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

namespace Bridge\Telegram\Tests;

use Bridge\Bot;
use Bridge\Command\Access;
use Bridge\Config;
use Bridge\Environment;
use Bridge\Message\Incoming;
use Bridge\Message\Media as Attachment;
use Bridge\Message\Outgoing;
use Bridge\Room;
use Bridge\Store;
use Bridge\Support\Filesystem;
use Bridge\Telegram\TelegramAdapter;
use Bridge\Telegram\TelegramConfig;
use Bridge\Telegram\TelegramConnector;
use Bridge\Telegram\Tests\Doubles\FakeHttp;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use Telegram\Events\Event;
use Telegram\Http\Exceptions\BadRequestException;
use Telegram\Parts\Message;

/**
 * The connector against a scripted Bot API: a real {@see Bot} that never
 * connects to Discord, and a Telegram client whose HTTP layer is a fake.
 *
 * Most of what is checked here is what used to be wrong — that it starts at
 * all, that a send is a valid Bot API call, that the token never leaves.
 */
final class TelegramConnectorTest extends TestCase
{
    private const CHAT = '-1001234567890';

    private string $dir;

    private FakeHttp $http;

    private TelegramConnector $connector;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bridge-telegram-' . bin2hex(random_bytes(6));
        @mkdir($this->dir, 0o777, true);

        $this->http = new FakeHttp();
        $this->http->answers['getMe'] = ['id' => 123456789, 'is_bot' => true, 'first_name' => 'Bridge', 'username' => 'BridgeBot'];

