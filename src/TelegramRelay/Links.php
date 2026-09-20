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

namespace TelegramRelay;

/**
 * The routing table: which Discord channel is bridged to which Telegram chat.
 *
 * Immutable and derived from whatever {@see Store} has persisted, so routing
 * can be reasoned about — and tested — without a gateway, a socket, or a
 * config file.
 *
 * The two directions are deliberately asymmetric. A Discord channel bridges to
 * exactly one Telegram chat, so `Discord → Telegram` is a lookup. But several
 * Discord channels — in unrelated guilds — may follow the *same* Telegram
 * group, so `Telegram → Discord` fans out to a list.
 *
 * Chat ids are held as strings throughout. Telegram's supergroup ids
 * (`-1001234567890`) are larger than a 32-bit int and a `@username` is not a
 * number at all, so comparing them as strings is the only form that is correct
 * for every chat.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Links
{
    /** @var array<string, string> Discord channel id => Telegram chat id. */
    private array $toTelegram = [];

    /** @var array<string, list<string>> Telegram chat id => Discord channel ids. */
    private array $toDiscord = [];

    /**
     * @param array<string, array<string, string>> $guilds guild id => [discord channel id => telegram chat id]
     */
    public function __construct(private readonly array $guilds = [])
    {
        foreach ($this->guilds as $channels) {
            foreach ($channels as $channelId => $chatId) {
                $channelId = (string) $channelId;
                $chatId = (string) $chatId;

                $this->toTelegram[$channelId] = $chatId;
                $this->toDiscord[$chatId][] = $channelId;
            }
        }
    }

    /** The Telegram chat a Discord channel relays to, or `null` when unbridged. */
    public function telegramFor(int|string $discordChannelId): ?string
    {
        return $this->toTelegram[(string) $discordChannelId] ?? null;
    }

    /**
     * Every Discord channel that should receive a message from a Telegram
     * chat — possibly across several guilds.
     *
     * @return list<string>
     */
    public function discordFor(int|string $telegramChatId): array
    {
        return $this->toDiscord[(string) $telegramChatId] ?? [];
    }

    /**
     * Every Telegram chat the bridge cares about, deduplicated: two guilds
     * following the same group are one chat here.
     *
     * @return list<string>
     */
    public function chats(): array
    {
        // PHP turns a numeric string key back into an int, and "-1001" is a
        // numeric string, so these have to be cast back before they leave —
        // otherwise a caller comparing them strictly against the string ids
        // used everywhere else silently matches nothing.
        $chats = array_map(strval(...), array_keys($this->toDiscord));
        sort($chats, SORT_STRING);

        return $chats;
    }

    /** Whether any Discord channel at all is bridged to this Telegram chat. */
    public function isBridged(int|string $telegramChatId): bool
    {
        return $this->discordFor($telegramChatId) !== [];
    }

    /**
     * One guild's links.
     *
     * @return array<string, string> discord channel id => telegram chat id
     */
    public function forGuild(int|string $guildId): array
    {
        return $this->guilds[(string) $guildId] ?? [];
    }

    public function isEmpty(): bool
    {
        return $this->toTelegram === [];
    }

    public function count(): int
    {
        return count($this->toTelegram);
    }
}
