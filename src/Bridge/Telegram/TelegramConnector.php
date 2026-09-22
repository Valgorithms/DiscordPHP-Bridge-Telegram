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
use Bridge\Capability\Editing;
use Bridge\Capability\Media as CanSendMedia;
use Bridge\Capability\ProvidesActions;
use Bridge\Capability\ProvidesModules;
use Bridge\Command\Surface;
use Bridge\Connector;
use Bridge\Links;
use Bridge\Message\Incoming;
use Bridge\Message\Media as Attachment;
use Bridge\Message\Outgoing;
use Bridge\Room;
use Bridge\Support\MessageText;
use Bridge\Telegram\Actions\ControlActions;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

use Telegram\Parts\Message as TelegramMessage;
use Telegram\Telegram;

/**
 * Telegram, as far as the bridge is concerned.
 *
 * Owns the TelegramPHP client and the long poll, and hands the core
 * {@see Incoming} messages and {@see Room} descriptions like any other
 * connector. Nothing above this knows what a `file_id` is.
 *
 * `Telegram::run()` would call `Loop::run()` itself, so the client is started
 * through `start()` — TelegramPHP's loop-free entry point — and `Discord::run()`
 * is left to drive the loop.
 *
 * ## What it can do that Twitch cannot
 *
 * Telegram can rewrite a message it already sent and can carry a picture
 * rather than a link to one, so this implements {@see Editing} and
 * {@see CanSendMedia}. The relay asks rather than assuming, because IRC can do
 * neither and no amount of wishing makes it.
 *
 * ## The token in the URL
 *
 * A Telegram file URL contains the bot token in its path. That is Telegram's
 * design, not a mistake to be worked around, and it means such a URL must never
 * be logged, never be put in an exception message that might be, and never be
 * posted into Discord. The bytes are downloaded and re-uploaded instead; see
 * {@see describe()}, which hands the core `null` for the URL every time.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class TelegramConnector implements Connector, ProvidesActions, ProvidesModules, Editing, CanSendMedia
{
    /** The name this connector is addressed by, in the store and in chat. */
    public const NAME = 'telegram';

    private Bot $bot;

    private Telegram $telegram;

    private ?TelegramGateway $gateway = null;

    /** @var list<callable(Incoming): void> */
    private array $handlers = [];

    /** @var array<string, array<string, mixed>|null> chat id => cached chat row. */
    private array $chats = [];

    public function __construct(private readonly TelegramConfig $config)
    {
    }

    // ── Identity ───────────────────────────────────────────────────────

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'Telegram';
    }

    /** Telegram takes 4096 characters and renders its own HTML, not markdown. */
    public function surface(): Surface
    {
        return new Surface(self::NAME, 'Telegram', TelegramText::LIMIT, markdown: false, lines: true);
    }

    public function getTelegram(): Telegram
    {
        return $this->telegram;
    }

    /**
     * The gateway, for a caller that needs its pacing rather than the raw
     * client — sending a photo, most obviously.
     *
     * @throws \LogicException before the connector has started.
     */
    public function getGateway(): TelegramGateway
    {
        return $this->gateway
            ?? throw new \LogicException('The Telegram connector has not started yet.');
    }

    public function getConfig(): TelegramConfig
    {
        return $this->config;
    }

    // ── Lifecycle ──────────────────────────────────────────────────────

    public function boot(Bot $bot): void
    {
        $this->bot = $bot;

        $options = [
            'token' => $this->config->token,
            'loop' => $bot->getLoop(),
            'logger' => $bot->getLogger(),
        ];

        if ($this->config->baseUrl !== null) {
            $options['base_url'] = $this->config->baseUrl;
        }

        $this->telegram = new Telegram($options);
    }

    public function start(): void
    {
        $this->gateway = new TelegramGateway(
            $this->telegram,
            $this->bot->getLoop(),
            $this->bot->getLogger(),
        );

        $this->gateway->onMessage(fn (TelegramMessage $message) => $this->dispatch($message, edited: false));
        $this->gateway->onEdit(fn (TelegramMessage $message) => $this->dispatch($message, edited: true));
        $this->gateway->listen();

        $this->bot->getLogger()->info(sprintf(
            '[telegram] polling; %d bridge(s) configured',
            $this->bot->getStore()->links(self::NAME)->count(),
        ));
    }

    public function stop(): void
    {
        $this->telegram->stop();
    }

    // ── Rooms ──────────────────────────────────────────────────────────

    /**
     * Telegram has nothing to join: the bot is in a chat because somebody added
     * it, and it cannot let itself in.
     *
     * So there is no diff to apply — but the chats *are* checked, because a
     * bridge to a group the bot was removed from looks exactly like a quiet one
     * and the startup check is the only thing that will say otherwise.
     */
    public function sync(Links $links): array
    {
        return ['join' => [], 'part' => []];
    }

    /**
     * Every bridged chat the bot can actually see.
     *
     * Derived from what the API answers rather than from the configuration: the
     * point of the startup check is to catch the two disagreeing.
     */
    public function joined(): array
    {
        $joined = [];

        foreach ($this->chats as $id => $chat) {
            if ($chat !== null) {
                $joined[] = (string) $id;
            }
        }

        return $joined;
    }

    public function queued(): int
    {
        return $this->gateway?->queued() ?? 0;
    }

    public function normalise(string $input): ?string
    {
        return TelegramText::normalizeChatId($input);
    }

    /** @return PromiseInterface<?Room> */
    public function resolve(string $target): PromiseInterface
    {
        return $this->chat($target)->then(static fn (?array $chat): ?Room => $chat === null ? null : new Room(
            id: $chat['id'],
            label: $chat['title'],
            url: $chat['username'] === '' ? null : 'https://t.me/' . $chat['username'],
            kind: $chat['type'],
            members: $chat['members'],
            description: $chat['description'] === '' ? null : $chat['description'],
        ));
    }

    // ── Messages ───────────────────────────────────────────────────────

    public function send(string $target, string $text, array $options = []): PromiseInterface
    {
        if (trim($text) === '' || $this->gateway === null) {
            return resolve(null);
        }

        // Anything this bot composes itself is plain text as far as Telegram's
        // HTML mode is concerned, so it is escaped rather than trusted: an API
        // error quoting a `<tag>` would otherwise be a parse failure.
        $html = ($options['html'] ?? false) === true ? $text : TelegramText::escapeHtml($text);

        return $this->gateway->send($target, MessageText::truncate($html, TelegramText::LIMIT), $options)
            ->then(static fn ($message): ?string => self::messageId($message));
    }

    public function relay(string $target, Outgoing $message): PromiseInterface
    {
        $html = TelegramText::compose($message);

        return $html === null
            ? resolve(null)
            : $this->send($target, $html, ['html' => true]);
    }

    public function edit(string $target, string $messageId, string $text): PromiseInterface
    {
        return $this->telegram->editMessageText(
            chat_id: $target,
            message_id: (int) $messageId,
            text: MessageText::truncate($text, TelegramText::LIMIT),
            parse_mode: 'HTML',
        );
    }

    public function sendMedia(string $target, Attachment $media, ?Outgoing $message = null): PromiseInterface
    {
        if ($media->url === null || ! $media->isImage() || $this->gateway === null) {
            // Only a photo renders inline, and only from a URL Telegram can
            // reach; everything else relays as a link in the text.
            return reject(new \RuntimeException('not a photo Telegram can fetch'));
        }

        // A caption has a quarter of a message's room, so it is composed to
        // that limit rather than truncated from one built for the other.
        $caption = $message === null
            ? null
            : TelegramText::compose($message, TelegramText::CAPTION_LIMIT);

        return $this->gateway
            ->sendPhoto($target, $media->url, $caption)
            ->then(static fn ($sent): ?string => self::messageId($sent));
    }

    public function onIncoming(callable $handler): void
    {
        $this->handlers[] = $handler;
    }

    /** @return list<\Bridge\Command\Action> */
    public function actions(): array
    {
        return (new ControlActions())->actions();
    }

    /**
     * The buttons a panel carries, which are component interactions rather
     * than commands and so cannot be actions.
     *
     * @return list<\Bridge\Modules\Module>
     */
    public function modules(): array
    {
        return [new Panels()];
    }

    // ── Internals ──────────────────────────────────────────────────────

    /**
     * Turns a TelegramPHP message into the core's own shape.
     *
     * The chat title is remembered on the way past, so a listing can name a
     * group that has since been renamed without going and asking.
     */
    private function dispatch(TelegramMessage $message, bool $edited): void
    {
        $chatId = (string) ($message->chat?->id ?? '');

        if ($chatId === '') {
            return;
        }

        $title = (string) ($message->chat->title ?? $message->chat->username ?? '');

        if ($title !== '') {
            $this->bot->getStore()->rememberLabel(self::NAME, $chatId, $title);
        }

        $raw = $message->jsonSerialize();
        $media = $this->describe(is_array($raw) ? $raw : []);

        $incoming = new Incoming(
            target: $chatId,
            author: $this->author($message),
            authorId: (string) ($message->from?->id ?? '') ?: null,
            text: (string) ($message->text ?? $message->caption ?? ''),
            id: (string) ($message->message_id ?? '') ?: null,
            quoted: $this->quoted($message),
            media: $media === null ? [] : [$media],
            edited: $edited,
            own: (string) ($message->from?->id ?? '') === (string) $this->config->botId(),
        );

        foreach ($this->handlers as $handler) {
            $handler($incoming);
        }
    }

    /**
     * What a Telegram message was carrying, as the core's own {@see Attachment}.
     *
     * The URL is always `null`, and that is the important part: turning a
     * `file_id` into a link means asking the Bot API, and the link it returns
     * has the bot token in its path. Handing the core `null` means the relay
     * names the file rather than publishing a credential into a Discord
     * channel.
     *
     * @param array<string, mixed> $raw
     */
    private function describe(array $raw): ?Attachment
    {
        $described = Media::describe($raw);

        if ($described === null) {
            return null;
        }

        return new Attachment(
            kind: $described['kind'] === 'photo' ? Attachment::IMAGE : Attachment::FILE,
            url: null,
            id: $described['file_id'],
            name: $described['filename'] ?? $described['label'],
        );
    }

    private function author(TelegramMessage $message): string
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
    private function quoted(TelegramMessage $message): ?string
    {
        $replied = $message->reply_to_message ?? null;

        if ($replied === null) {
            return null;
        }

        $what = trim((string) ($replied->text ?? $replied->caption ?? ''));

        if ($what === '') {
            $raw = $replied->jsonSerialize();
            $what = Media::describe(is_array($raw) ? $raw : [])['label'] ?? 'a message';
        }

        return sprintf('**%s:** %s', $this->author($replied), str_replace("\n", ' ', $what));
    }

    /**
     * One chat row, cached by id — negatives included, so a group the bot was
     * removed from is not re-queried on every relayed message.
     *
     * @return PromiseInterface<array<string, mixed>|null>
     */
    private function chat(string $target): PromiseInterface
    {
        if (array_key_exists($target, $this->chats)) {
            return resolve($this->chats[$target]);
        }

        return $this->telegram->getChat($target)->then(
            function ($chat) use ($target): ?array {
                $row = $chat === null ? null : [
                    'id' => (string) ($chat->id ?? $target),
                    'title' => (string) ($chat->title ?? $chat->username ?? $target),
                    'username' => (string) ($chat->username ?? ''),
                    'type' => (string) ($chat->type ?? 'chat'),
                    'description' => (string) ($chat->description ?? ''),
                    'members' => null,
                ];

                return $this->chats[$target] = $row;
            },
            function (\Throwable $e) use ($target): ?array {
                // Never log the exception's own message unexamined: a Telegram
                // error can quote a URL, and a Telegram URL carries the token.
                $this->bot->getLogger()->debug('[telegram] could not read chat ' . $target);

                // A failed lookup is not proof the chat is gone, so it is not
                // cached as one.
                return null;
            },
        );
    }

    /** The message id out of whatever TelegramPHP handed back. */
    private static function messageId(mixed $message): ?string
    {
        $id = \is_object($message) ? ($message->message_id ?? null) : null;

        return $id === null ? null : (string) $id;
    }
}
