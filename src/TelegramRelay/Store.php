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
 * ## Surviving a restart
 *
 * This file is the only thing that remembers a server's bridges, so losing it
 * means every admin has to run `/telegram link` again. Four things protect it:
 *
 *  - **Writes are atomic.** Content goes to a temp file, is flushed to the
 *    disk, and is then renamed over the target, so a crash mid-write cannot
 *    leave a half-written file where the configuration used to be.
 *  - **The previous good copy is kept** beside it as `.bak` before every
 *    write.
 *  - **A damaged file is never silently replaced.** If the JSON does not
 *    parse, the backup is tried; if that fails too, the file is preserved
 *    under a `.corrupt-<timestamp>` name rather than being overwritten by the
 *    next `/telegram link`. Recovering it by hand is then possible, which it
 *    would not be if the bridge had simply started empty and saved over it.
 *  - **Entries of the wrong shape are dropped, not loaded.** A hand-edited
 *    file cannot take the bridge down with a `TypeError` three layers away.
 *
 * Anything noticed while loading is recorded in {@see warnings()}, which the
 * bot logs — and tells its owner about — at startup.
 *
 * Every mutator hands back a fresh {@see Links}, which is what callers act on;
 * the store owns persistence, {@see Links} owns routing.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Store
{
    /** Where the last known-good copy is kept. */
    public const BACKUP_SUFFIX = '.bak';

    /** @var array{links?: array<string, array<string, string>>, titles?: array<string, string>} */
    private array $data;

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private readonly string $path)
    {
        $this->data = $this->load();

        foreach (glob($path . '.*.tmp') ?: [] as $stale) {
            @unlink($stale);
        }
    }

    /**
     * Anything that went wrong while reading the file, in the order it was
     * found. Empty on a normal start.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** Where this store keeps its state, for logging and for the startup check. */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Reads the configuration, falling back to the backup and refusing to
     * discard a file it cannot understand.
     *
     * @return array{links?: array<string, array<string, string>>, titles?: array<string, string>}
     */
    private function load(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $data = self::decode($this->path);

        if ($data !== null) {
            return $this->sanitize($data);
        }

        $backup = self::decode($this->path . self::BACKUP_SUFFIX);

        if ($backup !== null) {
            $this->warnings[] = sprintf(
                '%s could not be read; recovered the previous copy from %s.',
                basename($this->path),
                basename($this->path) . self::BACKUP_SUFFIX,
            );

            return $this->sanitize($backup);
        }

        // Nothing usable. Move the unreadable file out of the way rather than
        // starting empty and letting the next save overwrite it for good.
        $kept = $this->path . '.corrupt-' . date('Ymd-His');

        $this->warnings[] = @rename($this->path, $kept)
            ? sprintf('%s could not be read and no backup was usable; it has been kept as %s and the bridge started with no configuration.', basename($this->path), basename($kept))
            : sprintf('%s could not be read and could not be moved aside; refusing to overwrite it, so nothing will be saved.', basename($this->path));

        return [];
    }

    /**
     * Drops anything that is not a guild of channel-to-chat strings.
     *
     * @param  array<string, mixed> $data
     * @return array{links?: array<string, array<string, string>>, titles?: array<string, string>}
     */
    private function sanitize(array $data): array
    {
        $clean = [];

        foreach ((array) ($data['links'] ?? []) as $guildId => $channels) {
            if (! is_array($channels)) {
                $this->warnings[] = sprintf('Ignored the entry for guild %s: it is not a list of channels.', (string) $guildId);

                continue;
            }

            foreach ($channels as $channelId => $chatId) {
                if (! is_scalar($chatId) || (string) $chatId === '') {
                    $this->warnings[] = sprintf('Ignored the bridge for channel %s in guild %s: its chat id is not usable.', (string) $channelId, (string) $guildId);

                    continue;
                }

                $clean['links'][(string) $guildId][(string) $channelId] = (string) $chatId;
            }
        }

        foreach ((array) ($data['titles'] ?? []) as $chatId => $title) {
            if (is_scalar($title)) {
                $clean['titles'][(string) $chatId] = (string) $title;
            }
        }

        return $clean;
    }

    /**
     * Reads and decodes one file, or `null` when it is missing, unreadable, or
     * not a JSON object.
     *
     * @return array<string, mixed>|null
     */
    private static function decode(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        // An empty file is a legitimate "nothing configured yet" — a store
        // that has been created but never written to.
        if (trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
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
     * Encode, write a pid-suffixed sibling temp file, flush it to the disk,
     * back up what is there now, then rename the temp file over the target.
     *
     * Bails without touching the live file if the data cannot be encoded or
     * the temp write fails, so a bad value never truncates state. The `fsync`
     * matters on a machine that loses power: without it the rename can land
     * while the content is still in the page cache, leaving a zero-length file
     * where the configuration was.
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

        if (! self::writeDurably($tmp, $json)) {
            @unlink($tmp);

            return;
        }

        if (! @rename($tmp, $this->path)) {
            @unlink($tmp);

            return;
        }

        // The backup is written *after* the save, not by copying the file
        // about to be replaced: a backup that is one write behind would
        // recover a configuration missing whatever was just added, which is
        // exactly the change somebody would notice.
        $backupTmp = $this->path . '.' . getmypid() . '.bak.tmp';

        if (self::writeDurably($backupTmp, $json)) {
            @rename($backupTmp, $this->path . self::BACKUP_SUFFIX);
        } else {
            @unlink($backupTmp);
        }
    }

    /** Writes a file and waits for the disk to acknowledge it. */
    private static function writeDurably(string $path, string $contents): bool
    {
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            return false;
        }

        $written = @fwrite($handle, $contents);
        $flushed = $written === strlen($contents) && @fflush($handle);

        // fsync() is PHP 8.1+ and can fail on exotic filesystems; a failure
        // there is not a reason to lose the write.
        if ($flushed && function_exists('fsync')) {
            @fsync($handle);
        }

        @fclose($handle);

        return $flushed;
    }
}
