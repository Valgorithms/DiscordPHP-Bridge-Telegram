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

use Bridge\Bot;
use Bridge\Command\Access;
use Bridge\Command\ChatDispatcher;
use Bridge\Command\ChatInvocation;
use Bridge\Command\Context;
use React\Promise\PromiseInterface;
use Telegram\Parts\Message;

use function React\Promise\resolve;

/**
 * Runs the whole catalogue from a Telegram chat.
 *
 * The *whole* catalogue, as in every chat: `!twitch title` typed in a Telegram
 * group sets the title of the Twitch channel that group is bridged to through
 * Discord. Everything but the Telegram-specific parts is the core's
 * {@see ChatDispatcher}, so access, cooldowns and cross-network targets behave
 * exactly as they do everywhere else.
 *
 * What only this class knows is where Telegram keeps rank. A message says
 * nothing about its sender's standing, so it is asked for — once per person
 * per chat per minute, and only for a line that is actually a command, since
 * the lookup is an API call against the same budget as everything else.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class TelegramAdapter
{
    /** How long a rank is trusted before it is asked for again, in seconds. */
    public const RANK_TTL = 60.0;

    /** How many ranks to remember. */
    private const RANK_CACHE = 500;

    private readonly ChatDispatcher $dispatcher;

    /** @var array<string, array{0: Access, 1: float}> "chat:user" => rank, and when it was read */
    private array $ranks = [];

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @param (callable(): float)|null $clock For tests; defaults to the wall clock. */
    public function __construct(
        private readonly TelegramConnector $connector,
        Bot $bot,
        ?callable $clock = null,
    ) {
        $this->dispatcher = new ChatDispatcher($bot, $connector);
        $this->clock = \Closure::fromCallable($clock ?? static fn (): float => microtime(true));
    }

    /** Whether a line is addressed to the bot, as the relay needs to know. */
    public function isCommand(string $text): bool
    {
        return $this->dispatcher->isCommand($this->unaddressed($text));
    }

    /**
     * Runs a message if it is a command, reporting whether it was.
     *
     * The answer is threaded onto the command it answers, through the
     * connector's paced queue.
     */
    public function handle(Message $message): bool
    {
        $text = $this->unaddressed((string) ($message->text ?? $message->caption ?? ''));
        $chatId = (string) ($message->chat?->id ?? '');

        if ($chatId === '' || ! $this->dispatcher->isCommand($text)) {
            return false;
        }

        $this->rank($message)->then(function (Access $access) use ($message, $text, $chatId): void {
            $this->dispatcher->dispatch(
                $text,
                new ChatInvocation(
                    room: $chatId,
                    roomId: $chatId,
                    invokerName: $this->nameOf($message),
                    invokerId: (string) ($message->from?->id ?? $message->sender_chat?->id ?? ''),
                    access: $access,
                    message: $message,
                ),
                fn (string $reply) => $this->connector->send($chatId, $reply, [
                    'reply_to' => (string) ($message->message_id ?? ''),
                ]),
            );
        });

        return true;
    }

    /**
     * Where the sender stands, on the one ladder.
     *
     * - The configured owner is the operator, wherever they type.
     * - A chat's creator is its administrator, and its administrators are
     *   moderators — the same shape as a Twitch broadcaster and their mods.
     * - Somebody posting *as the chat itself* — an anonymous group admin, or a
     *   channel post — is an administrator of it by definition, so a moderator.
     * - Anyone else, and anyone in a private chat with the bot, is everyone.
     *
     * A lookup that fails is everyone: refusing a command is recoverable,
     * granting one is not.
     *
     * @return PromiseInterface<Access>
     */
    public function rank(Message $message): PromiseInterface
    {
        $chatId = (string) ($message->chat?->id ?? '');
        $userId = (string) ($message->from?->id ?? '');
        $ownerId = $this->connector->getConfig()->ownerId;

        if ($ownerId !== null && $userId === $ownerId) {
            return resolve(Access::Operator);
        }

        $senderChat = (string) ($message->sender_chat?->id ?? '');

        if ($senderChat !== '' && $senderChat === $chatId) {
            return resolve(Access::Moderator);
        }

        if ($userId === '' || $chatId === '' || ($message->chat?->type ?? '') === 'private') {
            return resolve(Access::Everyone);
        }

        $key = $chatId . ':' . $userId;
        $now = ($this->clock)();
        $cached = $this->ranks[$key] ?? null;

        if ($cached !== null && $now - $cached[1] < self::RANK_TTL) {
            return resolve($cached[0]);
        }

        return $this->connector->getTelegram()->getChatMember($chatId, (int) $userId)->then(
            function ($member) use ($key, $now): Access {
                $access = self::accessFor((string) ($member->status ?? ''));

                if (count($this->ranks) >= self::RANK_CACHE) {
                    unset($this->ranks[array_key_first($this->ranks)]);
                }

                $this->ranks[$key] = [$access, $now];

                return $access;
            },
            static fn (): Access => Access::Everyone,
        );
    }

    /** A `ChatMember` status as a rung. */
    public static function accessFor(string $status): Access
    {
        return Context::ladder(false, $status === 'creator', $status === 'administrator');
    }

    /**
     * Drops the `@botname` Telegram appends to a command in a group.
     *
     * Telegram's own command menu sends `/twitch@SomeBot title` when several
     * bots share a group; with a `/` prefix configured, that has to reach the
     * dispatcher as `/twitch title`. A command addressed to a *different* bot
     * is left alone, and so is not a command at all.
     */
    public function unaddressed(string $text): string
    {
        $username = (string) ($this->connector->getTelegram()->getBotUser()?->username ?? '');

        if ($username === '') {
            return $text;
        }

        return preg_replace('/^(\S+)@' . preg_quote($username, '/') . '(?=\s|$)/i', '$1', $text) ?? $text;
    }

    private function nameOf(Message $message): string
    {
        $from = $message->from;

        if ($from !== null) {
            $name = trim((string) $from->getFullName());

            return $name !== '' ? $name : '@' . (string) ($from->username ?? 'someone');
        }

        return (string) ($message->sender_chat?->title ?? $message->chat?->title ?? 'someone');
    }
}
