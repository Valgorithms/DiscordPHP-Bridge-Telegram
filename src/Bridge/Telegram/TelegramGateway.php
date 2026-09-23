<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-Bridge-Telegram project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@valgorithms.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Bridge\Telegram;

use Bridge\Support\RateLimiter;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Telegram\Events\Event;
use Telegram\Parts\Message;
use Telegram\Telegram;

/**
 * Owns the Telegram side of the bridge: the update subscription, and a paced
 * outbound queue.
 *
 * There is nothing here corresponding to an IRC JOIN — Telegram pushes updates
 * for every chat the bot is a member of, whether or not the bridge is
 * interested — so membership is Telegram's business and filtering is
 * {@see TelegramConnector}'s. What this class owns is *pacing*:
 * every outbound message passes a per-chat bucket and a global one, because
 * Telegram's limits are per-chat and per-bot at the same time and a burst that
 * respects only one of them still earns a `429`.
 *
 * TelegramPHP honours a `429`'s `retry_after` itself, so this is about not
 * earning one: a bot that keeps hitting the limit is throttled harder.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class TelegramGateway
{
    /**
     * Telegram's limit in a group: 20 messages a minute. Kept a little under.
     *
     * @link https://core.telegram.org/bots/faq#my-bot-is-hitting-limits-how-do-i-avoid-this
     */
    public const PER_CHAT = 18;

    public const PER_CHAT_SECONDS = 60.0;

    /** And across every chat: about 30 a second. */
    public const GLOBAL = 25;

    public const GLOBAL_SECONDS = 1.0;

    /**
     * How much may wait. A busy Discord channel can outrun a group's twenty a
     * minute indefinitely, and a backlog that is still playing out an hour
     * later is worse than a gap.
     */
    public const MAX_QUEUE = 200;

    /** @var list<array{chat: string, send: callable(): PromiseInterface, deferred: Deferred}> */
    private array $queue = [];

    /** @var array<string, RateLimiter> Per-chat budget. */
    private array $limiters = [];

    private readonly RateLimiter $global;

    /** @var (callable(): float)|null */
    private $clock;

    /** How many messages were dropped since the backlog last cleared. */
    private int $dropped = 0;

    private bool $draining = false;

    /** @var (callable(Message): void)|null */
    private $onMessage = null;

    /** @var (callable(Message): void)|null */
    private $onEdit = null;

    /** @param (callable(): float)|null $clock For tests; defaults to the wall clock. */
    public function __construct(
        private readonly Telegram $telegram,
        private readonly LoopInterface $loop,
        private readonly LoggerInterface $logger,
        ?callable $clock = null,
    ) {
        $this->clock = $clock;
        $this->global = new RateLimiter(self::GLOBAL, self::GLOBAL_SECONDS, $clock);
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
            $this->logger->warning('[telegram] ' . TelegramText::redact($e->getMessage()));
        });
    }

    /**
     * Queues an HTML message for a Telegram chat.
     *
     * Never sends around the budgets, even when they are full and the queue is
     * empty: the ordering guarantee is what keeps a busy channel's messages
     * arriving in the order they were typed.
     *
     * @param array{reply_to?: int|string|null, silent?: bool} $options
     *
     * @return PromiseInterface<Message> resolves when Telegram accepts it
     */
    public function send(int|string $chatId, string $html, array $options = []): PromiseInterface
    {
        $replyTo = (int) ($options['reply_to'] ?? 0);

        return $this->enqueue((string) $chatId, fn (): PromiseInterface => $this->telegram->sendMessage(
            (string) $chatId,
            $html,
            parse_mode: 'HTML',
            // Link previews off: a relayed message that happens to contain a
            // URL would otherwise arrive with an unsolicited card under it,
            // which is noise in a bridge and occasionally a leak.
            link_preview_options: ['is_disabled' => true],
            disable_notification: ($options['silent'] ?? false) === true ? true : null,
            // Threaded onto the command it answers, and sent anyway if that
            // was deleted in the meantime.
            reply_parameters: $replyTo > 0 ? ['message_id' => $replyTo, 'allow_sending_without_reply' => true] : null,
        ));
    }

    /**
     * Queues a photo, by URL — Telegram fetches it itself.
     *
     * Discord's CDN links are public for long enough for that, so handing
     * Telegram the URL avoids pulling several megabytes through this process
     * only to push them straight back out.
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

    /**
     * Rewrites a message the bot sent. Paced like a send, because Telegram
     * counts it like one.
     *
     * @param bool $caption Whether the message is a photo, whose text is its caption.
     *
     * @return PromiseInterface<mixed>
     */
    public function edit(int|string $chatId, int $messageId, string $html, bool $caption = false): PromiseInterface
    {
        return $this->enqueue((string) $chatId, fn (): PromiseInterface => $caption
            ? $this->telegram->editMessageCaption(
                chat_id: (string) $chatId,
                message_id: $messageId,
                caption: $html,
                parse_mode: 'HTML',
            )
            : $this->telegram->editMessageText(
                chat_id: (string) $chatId,
                message_id: $messageId,
                text: $html,
                parse_mode: 'HTML',
                link_preview_options: ['is_disabled' => true],
            ));
    }

    /** How many messages are waiting, for logging and health checks. */
    public function queued(): int
    {
        return count($this->queue);
    }

    /**
     * @param  callable(): PromiseInterface $send
     * @return PromiseInterface<mixed>
     */
    private function enqueue(string $chatId, callable $send): PromiseInterface
    {
        if (count($this->queue) >= self::MAX_QUEUE) {
            // The oldest goes: by the time it could be sent the conversation
            // it belonged to has moved on.
            $oldest = array_shift($this->queue);
            $oldest['deferred']->reject(new \RuntimeException('dropped: more was waiting than Telegram would take'));

            if ($this->dropped++ === 0) {
                $this->logger->warning(sprintf(
                    '[telegram] more is waiting than Telegram will take (%d messages); dropping the oldest until it clears',
                    self::MAX_QUEUE,
                ));
            }
        }

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
        $held = [];
        $soonest = null;

        while ($this->queue !== []) {
            if ($this->global->retryAfter() > 0.0) {
                $soonest = min($soonest ?? PHP_FLOAT_MAX, $this->global->retryAfter());

                break;
            }

            $item = array_shift($this->queue);
            $limiter = $this->limiters[$item['chat']] ??= new RateLimiter(self::PER_CHAT, self::PER_CHAT_SECONDS, $this->clock);

            if ($limiter->retryAfter() > 0.0) {
                $held[] = $item;
                $soonest = min($soonest ?? PHP_FLOAT_MAX, $limiter->retryAfter());

                continue;
            }

            $limiter->tryConsume();
            $this->global->tryConsume();
            $this->dispatch($item);
        }

        $this->queue = [...$held, ...$this->queue];

        if ($this->queue === []) {
            if ($this->dropped > 0) {
                $this->logger->info(sprintf('[telegram] backlog cleared; %d message(s) were dropped', $this->dropped));
                $this->dropped = 0;
            }

            return;
        }

        if ($this->draining) {
            return;
        }

        $this->draining = true;
        $this->loop->addTimer(max(0.05, $soonest ?? 0.05), function (): void {
            $this->draining = false;
            $this->drain();
        });
    }

    /**
     * Sends one item and settles its promise, however the send fails.
     *
     * A send that throws before it has a promise — a bad argument, say — must
     * still settle, or the caller waits forever.
     *
     * @param array{chat: string, send: callable(): PromiseInterface, deferred: Deferred} $item
     */
    private function dispatch(array $item): void
    {
        $failed = function (\Throwable $e) use ($item): void {
            $safe = TelegramText::redacted($e);

            $this->logger->warning('[telegram] send to ' . $item['chat'] . ' failed: ' . $safe->getMessage());
            $item['deferred']->reject($safe);
        };

        try {
            $item['send']()->then(
                static fn ($result) => $item['deferred']->resolve($result),
                $failed,
            );
        } catch (\Throwable $e) {
            $failed($e);
        }
    }
}