        $this->connect();
    }

    /**
     * Builds the bot and the connector under test.
     *
     * @param array<string, string> $settings Telegram settings on top of the defaults.
     */
    private function connect(array $settings = []): void
    {
        $logged = &$this->logged;
        $logger = new class ($logged) extends AbstractLogger {
            /** @param list<string> $lines */
            public function __construct(private array &$lines)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
            }
        };

        $path = $this->dir . '/bridges.json';
        $bot = new Bot(
            Config::fromEnvironment(Environment::fromArray(['DISCORD_TOKEN' => 'test.token.here']), $path),
            new Store($path, Filesystem::blocking()),
            ['logger' => $logger, 'loop' => new StreamSelectLoop()],
        );

        $this->connector = new TelegramConnector(
            TelegramConfig::fromEnvironment(Environment::fromArray($settings + [
                'TELEGRAM_TOKEN' => FakeHttp::TOKEN,
                'TELEGRAM_OWNER_ID' => '777',
            ])),
            ['http' => $this->http],
        );

        $bot->addConnector($this->connector);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    // ── Starting ───────────────────────────────────────────────────────

    public function testStartingIdentifiesTheBotAndBeginsPolling(): void
    {
        $started = $this->settle($this->connector->start());

        $this->assertTrue($started);
        $this->assertCount(1, $this->http->callsTo('getMe'));
        $this->assertCount(1, $this->http->callsTo('getUpdates'));
    }

    public function testOnlyTheUpdatesTheBridgeActsOnAreAskedFor(): void
    {
        $this->settle($this->connector->start());

        $this->assertSame(TelegramConnector::UPDATES, $this->http->callsTo('getUpdates')[0]['allowed_updates'] ?? null);
    }

    public function testAFailedStartRejectsWithoutTheToken(): void
    {
        $this->http->answers['getMe'] = new \RuntimeException('POST https://api.telegram.org/bot' . FakeHttp::TOKEN . '/getMe: 401 Unauthorized');

        $error = $this->settle($this->connector->start());

        $this->assertInstanceOf(\Throwable::class, $error);
        $this->assertStringNotContainsString(FakeHttp::TOKEN, $error->getMessage());
        $this->assertNull($error->getPrevious());
        $this->assertNoTokenLogged();
    }

    public function testAnUnusableBaseUrlStopsTheStartAndNamesTheSetting(): void
    {
        // Otherwise every request fails with ReactPHP's "Invalid request URL
        // given", which says nothing about which setting is wrong.
        $this->connect(['TELEGRAM_BASE_URL' => 'localhost:8081']);

        $error = $this->settle($this->connector->start());

        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertStringContainsString('TELEGRAM_BASE_URL', $error->getMessage());
        $this->assertSame([], $this->http->callsTo('getMe'));
    }

    // ── Sending ────────────────────────────────────────────────────────

    public function testARelayedMessageIsAValidBotApiCall(): void
    {
        // It used to pass its own `html` flag through as a named argument,
        // which PHP refuses — so every relayed message threw.
        $this->settle($this->connector->start());
        $this->settle($this->connector->relay(self::CHAT, new Outgoing('Somebody', 'hello & <welcome>')));

        $sent = $this->http->callsTo('sendMessage')[0] ?? [];

        $this->assertSame(self::CHAT, (string) ($sent['chat_id'] ?? ''));
        $this->assertSame('<b>Somebody</b>: hello &amp; &lt;welcome&gt;', $sent['text'] ?? null);
        $this->assertSame('HTML', $sent['parse_mode'] ?? null);
        $this->assertArrayNotHasKey('html', $sent);
    }

    public function testAReplyIsThreadedOntoWhatItAnswers(): void
    {
        $this->settle($this->connector->start());
        $this->settle($this->connector->send(self::CHAT, 'done', ['reply_to' => '42']));

        $sent = $this->http->callsTo('sendMessage')[0] ?? [];

        $this->assertSame(['message_id' => 42, 'allow_sending_without_reply' => true], $sent['reply_parameters'] ?? null);
    }

    public function testNothingIsSentBeforeStarting(): void
    {
        $this->assertNull($this->settle($this->connector->send(self::CHAT, 'too early')));
        $this->assertSame([], $this->http->callsTo('sendMessage'));
    }

    public function testAPhotoIsNotAlsoLinkedInItsOwnCaption(): void
    {
        $this->settle($this->connector->start());
        $this->http->answers['sendPhoto'] = $this->sentMessage(10);

        $photo = new Attachment(Attachment::IMAGE, 'https://cdn.discordapp.com/attachments/1/2/cat.png', '2', 'cat.png');
        $this->settle($this->connector->sendMedia(self::CHAT, $photo, new Outgoing('Somebody', 'look', media: [$photo])));

        $sent = $this->http->callsTo('sendPhoto')[0] ?? [];

        $this->assertSame('https://cdn.discordapp.com/attachments/1/2/cat.png', $sent['photo'] ?? null);
        $this->assertSame('<b>Somebody</b>: look', $sent['caption'] ?? null);
    }

    public function testAPhotoWithNoTextStillSaysWhoSentIt(): void
    {
        $this->settle($this->connector->start());
        $this->http->answers['sendPhoto'] = $this->sentMessage(10);

        $photo = new Attachment(Attachment::IMAGE, 'https://cdn.discordapp.com/attachments/1/2/cat.png', '2', 'cat.png');
        $this->settle($this->connector->sendMedia(self::CHAT, $photo, new Outgoing('Somebody', '', media: [$photo])));

        $this->assertSame('<b>Somebody</b>', $this->http->callsTo('sendPhoto')[0]['caption'] ?? null);
    }

    // ── Editing ────────────────────────────────────────────────────────

    public function testAnEditRewritesTheText(): void
    {
        $this->settle($this->connector->start());
        $this->settle($this->connector->edit(self::CHAT, '5', new Outgoing('Somebody', 'fixed')));

        $edit = $this->http->callsTo('editMessageText')[0] ?? [];

        $this->assertSame(5, $edit['message_id'] ?? null);
        $this->assertSame('<b>Somebody</b>: fixed', $edit['text'] ?? null);
    }

    public function testAnEditOfAPhotoRewritesItsCaption(): void
    {
        $this->settle($this->connector->start());
        $this->http->answers['sendPhoto'] = $this->sentMessage(10);

        $photo = new Attachment(Attachment::IMAGE, 'https://cdn.discordapp.com/attachments/1/2/cat.png', '2', 'cat.png');
        $this->settle($this->connector->sendMedia(self::CHAT, $photo, new Outgoing('Somebody', 'look', media: [$photo])));
        $this->settle($this->connector->edit(self::CHAT, '10', new Outgoing('Somebody', 'look at this', media: [$photo], edited: true)));

        $this->assertSame([], $this->http->callsTo('editMessageText'));
        $this->assertSame('<b>Somebody</b>: look at this', $this->http->callsTo('editMessageCaption')[0]['caption'] ?? null);
    }

    // ── Rooms ──────────────────────────────────────────────────────────

    public function testAChatIsStoredByIdWhateverItWasCalled(): void
    {
        // A username can change hands; a bridge must not follow it.
        $this->http->answers['getChat'] = ['id' => (int) self::CHAT, 'type' => 'supergroup', 'title' => 'My Group', 'username' => 'mygroup'];

        $room = $this->settle($this->connector->resolve('@mygroup'));

        $this->assertInstanceOf(Room::class, $room);
        $this->assertSame(self::CHAT, $room->id);
        $this->assertSame('https://t.me/mygroup', $room->url);
        $this->assertSame([self::CHAT], $this->connector->joined());
    }

    public function testAChatThatIsNotThereIsNull(): void
    {
        $this->http->answers['getChat'] = new BadRequestException('Bad Request: chat not found', 400);

        $this->assertNull($this->settle($this->connector->resolve(self::CHAT)));
    }

    public function testALookupThatFailedIsNotReportedAsMissing(): void
    {
        // A timeout is not proof the group is gone, and `link` has to say so.
        $this->http->answers['getChat'] = new \RuntimeException('Transport error on POST https://api.telegram.org/bot' . FakeHttp::TOKEN . '/getChat');

        $error = $this->settle($this->connector->resolve(self::CHAT));

        $this->assertInstanceOf(\Throwable::class, $error);
        $this->assertStringNotContainsString(FakeHttp::TOKEN, $error->getMessage());
    }

    public function testANotFoundIsNotRememberedPastTheBotBeingAdded(): void
    {
        $this->http->answers['getChat'] = new BadRequestException('Bad Request: chat not found', 400);
        $this->settle($this->connector->resolve(self::CHAT));

        $this->http->answers['getChat'] = ['id' => (int) self::CHAT, 'type' => 'supergroup', 'title' => 'My Group'];

        $this->assertInstanceOf(Room::class, $this->settle($this->connector->resolve(self::CHAT)));
    }

    // ── Files ──────────────────────────────────────────────────────────

    public function testAFileIsFetchedAsBytesNeverAsALink(): void
    {
        $this->http->answers['getFile'] = ['file_id' => 'abc', 'file_unique_id' => 'u', 'file_path' => 'photos/file_1.jpg'];
        $this->http->files['photos/file_1.jpg'] = 'JPEGBYTES';

        $file = $this->settle($this->connector->fetchMedia(new Attachment(Attachment::IMAGE, null, 'abc', 'photo.jpg', size: 9)));

        $this->assertSame(['filename' => 'photo.jpg', 'content' => 'JPEGBYTES'], $file);
    }

    public function testAFileTooBigForDiscordIsNotDownloaded(): void
    {
        $file = $this->settle($this->connector->fetchMedia(new Attachment(Attachment::FILE, null, 'abc', 'big.bin', size: 50 * 1024 * 1024)));

        $this->assertNull($file);
        $this->assertSame([], $this->http->callsTo('getFile'));
    }

    public function testAFailedDownloadDoesNotCarryTheFileUrl(): void
    {
        $this->http->answers['getFile'] = ['file_id' => 'abc', 'file_unique_id' => 'u', 'file_path' => 'photos/missing.jpg'];

        $error = $this->settle($this->connector->fetchMedia(new Attachment(Attachment::IMAGE, null, 'abc', 'photo.jpg')));

        $this->assertInstanceOf(\Throwable::class, $error);
        $this->assertStringNotContainsString(FakeHttp::TOKEN, $error->getMessage());
    }

    // ── Inbound ────────────────────────────────────────────────────────

    public function testAMessageArrivesAsIncomingWithItsFileButNoUrl(): void
    {
        $this->settle($this->connector->start());

        $received = [];
        $this->connector->onIncoming(static function (Incoming $incoming) use (&$received): void {
            $received[] = $incoming;
        });

        $this->receive([
            'caption' => 'look',
            'photo' => [['file_id' => 'small', 'file_unique_id' => 's', 'width' => 90, 'height' => 90, 'file_size' => 100]],
        ]);

        $this->assertCount(1, $received);
        $this->assertSame(self::CHAT, $received[0]->target);
        $this->assertSame('look', $received[0]->text);
        $this->assertNull($received[0]->media[0]->url);
        $this->assertSame('small', $received[0]->media[0]->id);
        $this->assertFalse($received[0]->own);
    }

    public function testACommandIsAnsweredInTheChatItCameFrom(): void
    {
        $this->settle($this->connector->start());
        $this->http->answers['getChatMember'] = ['status' => 'member', 'user' => ['id' => 5, 'is_bot' => false, 'first_name' => 'Some']];

        $this->receive(['text' => '!telegram', 'message_id' => 31]);

        $reply = $this->http->callsTo('sendMessage')[0] ?? [];

        $this->assertStringStartsWith('!telegram: ', (string) ($reply['text'] ?? ''));
        $this->assertSame(31, $reply['reply_parameters']['message_id'] ?? null);
    }

    public function testACommandAddressedToThisBotIsStillOne(): void
    {
        $this->settle($this->connector->start());
        $this->http->answers['getChatMember'] = ['status' => 'member', 'user' => ['id' => 5, 'is_bot' => false, 'first_name' => 'Some']];

        $this->receive(['text' => '!telegram@BridgeBot']);

        $this->assertCount(1, $this->http->callsTo('sendMessage'));
    }

    // ── Rank ───────────────────────────────────────────────────────────

    public function testRankComesFromTheChatMembership(): void
    {
        $this->assertSame(Access::Administrator, TelegramAdapter::accessFor('creator'));
        $this->assertSame(Access::Moderator, TelegramAdapter::accessFor('administrator'));
        $this->assertSame(Access::Everyone, TelegramAdapter::accessFor('member'));
        $this->assertSame(Access::Everyone, TelegramAdapter::accessFor('left'));
    }

    public function testTheConfiguredOwnerIsTheOperatorWithoutAsking(): void
    {
        $this->settle($this->connector->start());
        $adapter = new TelegramAdapter($this->connector, $this->botOf());

        $this->assertSame(Access::Operator, $this->settle($adapter->rank($this->message(['from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Owner']]))));
        $this->assertSame([], $this->http->callsTo('getChatMember'));
    }

    public function testAFailedRankLookupGrantsNothing(): void
    {
        $this->settle($this->connector->start());
        $this->http->answers['getChatMember'] = new \RuntimeException('timeout');
        $adapter = new TelegramAdapter($this->connector, $this->botOf());

        $this->assertSame(Access::Everyone, $this->settle($adapter->rank($this->message())));
    }

    public function testARankIsAskedForOncePerMinute(): void
    {
        $this->settle($this->connector->start());
        $this->http->answers['getChatMember'] = ['status' => 'administrator', 'user' => ['id' => 5, 'is_bot' => false, 'first_name' => 'Some']];

        $now = 1000.0;
        $adapter = new TelegramAdapter($this->connector, $this->botOf(), static function () use (&$now): float {
            return $now;
        });

        $adapter->rank($this->message());
        $adapter->rank($this->message());
        $this->assertCount(1, $this->http->callsTo('getChatMember'));

        $now += TelegramAdapter::RANK_TTL + 1;
        $adapter->rank($this->message());
        $this->assertCount(2, $this->http->callsTo('getChatMember'));
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function assertNoTokenLogged(): void
    {
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString(FakeHttp::TOKEN, $line);
        }
    }

    /** @param array<string, mixed> $fields */
    private function receive(array $fields): void
    {
        $this->connector->getTelegram()->emit(Event::MESSAGE, [$this->message($fields)]);
    }

    /** @param array<string, mixed> $fields */
    private function message(array $fields = []): Message
    {
        /** @var Message */
        return $this->connector->getTelegram()->getFactory()->create('Message', $fields + [
            'message_id' => 30,
            'date' => 1_700_000_000,
            'chat' => ['id' => (int) self::CHAT, 'type' => 'supergroup', 'title' => 'My Group'],
            'from' => ['id' => 5, 'is_bot' => false, 'first_name' => 'Some', 'last_name' => 'Body'],
        ]);
    }

    /** @return array<string, mixed> */
    private function sentMessage(int $id): array
    {
        return [
            'message_id' => $id,
            'date' => 1_700_000_000,
            'chat' => ['id' => (int) self::CHAT, 'type' => 'supergroup', 'title' => 'My Group'],
        ];
    }

    private function botOf(): Bot
    {
        $bot = (new \ReflectionProperty(TelegramConnector::class, 'bot'))->getValue($this->connector);
        \assert($bot instanceof Bot);

        return $bot;
    }

    /** What a promise settled with: its value, or the reason it rejected. */
    private function settle(PromiseInterface $promise): mixed
    {
        $result = null;
        $promise->then(
            static function (mixed $value) use (&$result): void {
                $result = $value;
            },
            static function (\Throwable $e) use (&$result): void {
                $result = $e;
            },
        );

        return $result;
    }
}
