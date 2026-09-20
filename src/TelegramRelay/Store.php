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
 * JSON-file backed persistence for the bridge configuration — which Discord
 * channel is linked to which Telegram chat, per guild — plus the last-known
 * title of each chat, so `/telegram list` can name a group without a round
 * trip to Telegram for every row.
 *
 * Writes are atomic (temp file + rename), so a crash mid-write cannot leave a
 * truncated file where a server's configuration used to be.
 *
 * Every mutator hands back a fresh {@see Links}, which is what callers act on;
 * the store owns persistence, {@see Links} owns routing.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Store
{
    /** @var array{links?: array<string, array<string, string>>, titles?: array<string, string>} */
    private array $data;

    public function __construct(private readonly string $path)
    {
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $this->data = is_array($decoded) ? $decoded : [];

        foreach (glob($path . '.*.tmp') ?: [] as $stale) {
            @unlink($stale);
        }
    }

    /** The current routing table. */
    public function links(): Links
    {
        /** @var array<string, array<string, string>> $links */
        $links = $this->data['links'] ?? [];

        return new Links($links);
    }

    /**
     * Bridges a Discord channel to a Telegram chat.
     *
     * A Discord channel can only point at one Telegram chat, so setting it
     * again replaces the previous target rather than accumulating.
     */
    public function link(int|string $guildId, int|string $discordChannelId, int|string $telegramChatId, ?string $title = null): Links
    {
        $this->data['links'][(string) $guildId][(string) $discordChannelId] = (string) $telegramChatId;

        if ($title !== null && $title !== '') {
            $this->data['titles'][(string) $telegramChatId] = $title;
        }

        $this->save();

        return $this->links();
    }

    /** Removes one Discord channel's bridge. No-op when it was not bridged. */
    public function unlink(int|string $guildId, int|string $discordChannelId): Links
    {
        $guild = (string) $guildId;
        $channel = (string) $discordChannelId;

        if (isset($this->data['links'][$guild][$channel])) {
            unset($this->data['links'][$guild][$channel]);

            if (($this->data['links'][$guild] ?? []) === []) {
                unset($this->data['links'][$guild]);
            }

            $this->forgetUnusedTitles();
            $this->save();
        }

        return $this->links();
    }

    /** Drops every bridge a guild has configured. */
    public function forgetGuild(int|string $guildId): Links
    {
        if (isset($this->data['links'][(string) $guildId])) {
            unset($this->data['links'][(string) $guildId]);
            $this->forgetUnusedTitles();
            $this->save();
        }

        return $this->links();
    }

    /** The last title seen for a chat, for display. Never authoritative. */
    public function title(int|string $telegramChatId): ?string
    {
        return $this->data['titles'][(string) $telegramChatId] ?? null;
    }

    /**
     * Records a chat's current title, if it changed.
     *
     * Called from the relay as messages arrive, so a renamed group stops being
     * listed under the name it had when it was linked.
     */
    public function rememberTitle(int|string $telegramChatId, string $title): void
    {
        $chat = (string) $telegramChatId;

        if ($title === '' || ($this->data['titles'][$chat] ?? null) === $title) {
            return;
        }

        $this->data['titles'][$chat] = $title;
        $this->save();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /** Drops titles for chats nothing links to any more. */
    private function forgetUnusedTitles(): void
    {
        $live = $this->links()->chats();

        foreach (array_keys($this->data['titles'] ?? []) as $chat) {
            if (! in_array((string) $chat, $live, true)) {
                unset($this->data['titles'][$chat]);
            }
        }

        if (($this->data['titles'] ?? null) === []) {
            unset($this->data['titles']);
        }
    }

    /**
     * Encode, write a pid-suffixed sibling temp file, rename it over the
     * target. Bails without touching the live file if the data cannot be
     * encoded or the temp write fails, so a bad value never truncates state.
     */
    private function save(): void
    {
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        $dir = \dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }

        $tmp = $this->path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            return;
        }

        @rename($tmp, $this->path);
    }
}
