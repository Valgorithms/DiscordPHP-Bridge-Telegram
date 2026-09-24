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
use Bridge\Capability\Avatars;
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
use Telegram\Parts\Message as TelegramMessage;
use Telegram\Telegram;

use function React\Promise\reject;
use function React\Promise\resolve;

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
 * Every Bot API URL contains the bot token, and so does every file URL. That
 * is Telegram's design, not a mistake to be worked around, and it means such a
 * URL must never be logged, never be put in an exception message that might
 * be, and never be posted into Discord. Files are downloaded and re-uploaded
 * instead — see {@see describe()}, which hands the core `null` for the URL
 * every time — and every error leaving this class passes through
 * {@see TelegramText::redacted()}.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class TelegramConnector implements Connector, ProvidesActions, ProvidesModules, Editing, CanSendMedia, Avatars
{
    /** The name this connector is addressed by, in the store and in chat. */
    public const NAME = 'telegram';

    /** The updates the bridge acts on; nothing else is worth the bandwidth. */
    public const UPDATES = ['message', 'edited_message', 'channel_post', 'edited_channel_post'];

    /** How many sent photos to remember, so an edit knows to rewrite a caption. */
    private const REMEMBER_CAPTIONS = 500;

    /** Where t.me serves a public profile picture, by username, with no token. */
    private const USERPIC = 'https://t.me/i/userpic/320/';

    /** How long whether someone has a picture is believed; they rarely change it. */
    private const AVATAR_TTL = 3600.0;

    /** How many people's pictures to remember. */
    private const AVATAR_CACHE = 1000;

    private Bot $bot;

    private Telegram $telegram;

    private ?TelegramGateway $gateway = null;

    private ?TelegramAdapter $adapter = null;

    /** @var list<callable(Incoming): void> */
    private array $handlers = [];

    /** @var array<string, true> Chats the bot could last see, for the startup check. */
    private array $seen = [];

    /** @var array<string, true> "chat:message" for everything sent as a photo, whose text is a caption. */
    private array $captioned = [];

    /**
     * Each person's picture URL, or `null` for none, by user id, with when it
     * was looked up. The promise itself is kept, so people who speak at once
     * share one lookup.
     *
     * @var array<string, array{0: PromiseInterface<?string>, 1: float}>
     */
    private array $avatars = [];

    /**
     * @param array<string, mixed> $clientOptions Extra TelegramPHP options, merged
     *                                            over the ones built from the
     *                                            config — a different HTTP
     *                                            driver, or a fake one in tests.
     */
    public function __construct(
        private readonly TelegramConfig $config,
        private readonly array $clientOptions = [],
    ) {
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
        return new Surface(self::NAME, 'Telegram', TelegramText::LIMIT, markdown: false, lines: true, prefix: $this->config->prefix);
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
            // Windows PHP usually has no CA bundle configured, and TLS to
            // api.telegram.org fails outright without one.
            'socket_options' => $this->config->socketOptions(),
            'poll_timeout' => $this->config->pollTimeout,
            'allowed_updates' => self::UPDATES,
        ];

        if ($this->config->baseUrl !== null) {
            $options['base_url'] = $this->config->baseUrl;
        }

        $this->telegram = new Telegram([...$options, ...$this->clientOptions]);
    }

    /**
     * Identifies the bot and starts the long poll.
     *
     * The listeners go on first, so nothing that arrives in the first poll is
     * missed. A failure — a revoked token, no route to Telegram — is handed
     * back redacted, and the core reports this connector as down without
     * taking the others with it.
     */
    public function start(): PromiseInterface
    {
        // Refused here, by name, instead of by ReactPHP on the first request.
        // Failing to start keeps the other connectors running and stops the
        // bot pruning /telegram; throwing from boot() would take the whole
        // bot down.
        $problem = $this->config->baseUrlProblem();
        if ($problem !== null) {
            return reject(new \RuntimeException($problem));
        }

        $gateway = new TelegramGateway($this->telegram, $this->bot->getLoop(), $this->bot->getLogger());
        $this->adapter = new TelegramAdapter($this, $this->bot);

        // Said up front, because a TLS failure says nothing about which
        // certificates it was checked against.
        $this->bot->getLogger()->info($this->config->caBundle === null
            ? '[telegram] verifying TLS against the system\'s certificates (no TELEGRAM_CA_BUNDLE, and no usable one in php.ini)'
            : sprintf('[telegram] verifying TLS with %s (from %s)', $this->config->caBundle, (string) $this->config->caBundleSource));

        $gateway->onMessage(fn (TelegramMessage $message) => $this->dispatch($message, edited: false));
        $gateway->onEdit(fn (TelegramMessage $message) => $this->dispatch($message, edited: true));
        $gateway->listen();

        $this->gateway = $gateway;

        return $this->telegram->start()->then(
            function ($me): bool {
                $this->bot->getLogger()->info(sprintf(
                    '[telegram] polling as @%s; %d bridge(s) configured, commands start with %s',
                    (string) ($me->username ?? '?'),
                    $this->bot->getStore()->links(self::NAME)->count(),
                    $this->config->prefix,
                ));

                return true;
            },
            function (\Throwable $e): never {
                $this->gateway = null;

                throw TelegramText::redacted($e);
            },
        );
    }

    /** Stops the long poll. The loop is Discord's, and keeps running. */
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
     * Every bridged chat the bot could see when it last looked.
     *
     * Derived from what the API answered rather than from the configuration:
     * the point of the startup check is to catch the two disagreeing.
     */
    public function joined(): array
    {
        return array_map(strval(...), array_keys($this->seen));
    }

    public function queued(): int
    {
        return $this->gateway?->queued() ?? 0;
    }

    public function normalise(string $input): ?string
    {
        return TelegramText::normalizeChatId($input);
    }

    /**
     * Looks a chat up, by id or `@username`.
     *
     * The room is keyed by the numeric id whichever was typed: a username can
     * be changed or given away, and a bridge stored under one would follow it.
     *
     * Not cached. Nothing calls this per message, and a cached "not found"
     * would keep refusing a group for as long as the bot runs — including the
     * one somebody has just added it to, which is exactly when `link` is
     * retried.
     *
     * @return PromiseInterface<?Room>
     */
    public function resolve(string $target): PromiseInterface
    {
        return $this->telegram->getChat($target)->then(
            function ($chat) use ($target): ?Room {
                if ($chat === null) {
                    return null;
                }

                $id = (string) ($chat->id ?? $target);
                $this->seen[$id] = true;
                $username = (string) ($chat->username ?? '');
                $description = (string) ($chat->description ?? '');

                return new Room(
                    id: $id,
                    label: (string) ($chat->title ?? ($username !== '' ? '@' . $username : $id)),
                    url: $username === '' ? null : 'https://t.me/' . $username,
                    kind: (string) ($chat->type ?? 'chat'),
                    description: $description === '' ? null : $description,
                );
            },
            function (\Throwable $e) use ($target): ?Room {
                // Telegram's answer for a chat that does not exist and for one
                // the bot has been removed from. Anything else — a timeout, a
                // 5xx — is not proof of either.
                if (self::isGone($e)) {
                    unset($this->seen[$target]);

                    return null;
                }

                throw TelegramText::redacted($e);
            },
        );
    }

    /**
     * The sender's public t.me picture, when there is one to show.
     *
     * The Bot API hands out profile photos only as file URLs carrying the bot
     * token, which must never reach Discord. t.me serves the same picture
     * without one, keyed by @username, to anyone the owner lets see it. So it
     * is used when the sender has a username and the Bot API says they have a
     * photo at all; without that check, a username with no photo would show
     * t.me's placeholder. The URL is never fetched here: a connector holds no
     * HTTP client of its own, and Discord does the fetching.
     *
     * A failed lookup is not remembered, so the next message tries again.
     */
    public function avatarFor(Incoming $message): PromiseInterface
    {
        $username = $message->handle ?? '';
        $userId = $message->authorId ?? '';

        // Telegram's own username rules, which also keep the URL well formed.
        if (preg_match('/^[A-Za-z0-9_]{4,32}$/', $username) !== 1 || preg_match('/^\d+$/', $userId) !== 1) {
            return resolve(null);
        }

        $now = microtime(true);
        $cached = $this->avatars[$userId] ?? null;

        if ($cached !== null && $now - $cached[1] < self::AVATAR_TTL) {
            return $cached[0];
        }

        if (count($this->avatars) >= self::AVATAR_CACHE) {
            unset($this->avatars[array_key_first($this->avatars)]);
        }

        $failed = false;
        $lookup = $this->telegram->getUserProfilePhotos((int) $userId, limit: 1)->then(
            static fn ($photos): ?string => (int) ($photos->total_count ?? 0) > 0 ? self::USERPIC . $username . '.jpg' : null,
            function () use ($userId, &$failed): ?string {
                // Before it was stored, if it failed at once; after, if later.
                $failed = true;
                unset($this->avatars[$userId]);

                return null;
            },
        );

        if (! $failed) {
            $this->avatars[$userId] = [$lookup, $now];
        }

        return $lookup;
    }

    // ── Messages ───────────────────────────────────────────────────────

    /**
     * Sends text, escaped unless `html` says it is already Telegram HTML.
     *
     * @param array{html?: bool, reply_to?: int|string|null} $options
     */
    public function send(string $target, string $text, array $options = []): PromiseInterface
    {
        if (trim($text) === '' || $this->gateway === null) {
            return resolve(null);
        }

        // Anything this bot composes itself is plain text as far as Telegram's
        // HTML mode is concerned, so it is escaped rather than trusted: an API
        // error quoting a `<tag>` would otherwise be a parse failure. Cut to
        // length first — cutting afterwards can split an `&amp;` in half.
        $html = ($options['html'] ?? false) === true
            ? $text
            : TelegramText::escapeHtml(MessageText::truncate($text, TelegramText::LIMIT));

        return $this->gateway->send($target, $html, ['reply_to' => $options['reply_to'] ?? null])
            ->then(static fn ($message): ?string => self::messageId($message));
    }

    public function relay(string $target, Outgoing $message): PromiseInterface
    {
        $html = TelegramText::compose($message);

        return $html === null
            ? resolve(null)
            : $this->send($target, $html, ['html' => true]);
    }

    /**
     * Rewrites a relayed message in place.
     *
     * A photo's text is its caption, which is a different call with a quarter
     * of the room, so what was sent as a photo is remembered.
     */
    public function edit(string $target, string $messageId, Outgoing $message): PromiseInterface
    {
        if ($this->gateway === null) {
            return reject(new \RuntimeException('Telegram is not connected.'));
        }

        $captioned = isset($this->captioned[$target . ':' . $messageId]);
        $photo = $captioned ? self::firstPhoto($message) : null;

        $html = $captioned
            ? $this->caption($photo === null ? $message : $message->withoutMedia($photo))
            : TelegramText::compose($message);

        if ($html === null) {
            // Telegram will not blank a message; leaving it is the best there is.
            return resolve(null);
        }

        return $this->gateway->edit($target, (int) $messageId, $html, $captioned);
    }

    public function sendMedia(string $target, Attachment $media, ?Outgoing $message = null): PromiseInterface
    {
        if ($media->url === null || ! $media->isImage() || $this->gateway === null) {
            // Only a photo renders inline, and only from a URL Telegram can
            // reach; everything else relays as a link in the text.
            return reject(new \RuntimeException('not a photo Telegram can fetch'));
        }

        // Without the photo itself, which would otherwise also be linked in
        // its own caption.
        $caption = $message === null ? null : $this->caption($message->withoutMedia($media));

        return $this->gateway
            ->sendPhoto($target, $media->url, $caption)
            ->then(function ($sent) use ($target): ?string {
                $id = self::messageId($sent);

                if ($id !== null) {
                    if (count($this->captioned) >= self::REMEMBER_CAPTIONS) {
                        unset($this->captioned[array_key_first($this->captioned)]);
                    }

                    $this->captioned[$target . ':' . $id] = true;
                }

                return $id;
            });
    }

    /**
     * Downloads a file somebody sent in Telegram, for re-uploading to Discord.
     *
     * The bytes, never the URL: the URL has the token in it. Declined up front
     * when the file is known to be too big for Discord, and after the download
     * when it turns out to be — Telegram often omits the size.
     */
    public function fetchMedia(Attachment $media): PromiseInterface
    {
        if ($media->id === null || $media->id === '') {
            return resolve(null);
        }

        // Discord's limit is the lower of the two, so it is the one that matters.
        if ($media->size !== null && $media->size > Media::DISCORD_UPLOAD_LIMIT) {
            return resolve(null);
        }

        return $this->telegram->downloadFile($media->id)->then(
            static function ($bytes) use ($media): ?array {
                $bytes = (string) $bytes;

                if ($bytes === '' || strlen($bytes) > Media::DISCORD_UPLOAD_LIMIT) {
                    return null;
                }

                return [
                    'filename' => Media::safeFilename($media->name ?? ($media->isImage() ? 'photo.jpg' : 'file.bin')),
                    'content' => $bytes,
                ];
            },
            static fn (\Throwable $e) => throw TelegramText::redacted($e),
        );
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
     * Turns a TelegramPHP message into the core's own shape, running it as a
     * command first if it is one.
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

        $own = (string) ($message->from?->id ?? '') === (string) $this->config->botId();

        // A command is answered once, when it is sent. Editing one is not
        // asking again, and answering the edit would run it twice.
        if (! $own && ! $edited) {
            $this->adapter?->handle($message);
        }

        $title = (string) ($message->chat->title ?? $message->chat->username ?? '');

        if ($title !== '') {
            $this->bot->getStore()->rememberLabel(self::NAME, $chatId, $title);
        }

        $media = $this->describe(self::raw($message));
        $text = (string) ($message->text ?? $message->caption ?? '');

        $incoming = new Incoming(
            target: $chatId,
            author: $this->author($message),
            authorId: (string) ($message->from?->id ?? '') ?: null,
            // Without a trailing "@thisbot" on the first word, so the relay
            // recognises "/twitch@thisbot title" as the command it is.
            text: $this->adapter?->unaddressed($text) ?? $text,
            id: (string) ($message->message_id ?? '') ?: null,
            quoted: $this->quoted($message),
            media: $media === null ? [] : [$media],
            edited: $edited,
            own: $own,
            // Only a person's. A post made as a channel or group, or by an
            // anonymous admin, comes "from" one of Telegram's own service
            // accounts, whose picture is nobody's.
            handle: $message->sender_chat === null && $message->from?->username !== null ? (string) $message->from->username : null,
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
     * names the file — or asks {@see fetchMedia()} for the bytes — rather than
     * publishing a credential into a Discord channel.
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
            // Only when the bytes may be fetched: a file too big for Discord is
            // named instead.
            id: $described['mirrorable'] ? $described['file_id'] : null,
            name: $described['filename'] ?? $described['label'],
            size: $described['size'],
            link: self::publicLink($raw),
        );
    }

    /**
     * The message's public page on t.me, when its chat has a public username.
     *
     * `t.me/<chat>/<id>` opens for anyone and previews the picture, so a
     * network that can only carry text still gets something to click. A private
     * chat's link (`t.me/c/…`) opens only for its members, so it gets none.
     *
     * @param array<string, mixed> $raw
     */
    private static function publicLink(array $raw): ?string
    {
        $username = (string) ($raw['chat']['username'] ?? '');
        $id = (int) ($raw['message_id'] ?? 0);

        return $id > 0 && preg_match('/^[A-Za-z0-9_]{4,32}$/', $username) === 1
            ? 'https://t.me/' . $username . '/' . $id
            : null;
    }

    /** A photo's caption: the relayed text, or at least who sent it. */
    private function caption(Outgoing $message): string
    {
        return TelegramText::compose($message, TelegramText::CAPTION_LIMIT)
            ?? '<b>' . TelegramText::escapeHtml(trim(MessageText::sanitize($message->author))) . '</b>';
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
            $what = Media::describe(self::raw($replied))['label'] ?? 'a message';
        }

        return sprintf('**%s:** %s', $this->author($replied), str_replace("\n", ' ', $what));
    }

    /**
     * A message as plain arrays, all the way down.
     *
     * `jsonSerialize()` is one level deep: the sizes of a photo come back as
     * `PhotoSize` parts, not arrays, and {@see Media::describe()} — which reads
     * arrays — finds no file in them at all. Encoding runs every nested
     * part's own serialisation.
     *
     * @return array<string, mixed>
     */
    private static function raw(TelegramMessage $message): array
    {
        $raw = json_decode((string) json_encode($message), true);

        return is_array($raw) ? $raw : [];
    }

    /** The first picture Telegram was handed by URL — the one a photo was sent as. */
    private static function firstPhoto(Outgoing $message): ?Attachment
    {
        foreach ($message->media as $item) {
            if ($item->isImage() && $item->url !== null && MessageText::isRelayableUrl($item->url)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Whether an error means the chat is not there for this bot: it does not
     * exist, or the bot was removed from it.
     */
    private static function isGone(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        foreach (['chat not found', 'bot was kicked', 'bot is not a member', 'have no rights to send', 'forbidden'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** The message id out of whatever TelegramPHP handed back. */
    private static function messageId(mixed $message): ?string
    {
        $id = \is_object($message) ? ($message->message_id ?? null) : null;

        return $id === null ? null : (string) $id;
    }
}
