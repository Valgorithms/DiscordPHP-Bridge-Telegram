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

namespace TelegramRelay\Helpers;

/**
 * Remembers which message on one network became which message on the other,
 * so an edit can follow it across.
 *
 * Bounded and in memory on purpose. An edit almost always follows within
 * minutes of the message, so the useful window is short; persisting it would
 * mean a growing file of ids that stop existing, and keeping it unbounded
 * would mean a bridge that slowly eats the host's memory for a feature nobody
 * uses on a week-old message. When an entry has been evicted — or the process
 * restarted — the bridge falls back to relaying the edit as a new message,
 * which is worse than editing in place but better than dropping it.
 *
 * @template T
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class MessageMap
{
    /** @var array<string, T> Insertion-ordered; the oldest key is evicted first. */
    private array $entries = [];

    /** @param int $capacity How many messages to remember. */
    public function __construct(private readonly int $capacity = 500)
    {
    }

    /**
     * Records what a message became.
     *
     * @param T $value
     */
    public function remember(string $key, mixed $value): void
    {
        // Re-inserting moves the key to the end, so a message that keeps being
        // edited keeps being worth remembering.
        unset($this->entries[$key]);

        $this->entries[$key] = $value;

        while (count($this->entries) > $this->capacity) {
            array_shift($this->entries);
        }
    }

    /**
     * What a message became, or `null` when it is no longer remembered.
     *
     * @return T|null
     */
    public function lookup(string $key): mixed
    {
        return $this->entries[$key] ?? null;
    }

    public function forget(string $key): void
    {
        unset($this->entries[$key]);
    }

    /** How many messages are currently remembered. */
    public function count(): int
    {
        return count($this->entries);
    }

    /** The key for one Telegram message. */
    public static function telegramKey(int|string $chatId, int|string $messageId): string
    {
        return $chatId . ':' . $messageId;
    }
}
