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

use Bridge\Telegram\TelegramGateway;
use Bridge\Telegram\Tests\Doubles\FakeHttp;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\EventLoop\StreamSelectLoop;
use Telegram\Telegram;

/**
 * Pacing against Telegram's two limits at once — twenty a minute in a group,
 * about thirty a second overall. The clock is injected and never advanced, so
 * nothing here waits.
 */
final class TelegramGatewayTest extends TestCase
{
    private FakeHttp $http;

    private TelegramGateway $gateway;

    protected function setUp(): void
    {
        $loop = new StreamSelectLoop();
        $this->http = new FakeHttp();

        $this->gateway = new TelegramGateway(
            new Telegram(['token' => FakeHttp::TOKEN, 'http' => $this->http, 'loop' => $loop]),
            $loop,
            new NullLogger(),
            static fn (): float => 1000.0,
        );
    }

    public function testOneChatIsHeldToItsPerMinuteBudget(): void
    {
        for ($i = 0; $i < TelegramGateway::PER_CHAT + 3; ++$i) {
            $this->gateway->send('-100', 'm' . $i);
        }

        $this->assertCount(TelegramGateway::PER_CHAT, $this->http->callsTo('sendMessage'));
        $this->assertSame(3, $this->gateway->queued());
    }

    public function testABusyChatDoesNotHoldUpAQuietOne(): void
    {
        for ($i = 0; $i < TelegramGateway::PER_CHAT + 3; ++$i) {
            $this->gateway->send('-100', 'busy');
        }

        $this->gateway->send('-200', 'quiet');

        $this->assertContains('-200', array_map(
            static fn (array $call): string => (string) $call['chat_id'],
            $this->http->callsTo('sendMessage'),
        ));
    }

    public function testEveryChatTogetherIsHeldToTheGlobalBudget(): void
    {
        for ($i = 0; $i < TelegramGateway::GLOBAL + 5; ++$i) {
            $this->gateway->send((string) -$i - 1, 'one each');
        }

        $this->assertCount(TelegramGateway::GLOBAL, $this->http->callsTo('sendMessage'));
    }

    public function testTheBacklogIsBoundedAndTheOldestGoFirst(): void
    {
        $dropped = 0;

        for ($i = 0; $i < TelegramGateway::PER_CHAT + TelegramGateway::MAX_QUEUE + 2; ++$i) {
            $this->gateway->send('-100', 'm' . $i)->then(null, static function () use (&$dropped): void {
                ++$dropped;
            });
        }

        $this->assertSame(TelegramGateway::MAX_QUEUE, $this->gateway->queued());
        $this->assertSame(2, $dropped);
    }

    public function testASendThatThrowsStillSettles(): void
    {
        $this->http->answers['sendMessage'] = static fn () => throw new \TypeError('bot' . FakeHttp::TOKEN . ' bad argument');

        $error = null;
        $this->gateway->send('-100', 'x')->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        $this->assertInstanceOf(\Throwable::class, $error);
        $this->assertStringNotContainsString(FakeHttp::TOKEN, $error->getMessage());
    }
}
