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

use Bridge\Environment;
use Bridge\Telegram\TelegramConfig;
use PHPUnit\Framework\TestCase;

/**
 * Only what Telegram itself needs. Reading a `.env` file is the core's job, and
 * is tested there.
 */
final class TelegramConfigTest extends TestCase
{
    public function testReadsTheToken(): void
    {
        $this->assertSame('123:abc', $this->config(['TELEGRAM_TOKEN' => '123:abc'])->token);
    }

    public function testAMissingTokenSaysSo(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/TELEGRAM_TOKEN/');

        $this->config([]);
    }

    public function testTheBaseUrlIsOnlyForASelfHostedApiServer(): void
    {
        $this->assertNull($this->config(['TELEGRAM_TOKEN' => '123:abc'])->baseUrl);
        $this->assertSame(
            'https://api.example.test',
            $this->config(['TELEGRAM_TOKEN' => '123:abc', 'TELEGRAM_BASE_URL' => 'https://api.example.test'])->baseUrl,
        );
    }

    public function testThePollIntervalHasAFloor(): void
    {
        // A zero or negative interval would busy-loop the poller.
        $this->assertSame(1.0, $this->config(['TELEGRAM_TOKEN' => '1:a'])->pollInterval);
        $this->assertSame(0.5, $this->config(['TELEGRAM_TOKEN' => '1:a', 'TELEGRAM_POLL_INTERVAL' => '0'])->pollInterval);
        $this->assertSame(2.5, $this->config(['TELEGRAM_TOKEN' => '1:a', 'TELEGRAM_POLL_INTERVAL' => '2.5'])->pollInterval);
    }

    public function testTheBotIdIsReadOffTheTokenRatherThanFetched(): void
    {
        // It is needed to recognise the bot's own messages coming back, which
        // is before the API has answered anything.
        $this->assertSame('123456', $this->config(['TELEGRAM_TOKEN' => '123456:AAHfiqq'])->botId());
    }

    public function testATokenOfAnUnexpectedShapeYieldsNoBotId(): void
    {
        // Better than a wrong one: a wrong id means the bot fails to recognise
        // its own echo, which is an infinite relay loop.
        $this->assertNull($this->config(['TELEGRAM_TOKEN' => 'not-a-token'])->botId());
    }

    public function testWhetherTheConnectorShouldBeInstalledAtAll(): void
    {
        $this->assertTrue(TelegramConfig::isConfigured(Environment::fromArray(['TELEGRAM_TOKEN' => '1:a'])));
        $this->assertFalse(TelegramConfig::isConfigured(Environment::fromArray([])));
    }

    /** @param array<string, string> $values */
    private function config(array $values): TelegramConfig
    {
        return TelegramConfig::fromEnvironment(Environment::fromArray($values));
    }
}
