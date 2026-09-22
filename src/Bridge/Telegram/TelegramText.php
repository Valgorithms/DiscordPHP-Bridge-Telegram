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

use Bridge\Message\Outgoing;
use Bridge\Support\MessageText;

/**
 * The parts of turning a message into text that are Telegram's rules rather
 * than anybody's.
 *
 * Everything shared — resolving Discord's mention markup, truncating on a
 * character boundary, naming a file — lives in {@see MessageText} and is used
 * from here. What is left is Telegram's HTML mode, its limits, and the several
 * shapes a chat can be referred to by.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class TelegramText
{
    /** What Telegram accepts in one message. */
    public const LIMIT = 4096;

    /** And in a caption attached to a photo or file, which is far shorter. */
    public const CAPTION_LIMIT = 1024;

    /**
     * Escapes the three characters Telegram's HTML mode reserves.
     *
     * Only these three. Escaping more — `"`, `'` — shows up as literal entities
     * in the chat, because Telegram does not decode them.
     *
     * @link https://core.telegram.org/bots/api#html-style
     */
    public static function escapeHtml(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    /**
     * Renders a Discord message for Telegram, as HTML, or `null` when there is
     * nothing worth relaying.
     *
     * The author is bolded into the text rather than sent as a separate
     * message, because Telegram has no per-message identity to borrow the way a
     * Discord webhook does — one bot account says everything the bridge relays,
     * so the name has to be in the message or it is nowhere.
     */
    public static function compose(Outgoing $message, int $limit = self::LIMIT): ?string
    {
        $body = trim(MessageText::sanitize(MessageText::resolveMentions(
            $message->text,
            $message->userNames,
            $message->channelNames,
            $message->roleNames,
        )));

        if ($body === '' && $message->media === []) {
            return null;
        }

        $prefix = '<b>' . self::escapeHtml(trim(MessageText::sanitize($message->author))) . '</b>: ';
        $tail = '';

        foreach ($message->mediaUrls() as $url) {
            // Already a URL, so it needs escaping for the attribute *and* the
            // body — a filename with an "&" in it would otherwise break the tag.
            $safe = self::escapeHtml($url);
            $tail .= "\n" . '<a href="' . $safe . '">' . self::escapeHtml(MessageText::filename($url)) . '</a>';
        }

        $room = max(1, $limit - MessageText::length($prefix) - MessageText::length(strip_tags($tail)));

        return $prefix . self::escapeHtml(MessageText::truncate($body, $room)) . $tail;
    }

    /**
     * Normalises however somebody referred to a Telegram chat into the form the
     * Bot API takes: a numeric id, or an `@username`.
     *
     * Accepts a raw id (`-1001234567890`), a username with or without the `@`,
     * and a public `t.me/name` link. Rejects private invite links
     * (`t.me/+hash`, `t.me/joinchat/hash`) with `null`: those are joins, not
     * chat ids, and there is no way to turn one into an id from outside. The
     * caller is expected to say so, rather than storing a link that can never
     * resolve.
     *
     * What this returns is what gets stored, so it has to be stable:
     * normalising one way today and another tomorrow orphans every bridge made
     * before the change.
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
        // starting with a letter. Matched case-insensitively and handed back as
        // typed, since the API is not case-sensitive about them.
        return preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $value) === 1 ? '@' . $value : null;
    }
}
