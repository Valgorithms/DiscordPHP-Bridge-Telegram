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
 * Turns a message from one network into something safe to say on the other.
 *
 * Pure and side-effect free, which is the point: everything that decides what
 * text leaves this process lives here, where it can be tested directly rather
 * than inferred from a live bridge.
 *
 * Unlike an IRC bridge, neither side here is line-oriented — Telegram and
 * Discord both carry a multi-line message as one message — so newlines are
 * preserved rather than flattened into spaces. What *is* dangerous is
 * Telegram's `parse_mode`: the bridge sends `HTML`, so every piece of relayed
 * text is escaped by {@see escapeHtml()} before it goes near a tag.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class MessageText
{
    /** Telegram rejects a message body past 4096 UTF-16 code units. */
    public const TELEGRAM_LIMIT = 4096;

    /** Telegram caps a photo/document caption far lower than a message. */
    public const TELEGRAM_CAPTION_LIMIT = 1024;

    /** Discord rejects a message body past 2000. */
    public const DISCORD_LIMIT = 2000;

    /**
     * Escapes text for Telegram's `HTML` parse mode.
     *
     * This is the important one. The bridge sends `parse_mode: HTML` so it can
     * put the Discord author's name in bold, which means relayed text is
     * parsed as markup: an unescaped `<b>` from Discord would style the
     * message, and an unbalanced `<` would make Telegram reject the whole send
     * with "can't parse entities" — a chat that silently stops relaying.
     *
     * Telegram's HTML mode only requires these three, and escaping more (`"`,
     * `'`) would show up as literal entities in the chat.
     *
     * @link https://core.telegram.org/bots/api#html-style
     */
    public static function escapeHtml(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    /**
     * Strips the control characters neither network should ever carry, while
     * keeping the ones that carry meaning in a chat message.
     *
     * Newline and tab survive; everything else in C0, plus DEL, goes. A bare
     * carriage return is normalised rather than dropped so a message pasted
     * from Windows does not arrive with its lines run together.
     */
    public static function sanitize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    }

    /**
     * Formats a Discord message for Telegram, as HTML, or `null` when there is
     * nothing worth relaying (an embed-only or empty message).
     *
     * The author is bolded rather than sent as a separate message, because
     * Telegram has no per-message identity to borrow the way a Discord webhook
     * does — one bot account says everything the bridge relays, so the name has
     * to be in the text.
     *
     * @param array<string, string> $userNames    Discord user id => display name, for resolving `<@id>`.
     * @param array<string, string> $channelNames Discord channel id => name, for `<#id>`.
     * @param array<string, string> $roleNames    Discord role id => name, for `<@&id>`.
     * @param list<string>          $attachments  Attachment URLs, appended as links.
     */
    public static function forTelegram(
        string $content,
        string $author,
        array $userNames = [],
        array $channelNames = [],
        array $roleNames = [],
        array $attachments = [],
        int $limit = self::TELEGRAM_LIMIT,
    ): ?string {
        $body = self::sanitize(self::resolveMentions($content, $userNames, $channelNames, $roleNames));
        $body = trim($body);

        if ($body === '' && $attachments === []) {
            return null;
        }

        $prefix = '<b>' . self::escapeHtml(trim(self::sanitize($author))) . '</b>: ';
        $tail = '';

        foreach ($attachments as $url) {
            // Already a URL, so it needs escaping for the attribute *and* the
            // body — a filename with an "&" in it would otherwise break the tag.
            $safe = self::escapeHtml($url);
            $tail .= "\n" . '<a href="' . $safe . '">' . self::escapeHtml(self::filename($url)) . '</a>';
        }

        $room = max(1, $limit - self::length($prefix) - self::length(strip_tags($tail)));

        return $prefix . self::escapeHtml(self::truncate($body, $room)) . $tail;
    }

    /**
     * Formats a Telegram message for Discord.
     *
     * Content is passed through as written. Nothing is escaped, because the
     * delivery side sends `allowed_mentions: none` — that neuters `@everyone`
     * at the API rather than by mangling the text, so someone who types an `@`
     * still reads as having typed one. Telegram markup is left alone for the
     * same reason: mangling asterisks to stop Discord bolding them would be a
     * worse result than the occasional stray bold.
     */
    public static function forDiscord(string $content, int $limit = self::DISCORD_LIMIT): ?string
    {
        $body = trim(self::sanitize($content));

        return $body === '' ? null : self::truncate($body, $limit);
    }

    /**
     * Rewrites Discord's `<@id>` / `<#id>` / `<@&id>` / `<a:name:id>` markup
     * into something legible in a plain-text chat. Unknown ids degrade to a
     * readable placeholder rather than leaking a raw snowflake.
     *
     * @param array<string, string> $userNames
     * @param array<string, string> $channelNames
     * @param array<string, string> $roleNames
     */
    public static function resolveMentions(
        string $content,
        array $userNames = [],
        array $channelNames = [],
        array $roleNames = [],
    ): string {
        // Custom emoji <:name:id> / <a:name:id> → :name:
        $content = preg_replace('/<a?:([A-Za-z0-9_]+):\d+>/', ':$1:', $content) ?? $content;

        $content = preg_replace_callback(
            '/<@!?(\d+)>/',
            static fn (array $m): string => '@' . ($userNames[$m[1]] ?? 'someone'),
            $content,
        ) ?? $content;

        $content = preg_replace_callback(
            '/<@&(\d+)>/',
            static fn (array $m): string => '@' . ($roleNames[$m[1]] ?? 'role'),
            $content,
        ) ?? $content;

        return preg_replace_callback(
            '/<#(\d+)>/',
            static fn (array $m): string => '#' . ($channelNames[$m[1]] ?? 'channel'),
            $content,
        ) ?? $content;
    }

    /**
     * Normalises however someone referred to a Telegram chat into the form the
     * Bot API takes: a numeric id, or an `@username`.
     *
     * Accepts a raw id (`-1001234567890`), a username with or without the `@`,
     * and a public `t.me/name` link. Rejects private invite links
     * (`t.me/+hash`, `t.me/joinchat/hash`) with `null`: those are joins, not
     * chat ids, and there is no way to turn one into an id from outside. The
     * caller is expected to say so, rather than storing a link that can never
     * resolve.
     */
    public static function normalizeChatId(string $input): ?string
    {
        $value = trim($input);

        // A bare numeric id — supergroups are negative and longer than 32 bits,
        // so this stays a string.
        if (preg_match('/^-?\d{1,20}$/', $value) === 1) {
            return $value;
        }

        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        $value = preg_replace('#^(?:www\.)?(?:t(?:elegram)?\.me|telegram\.dog)/#i', '', $value) ?? $value;
        $value = explode('?', $value)[0];
        $value = ltrim(trim($value), '@');

        // Invite links, not chats. "+" and "joinchat/" both mean "join this",
        // and neither carries an id the Bot API would accept.
        if ($value === '' || $value[0] === '+' || stripos($value, 'joinchat/') === 0) {
            return null;
        }

        $value = explode('/', $value)[0];

        // Telegram usernames: 5-32 characters, letters, digits and underscore,
        // starting with a letter. Matched case-insensitively and handed back
        // as typed, since the API is not case-sensitive about them.
        return preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $value) === 1 ? '@' . $value : null;
    }

    /**
     * Escapes Discord markdown in text that came from Telegram.
     *
     * For structured output — a chat title in a panel, a name in a heading —
     * where an underscore should read as an underscore rather than silently
     * italicising the rest of the line. Relayed *messages* are deliberately
     * not escaped this way; see {@see forDiscord()}.
     */
    public static function escapeMarkdown(string $text): string
    {
        return preg_replace('/([*_~`|\\\\>#-])/', '\\\\$1', $text) ?? $text;
    }

    /** Truncates on a character boundary, marking that it happened. */
    public static function truncate(string $text, int $limit): string
    {
        if ($limit <= 0 || self::length($text) <= $limit) {
            return $text;
        }

        $ellipsis = '…';
        $keep = max(0, $limit - 1);

        return (function_exists('mb_substr') ? mb_substr($text, 0, $keep, 'UTF-8') : substr($text, 0, $keep)) . $ellipsis;
    }

    /** The last path segment of a URL, for labelling an attachment link. */
    public static function filename(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $name = rawurldecode(basename($path));

        return $name === '' ? 'attachment' : self::truncate($name, 60);
    }

    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }
}
