<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-TelegramRelay project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@valgorithms.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace TelegramRelay\Bridge;

use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Telegram\Events\Event;
use Telegram\Parts\Message;
use Telegram\Telegram;
use TelegramRelay\Helpers\RateLimiter;

/**
 * Owns the Telegram side of the bridge: the update subscription, and a paced
 * outbound queue.
 *
 * There is nothing here corresponding to an IRC JOIN — Telegram pushes updates
 * for every chat the bot is a member of, whether or not the bridge is
 * interested — so membership is Telegram's business and filtering is
 * {@see \TelegramRelay\Modules\Bridge}'s. What this class owns is *pacing*:
 * every outbound message passes a per-chat bucket and a global one, because
 * Telegram's limits are per-chat and per-bot at the same time and a burst that
 * respects only one of them still earns a `429`.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class TelegramGateway
{
    /** @var list<array{chat: string, send: callable(): PromiseInterface, deferred: Deferred}> */
    private array $queue = [];

    /** @var array<string, RateLimiter> Per-chat budget. */
    private array $limiters = [];

    private RateLimiter $global;

    private bool $draining = false;

    /** @var (callable(Message): void)|null */
    private $onMessage = null;

    /** @var (callable(Message): void)|null */
    private $onEdit = null;

    public function __construct(
        private readonly Telegram $telegram,
        private readonly LoopInterface $loop,
        private readonly LoggerInterface $logger,
    ) {
        $this->global = RateLimiter::global();
    }

    /** Registers the handler for inbound Telegram messages. */
    public function onMessage(callable $handler): void
    {
        $this->onMessage = $handler;
    }

    /** Registers the handler for edits to messages already seen. */
    public function onEdit(callable $handler): void
    {
        $this->onEdit = $handler;
    }

    /**
     * Starts listening.
     *
     * Channel posts are relayed alongside group messages: to a reader in
     * Discord the difference between someone talking in a group and a channel
     * publishing a post is not interesting, and a bridge that silently ignored
     * one of them would look broken.
     *
     * A bot does not receive its own sends as updates, but the identity check
     * is made anyway — two instances of this bridge pointed at one bot token
     * would otherwise relay each other's output back and forth forever.
     */
    public function listen(): void
    {
        $dispatch = function (Message $message, bool $edited): void {
            $selfId = $this->telegram->getBotUser()?->id;

            if ($selfId !== null && (int) ($message->from?->id ?? 0) === (int) $selfId) {
                return;
            }

            $handler = $edited ? $this->onEdit : $this->onMessage;

            if ($handler !== null) {
                $handler($message);
            }
        };

        $this->telegram->on(Event::MESSAGE, static fn (Message $m) => $dispatch($m, false));
        $this->telegram->on(Event::CHANNEL_POST, static fn (Message $m) => $dispatch($m, false));

        // Telegram sends an edit as its own update type carrying the whole
        // message, not a diff — and never sends anything at all for a
        // deletion, which is why the bridge can follow one and not the other.
        $this->telegram->on(Event::EDITED_MESSAGE, static fn (Message $m) => $dispatch($m, true));
        $this->telegram->on(Event::EDITED_CHANNEL_POST, static fn (Message $m) => $dispatch($m, true));

        $this->telegram->on(Event::ERROR, function (\Throwable $e): void {
            $this->logger->warning('[telegram] ' . $e->getMessage());
        });
    }

    /**
     * Queues an HTML message for a Telegram chat.
     *
     * Never sends inline, even when the buckets are full and the queue is
     * empty: the ordering guarantee is what keeps a busy channel's messages
     * arriving in the order they were typed.
     *
     * @param array<string, mixed> $options Any other `sendMessage` field, by
     *                                      its Bot API name.
     *
     * @return PromiseInterface<Message> resolves when Telegram accepts it
     */
    public function send(int|string $chatId, string $html, array $options = []): PromiseInterface
    {
        // Link previews off by default: a relayed message that happens to
        // contain a URL would otherwise arrive with an unsolicited card under
        // it, which is noise in a bridge and occasionally a leak.
        $fields = [
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            ...$options,
        ];

        return $this->enqueue((string) $chatId, fn (): PromiseInterface => $this->telegram->sendMessage(
            (string) $chatId,
            $html,
            ...$fields,
        ));
    }

    /**
     * Queues a photo, by URL — Telegram fetches it itself.
     *
     * Discord's CDN links are public and time-unlimited for attachments, so
     * handing Telegram the URL avoids pulling several megabytes through this
     * process only to push them straight back out.
     *
     * @return PromiseInterface<Message>
     */
    public function sendPhoto(int|string $chatId, string $url, ?string $caption = null): PromiseInterface
    {
        return $this->enqueue((string) $chatId, fn (): PromiseInterface => $this->telegram->sendPhoto(
            (string) $chatId,
            $url,
            caption: $caption,
            parse_mode: $caption === null ? null : 'HTML',
        ));
    }

    /** How many messages are waiting, for logging and health checks. */
    public function queued(): int
    {
        return count($this->queue);
    }

    /**
     * @param  callable(): PromiseInterface $send
     * @return PromiseInterface<Message>
     */
    private function enqueue(string $chatId, callable $send): PromiseInterface
    {
        $deferred = new Deferred();

        $this->queue[] = ['chat' => $chatId, 'send' => $send, 'deferred' => $deferred];
        $this->drain();

        return $deferred->promise();
    }

    /**
     * Sends whatever the budgets allow, then re-arms a timer for the rest.
     *
     * A chat that is out of tokens is skipped rather than blocking the queue
     * behind it, so one busy bridge cannot stall every other one. The global
     * bucket is the exception: when *that* is empty nothing can go anywhere,
     * so the loop stops immediately and keeps the remaining order intact.
     */
    private function drain(): void
    {
        $deferredItems = [];
        $soonest = null;

        while ($this->queue !== []) {
            if ($this->global->retryAfter() > 0.0) {
                $soonest = $soonest === null
                    ? $this->global->retryAfter()
                    : min($soonest, $this->global->retryAfter());

                break;
            }

            $item = array_shift($this->queue);
            $limiter = $this->limiters[$item['chat']] ??= new RateLimiter();

            if ($limiter->retryAfter() > 0.0) {
                $deferredItems[] = $item;
                $soonest = $soonest === null ? $limiter->retryAfter() : min($soonest, $limiter->retryAfter());

                continue;
            }

            $limiter->tryConsume();
            $this->global->tryConsume();

            $item['send']()->then(
                static fn ($result) => $item['deferred']->resolve($result),
                function (\Throwable $e) use ($item): void {
                    $this->logger->warning('[telegram] send to ' . $item['chat'] . ' failed: ' . $e->getMessage());
                    $item['deferred']->reject($e);
                },
            );
        }

        $this->queue = [...$deferredItems, ...$this->queue];

        if ($this->queue === [] || $this->draining) {
            return;
        }

        $this->draining = true;
        $this->loop->addTimer(max(0.05, $soonest ?? 0.05), function (): void {
            $this->draining = false;
            $this->drain();
        });
    }
}
