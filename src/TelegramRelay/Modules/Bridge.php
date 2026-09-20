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

namespace TelegramRelay\Modules;

use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message as DiscordMessage;
use Discord\WebSockets\Event;
use Telegram\Parts\Message as TelegramMessage;
use TelegramRelay\Helpers\Media;
use TelegramRelay\Helpers\MessageMap;
use TelegramRelay\Helpers\MessageText;
use TelegramRelay\Relay;

/**
 * The relay proper: Discord messages out to Telegram, Telegram messages back
 * into Discord, and edits following them in both directions.
 *
 * ## Not relaying our own echo
 *
 * A bridge that repeats what it just said is an infinite loop that will get
 * the account limited on both networks, so each direction drops its own
 * output at the earliest possible point:
 *
 *  - **Discord → Telegram** ignores any message with a `webhook_id`, and any
 *    message whose author is a bot. Relayed Telegram chat arrives *through* a
 *    webhook, so it carries a `webhook_id` and is filtered by the first rule
 *    even if the webhook is not ours. Dropping other bots too is deliberate:
 *    two bridges in one channel would otherwise ping-pong forever.
 *  - **Telegram → Discord** ignores anything sent by our own bot account, in
 *    {@see \TelegramRelay\Bridge\TelegramGateway}.
 *
 * ## Media
 *
 * Telegram files are downloaded and re-uploaded to Discord rather than linked,
 * because a Telegram file URL contains the bot token — see {@see Media}.
 * Discord attachments go the other way as plain URLs, which are public and
 * which Telegram fetches for itself.
 *
 * ## Edits, and why there are no deletions
 *
 * Both networks push an edit as a whole new copy of the message, so the bridge
 * keeps a bounded {@see MessageMap} of what became what and rewrites the copy
 * in place. When the mapping has been evicted — or the process restarted — the
 * edit is relayed as a fresh message rather than dropped.
 *
 * Deletions are not relayed in either direction, because neither API offers
 * them: the Bot API sends a bot no update at all when a message is deleted,
 * and Discord's `MESSAGE_DELETE` could only be honoured one way, which would
 * be more confusing than not honouring it.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Bridge implements Module
{
    /** Discord filenames that should be sent to Telegram as a photo, not a link. */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** @var MessageMap<array{chat_id: string, message_id: int, text: string}> Discord message id => the Telegram message it became. */
    private MessageMap $toTelegram;

    /** @var MessageMap<list<array{channel_id: string, message_id: string}>> Telegram message key => the Discord messages it became. */
    private MessageMap $toDiscord;

    public function __construct(int $remember = 500)
    {
        $this->toTelegram = new MessageMap($remember);
        $this->toDiscord = new MessageMap($remember);
    }

    public function name(): string
    {
        return 'bridge';
    }

    public function boot(Relay $bot): void
    {
        $bot->on(Event::MESSAGE_CREATE, fn (DiscordMessage $message) => $this->fromDiscord($bot, $message));

        $bot->on(Event::MESSAGE_UPDATE, function (object $message) use ($bot): void {
            // MESSAGE_UPDATE hands over the raw gateway payload rather than a
            // Message part when the message was not cached — a partial update,
            // or an edit to something posted before the bot started. There is
            // nothing to follow across for one of those, and typing the
            // parameter as a Message would turn it into a TypeError.
            if ($message instanceof DiscordMessage) {
                $this->fromDiscord($bot, $message, edited: true);
            }
        });

        $bot->gateway()->onMessage(fn (TelegramMessage $message) => $this->fromTelegram($bot, $message));
        $bot->gateway()->onEdit(fn (TelegramMessage $message) => $this->fromTelegram($bot, $message, edited: true));
    }

    /** Discord → Telegram. */
    private function fromDiscord(Relay $bot, DiscordMessage $message, bool $edited = false): void
    {
        // Our own relayed output, or another bot's. See the class docblock.
        if ($message->webhook_id !== null) {
            return;
        }
        if ($message->author === null || ($message->author->bot ?? false)) {
            return;
        }

        $chatId = $bot->getStore()->links()->telegramFor((string) $message->channel_id);
        if ($chatId === null) {
            return;
        }

        [$photo, $links] = $this->splitAttachments($message);

        $text = MessageText::forTelegram(
            (string) ($message->content ?? ''),
            $this->discordAuthor($message),
            $this->userNames($message),
            $this->channelNames($message),
            $this->roleNames($message),
            $links,
            // A photo carries the text as its caption, and captions are capped
            // far lower than messages.
            $photo === null ? MessageText::TELEGRAM_LIMIT : MessageText::TELEGRAM_CAPTION_LIMIT,
        );

        if ($text === null) {
            return;
        }

        if ($edited && $this->editInTelegram($bot, $message, $text)) {
            return;
        }

        $promise = $photo === null
            ? $bot->gateway()->send($chatId, $text)
            : $bot->gateway()->sendPhoto($chatId, $photo, $text);

        $promise->then(function (TelegramMessage $sent) use ($message, $chatId, $text): void {
            $this->toTelegram->remember((string) $message->id, [
                'chat_id' => $chatId,
                'message_id' => (int) $sent->message_id,
                'text' => $text,
            ]);
        });

        // A photo Telegram refuses to fetch — wrong type, too large, a CDN
        // hiccup — should still reach the chat as text rather than vanishing.
        if ($photo !== null) {
            $promise->catch(function (\Throwable $e) use ($bot, $chatId, $text, $photo): void {
                $bot->logger->debug('[bridge] photo send failed, falling back to text: ' . $e->getMessage());
                $bot->gateway()->send(
                    $chatId,
                    $text . "\n" . '<a href="' . MessageText::escapeHtml($photo) . '">'
                        . MessageText::escapeHtml(MessageText::filename($photo)) . '</a>',
                );
            });
        }
    }

    /**
     * Rewrites the Telegram copy of an edited Discord message.
     *
     * Returns false when there is nothing to rewrite — an old message, or one
     * sent before a restart — so the caller relays it as a new message
     * instead. A caption edit goes through `editMessageCaption`, since
     * `editMessageText` refuses a message that has a photo attached.
     */
    private function editInTelegram(Relay $bot, DiscordMessage $message, string $text): bool
    {
        $sent = $this->toTelegram->lookup((string) $message->id);

        if ($sent === null) {
            return false;
        }

        // Discord fires MESSAGE_UPDATE for things that are not edits at all —
        // a link unfurling into an embed is the common one — and rewriting the
        // Telegram copy with the text it already has earns a "message is not
        // modified" error for every one of them.
        if (($sent['text'] ?? null) === $text) {
            return true;
        }

        $this->toTelegram->remember((string) $message->id, [...$sent, 'text' => $text]);

        // Every parameter of editMessageText is optional — the method also
        // edits an inline message, which has no chat — so they all go by name.
        $bot->getTelegram()->editMessageText(
            text: $text,
            chat_id: $sent['chat_id'],
            message_id: $sent['message_id'],
            parse_mode: 'HTML',
            link_preview_options: ['is_disabled' => true],
        )->catch(function (\Throwable $e) use ($bot, $sent, $text): void {
            $bot->getTelegram()->editMessageCaption(
                chat_id: $sent['chat_id'],
                message_id: $sent['message_id'],
                caption: MessageText::truncate($text, MessageText::TELEGRAM_CAPTION_LIMIT),
                parse_mode: 'HTML',
            )->catch(fn (\Throwable $inner) => $bot->logger->debug(
                '[bridge] could not edit the Telegram copy: ' . $inner->getMessage(),
            ));
        });

        return true;
    }

    /** Telegram → Discord. */
    private function fromTelegram(Relay $bot, TelegramMessage $message, bool $edited = false): void
    {
        $chatId = (string) ($message->chat?->id ?? '');
        $targets = $bot->getStore()->links()->discordFor($chatId);

        if ($chatId === '' || $targets === []) {
            return;
        }

        // Keep `/telegram list` honest about a group that has been renamed.
        $title = (string) ($message->chat->title ?? $message->chat->username ?? '');
        if ($title !== '') {
            $bot->getStore()->rememberTitle($chatId, $title);
        }

        $raw = $message->jsonSerialize();
        $media = Media::describe(is_array($raw) ? $raw : []);

        $text = MessageText::forDiscord(implode("\n", $this->compose($message, $media)));
        $author = $this->telegramAuthor($message);
        $key = MessageMap::telegramKey($chatId, (int) $message->message_id);

        if ($edited && $this->editInDiscord($bot, $key, $author, $text)) {
            return;
        }

        if ($media !== null && $media['mirrorable'] && $media['file_id'] !== null) {
            $bot->getTelegram()->downloadFile($media['file_id'])->then(
                fn (string $bytes) => $this->send($bot, $targets, $key, $author, $text, [
                    'filename' => $media['filename'] ?? 'file.bin',
                    'content' => $bytes,
                ]),
                function (\Throwable $e) use ($bot, $targets, $key, $author, $text, $media): void {
                    // Never log the exception's URL: a Telegram file URL
                    // carries the bot token.
                    $bot->logger->warning('[bridge] could not download a ' . $media['kind'] . ' from Telegram');

                    $this->send($bot, $targets, $key, $author, trim(($text ?? '') . "\n" . '-# ' . $media['label'] . ' (could not be mirrored)'));
                },
            );

            return;
        }

        $this->send($bot, $targets, $key, $author, $text);
    }

    /**
     * The lines a relayed Telegram message is made of: what it was carrying,
     * what it was replying to, and what it said.
     *
     * @param array{kind: string, label: string, file_id: ?string, size: ?int, filename: ?string, mirrorable: bool}|null $media
     *
     * @return list<string>
     */
    private function compose(TelegramMessage $message, ?array $media): array
    {
        $parts = [];

        if ($media !== null && ! $media['mirrorable']) {
            // Either there is no file to fetch (a location, a poll, somebody
            // joining) or it is too big to re-upload. Say what it was; a bare
            // author name with no body reads as a bug.
            $parts[] = '-# ' . $media['label'] . ($media['file_id'] !== null && $media['size'] !== null
                ? sprintf(' (%s — too large to mirror)', self::humanSize($media['size']))
                : '');
        }

        $quoted = $this->quotedReply($message);
        if ($quoted !== null) {
            $parts[] = $quoted;
        }

        $body = (string) ($message->text ?? $message->caption ?? '');
        if ($body !== '') {
            $parts[] = $body;
        }

        return $parts;
    }

    /**
     * Rewrites every Discord copy of an edited Telegram message.
     *
     * Returns false when none are remembered, so the caller relays the edit as
     * a new message rather than losing it.
     */
    private function editInDiscord(Relay $bot, string $key, string $author, ?string $text): bool
    {
        $delivered = $this->toDiscord->lookup($key);

        if ($delivered === null || $text === null || $text === '') {
            return false;
        }

        foreach ($delivered as $copy) {
            $channel = $bot->getChannel($copy['channel_id']);

            if (! $channel instanceof Channel) {
                continue;
            }

            $bot->delivery()->edit($channel, $copy['message_id'], $author, $text)
                ->catch(fn (\Throwable $e) => $bot->logger->debug(
                    '[bridge] could not edit the Discord copy in ' . $copy['channel_id'] . ': ' . $e->getMessage(),
                ));
        }

        return true;
    }

    /**
     * Fans one relayed message out to every Discord channel bridged to the
     * chat it came from, remembering where each copy landed.
     *
     * @param list<string>                                  $targets
     * @param array{filename: string, content: string}|null $file
     */
    private function send(Relay $bot, array $targets, string $key, string $author, ?string $text, ?array $file = null): void
    {
        if (($text === null || $text === '') && $file === null) {
            return;
        }

        foreach ($targets as $channelId) {
            $channel = $bot->getChannel($channelId);

            if (! $channel instanceof Channel) {
                $bot->logger->debug('[bridge] no cached channel ' . $channelId . ' — skipping');

                continue;
            }

            $bot->delivery()->deliver($channel, $author, $text, null, $file)
                ->then(function (?DiscordMessage $sent) use ($key, $channelId): void {
                    if ($sent === null) {
                        return;
                    }

                    $delivered = $this->toDiscord->lookup($key) ?? [];
                    $delivered[] = ['channel_id' => $channelId, 'message_id' => (string) $sent->id];

                    $this->toDiscord->remember($key, $delivered);
                })
                ->catch(function (\Throwable $e) use ($bot, $channel): void {
                    $bot->logger->warning('[bridge] delivery to ' . $channel->id . ' failed: ' . $e->getMessage());
                    $bot->delivery()->forget($channel);
                });
        }
    }

    /** How a Discord sender should be named in Telegram. */
    private function discordAuthor(DiscordMessage $message): string
    {
        return (string) ($message->member?->nick
            ?? $message->author?->global_name
            ?? $message->author?->username
            ?? 'someone');
    }

    /**
     * How a Telegram sender should be named in Discord.
     *
     * A channel post has no `from` at all — it is published by the channel —
     * so the chat's own title stands in.
     */
    private function telegramAuthor(TelegramMessage $message): string
    {
        $from = $message->from;

        if ($from !== null) {
            $name = trim((string) $from->getFullName());
            $handle = (string) ($from->username ?? '');

            if ($name !== '' && $handle !== '') {
                return $name . ' (@' . $handle . ')';
            }

            if ($name !== '' || $handle !== '') {
                return $name !== '' ? $name : '@' . $handle;
            }
        }

        $chat = $message->sender_chat ?? $message->chat;

        return (string) ($chat?->title ?? $chat?->username ?? 'telegram user');
    }

    /**
     * A one-line quote of what a Telegram message was replying to.
     *
     * Discord shows a reply as a link to the original; there is no such link
     * across the bridge, so the context has to be carried in the text or it is
     * lost entirely.
     */
    private function quotedReply(TelegramMessage $message): ?string
    {
        $replied = $message->reply_to_message ?? null;

        if ($replied === null) {
            return null;
        }

        $who = $this->telegramAuthor($replied);
        $what = trim((string) ($replied->text ?? $replied->caption ?? ''));

        if ($what === '') {
            $raw = $replied->jsonSerialize();
            $what = Media::describe(is_array($raw) ? $raw : [])['label'] ?? 'a message';
        }

        return sprintf('> **%s:** %s', $who, MessageText::truncate(str_replace("\n", ' ', $what), 120));
    }

    /**
     * Splits a Discord message's attachments into one photo Telegram can
     * render inline and links for everything else.
     *
     * Only the first image is sent as a photo: `sendPhoto` takes one, and a
     * media group would need every file to be an image and a different call.
     *
     * @return array{0: ?string, 1: list<string>}
     */
    private function splitAttachments(DiscordMessage $message): array
    {
        $photo = null;
        $links = [];

        foreach ($message->attachments ?? [] as $attachment) {
            $url = (string) ($attachment->url ?? '');
            if ($url === '') {
                continue;
            }

            $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

            if ($photo === null && in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                $photo = $url;

                continue;
            }

            $links[] = $url;
        }

        return [$photo, $links];
    }

    /** @return array<string, string> */
    private function userNames(DiscordMessage $message): array
    {
        $names = [];

        foreach ($message->mentions ?? [] as $user) {
            $names[(string) $user->id] = (string) ($user->global_name ?? $user->username ?? 'someone');
        }

        return $names;
    }

    /** @return array<string, string> */
    private function channelNames(DiscordMessage $message): array
    {
        $names = [];

        foreach ($message->guild?->channels ?? [] as $channel) {
            $names[(string) $channel->id] = (string) $channel->name;
        }

        return $names;
    }

    /** @return array<string, string> */
    private function roleNames(DiscordMessage $message): array
    {
        $names = [];

        foreach ($message->guild?->roles ?? [] as $role) {
            $names[(string) $role->id] = (string) $role->name;
        }

        return $names;
    }

    /** Bytes as something a human reads, for the "too large" note. */
    public static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $value = (float) $bytes;

        foreach (['KiB', 'MiB', 'GiB'] as $unit) {
            $value /= 1024;

            if ($value < 1024 || $unit === 'GiB') {
                return sprintf('%.1f %s', $value, $unit);
            }
        }

        return $bytes . ' B';
    }
}
