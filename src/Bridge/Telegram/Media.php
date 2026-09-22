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

/**
 * Works out what a Telegram message is carrying besides text, and whether the
 * bridge can mirror it into Discord.
 *
 * Operates on the plain array a {@see \Telegram\Parts\Message} serialises to
 * rather than on the part itself, so every decision here — which of the nine
 * media fields won, which photo size to take, what the thing should be called,
 * whether it is small enough to re-upload — is testable without a client, a
 * token, or a network.
 *
 * ## Why files are re-uploaded rather than linked
 *
 * A Telegram file has a public download URL, but it is
 * `https://api.telegram.org/file/bot<TOKEN>/<path>` — it contains the bot
 * token. Posting one into Discord would hand the bot's full credentials to
 * everyone who can read the channel, which is why the bridge downloads the
 * bytes and attaches them instead, and why that URL is never logged.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Media
{
    /**
     * The largest file the bridge will re-upload to Discord.
     *
     * Discord's own limit is 10 MiB for a server without boosts, and a
     * rejected upload costs a wasted download of the whole file first; 8 MiB
     * keeps a margin for the multipart overhead and for the smaller effective
     * limits some endpoints apply.
     */
    public const DISCORD_UPLOAD_LIMIT = 8 * 1024 * 1024;

    /**
     * The largest file `getFile` will serve from the cloud Bot API at all.
     *
     * A local Bot API server has no such limit, but the bridge cannot tell
     * from a message which one it is talking to, so it declines early rather
     * than issuing a request it expects to fail.
     *
     * @link https://core.telegram.org/bots/api#getfile
     */
    public const TELEGRAM_DOWNLOAD_LIMIT = 20 * 1024 * 1024;

    /**
     * What this message is carrying, or `null` when it is text only.
     *
     * The order matters: Telegram sets exactly one media field per message,
     * but `document` is also set alongside `animation` for a GIF, so the more
     * specific field has to win.
     *
     * @param  array<string, mixed> $message A serialised Telegram message.
     * @return array{kind: string, label: string, file_id: ?string, size: ?int, filename: ?string, mirrorable: bool}|null
     */
    public static function describe(array $message): ?array
    {
        if (isset($message['photo']) && is_array($message['photo']) && $message['photo'] !== []) {
            $size = self::largestPhoto($message['photo']);

            return self::entry('photo', '🖼️ photo', $size['file_id'] ?? null, $size['file_size'] ?? null, 'photo.jpg');
        }

        if (isset($message['sticker']) && is_array($message['sticker'])) {
            $sticker = $message['sticker'];
            $emoji = (string) ($sticker['emoji'] ?? '');
            $animated = ($sticker['is_animated'] ?? false) || ($sticker['is_video'] ?? false);

            return self::entry(
                'sticker',
                trim('🏷️ sticker ' . $emoji),
                $sticker['file_id'] ?? null,
                $sticker['file_size'] ?? null,
                $animated ? 'sticker.webm' : 'sticker.webp',
            );
        }

        foreach (
            [
                'animation' => ['🎞️ GIF', 'animation.mp4'],
                'video' => ['🎬 video', 'video.mp4'],
                'video_note' => ['🎥 video note', 'video-note.mp4'],
                'voice' => ['🎙️ voice message', 'voice.ogg'],
                'audio' => ['🎵 audio', 'audio.mp3'],
                'document' => ['📎 file', 'file.bin'],
            ] as $field => [$label, $fallbackName]
        ) {
            if (! isset($message[$field]) || ! is_array($message[$field])) {
                continue;
            }

            $media = $message[$field];
            $name = (string) ($media['file_name'] ?? '');

            return self::entry(
                $field,
                $name !== '' ? $label . ' ' . $name : $label,
                $media['file_id'] ?? null,
                $media['file_size'] ?? null,
                $name !== '' ? self::safeFilename($name) : $fallbackName,
            );
        }

        // Everything below has no file to fetch — it is relayed as a line of
        // text, so `file_id` stays null and `mirrorable` is false.
        if (isset($message['location']) && is_array($message['location'])) {
            $lat = (float) ($message['location']['latitude'] ?? 0);
            $lon = (float) ($message['location']['longitude'] ?? 0);

            return self::entry('location', sprintf(
                '📍 location — <https://www.openstreetmap.org/?mlat=%1$.5F&mlon=%2$.5F#map=16/%1$.5F/%2$.5F>',
                $lat,
                $lon,
            ), null, null, null);
        }

        if (isset($message['venue']) && is_array($message['venue'])) {
            return self::entry('venue', '📍 ' . trim(
                (string) ($message['venue']['title'] ?? 'venue') . ' — ' . (string) ($message['venue']['address'] ?? ''),
                ' —',
            ), null, null, null);
        }

        if (isset($message['contact']) && is_array($message['contact'])) {
            // The phone number is deliberately not relayed: the sender shared
            // it with a Telegram group, not with a Discord server.
            $name = trim(((string) ($message['contact']['first_name'] ?? '')) . ' ' . ((string) ($message['contact']['last_name'] ?? '')));

            return self::entry('contact', '👤 shared a contact' . ($name === '' ? '' : ' (' . $name . ')'), null, null, null);
        }

        if (isset($message['poll']) && is_array($message['poll'])) {
            return self::entry('poll', '📊 poll: ' . (string) ($message['poll']['question'] ?? ''), null, null, null);
        }

        if (isset($message['dice']) && is_array($message['dice'])) {
            return self::entry('dice', sprintf(
                '🎲 rolled %s → %d',
                (string) ($message['dice']['emoji'] ?? '🎲'),
                (int) ($message['dice']['value'] ?? 0),
            ), null, null, null);
        }

        return self::service($message);
    }

    /**
     * Telegram's service messages — somebody joined, the group was renamed, a
     * message was pinned.
     *
     * These arrive as an ordinary message with no text at all, so without this
     * the bridge would drop them silently and a Discord reader would never
     * learn that the group they are reading has changed under them.
     *
     * @param  array<string, mixed> $message
     * @return array{kind: string, label: string, file_id: ?string, size: ?int, filename: ?string, mirrorable: bool}|null
     */
    private static function service(array $message): ?array
    {
        if (isset($message['new_chat_members']) && is_array($message['new_chat_members'])) {
            $names = array_map(self::personName(...), array_filter($message['new_chat_members'], 'is_array'));

            return self::entry('new_chat_members', '👋 ' . implode(', ', $names) . ' joined the chat', null, null, null);
        }

        if (isset($message['left_chat_member']) && is_array($message['left_chat_member'])) {
            return self::entry('left_chat_member', '👋 ' . self::personName($message['left_chat_member']) . ' left the chat', null, null, null);
        }

        if (isset($message['new_chat_title'])) {
            return self::entry('new_chat_title', '✏️ The chat is now called "' . (string) $message['new_chat_title'] . '"', null, null, null);
        }

        if (isset($message['new_chat_photo'])) {
            return self::entry('new_chat_photo', '🖼️ The chat photo changed', null, null, null);
        }

        if (isset($message['pinned_message']) && is_array($message['pinned_message'])) {
            $pinned = trim((string) ($message['pinned_message']['text'] ?? $message['pinned_message']['caption'] ?? ''));

            return self::entry('pinned_message', '📌 Pinned' . ($pinned === '' ? ' a message' : ': ' . $pinned), null, null, null);
        }

        return null;
    }

    /**
     * How a Telegram user is named in a service line.
     *
     * @param array<string, mixed> $user
     */
    private static function personName(array $user): string
    {
        $name = trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')));

        if ($name !== '') {
            return $name;
        }

        $username = (string) ($user['username'] ?? '');

        return $username !== '' ? '@' . $username : 'someone';
    }

    /**
     * The caption that came with a media message, if any.
     *
     * @param array<string, mixed> $message
     */
    public static function caption(array $message): ?string
    {
        $caption = (string) ($message['caption'] ?? '');

        return trim($caption) === '' ? null : $caption;
    }

    /**
     * The largest of the photo sizes Telegram offers.
     *
     * Telegram sends a message's photo as several resolutions of the same
     * image; the last is normally the biggest, but that is convention rather
     * than a guarantee, so this compares them.
     *
     * @param  array<int, array<string, mixed>> $sizes
     * @return array<string, mixed>
     */
    public static function largestPhoto(array $sizes): array
    {
        $best = [];
        $bestArea = -1;

        foreach ($sizes as $size) {
            if (! is_array($size)) {
                continue;
            }

            $area = (int) ($size['width'] ?? 0) * (int) ($size['height'] ?? 0);
            if ($area > $bestArea) {
                $best = $size;
                $bestArea = $area;
            }
        }

        return $best;
    }

    /**
     * Strips a Telegram-supplied filename down to something safe to hand to
     * Discord as an attachment name — no directories, no control characters,
     * and never empty.
     */
    public static function safeFilename(string $name): string
    {
        $name = str_replace(['\\', '/'], '_', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name, " \t.");

        if ($name === '') {
            return 'file.bin';
        }

        return function_exists('mb_substr') ? mb_substr($name, 0, 80, 'UTF-8') : substr($name, 0, 80);
    }

    /**
     * @return array{kind: string, label: string, file_id: ?string, size: ?int, filename: ?string, mirrorable: bool}
     */
    private static function entry(string $kind, string $label, mixed $fileId, mixed $size, ?string $filename): array
    {
        $fileId = is_string($fileId) && $fileId !== '' ? $fileId : null;
        $size = is_int($size) || (is_string($size) && ctype_digit($size)) ? (int) $size : null;

        return [
            'kind' => $kind,
            'label' => trim($label),
            'file_id' => $fileId,
            'size' => $size,
            'filename' => $filename,
            // An unknown size is treated as mirrorable and allowed to fail on
            // the way back: Telegram omits `file_size` often enough that
            // refusing without one would drop ordinary photos.
            'mirrorable' => $fileId !== null
                && ($size === null || ($size <= self::DISCORD_UPLOAD_LIMIT && $size <= self::TELEGRAM_DOWNLOAD_LIMIT)),
        ];
    }
}
