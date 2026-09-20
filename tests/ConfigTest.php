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

namespace TelegramRelay\Tests;

use PHPUnit\Framework\TestCase;
use TelegramRelay\Config;

final class ConfigTest extends TestCase
{
    private string $dir;

    /** @var list<string> */
    private array $touched = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/telegramrelay-config-' . bin2hex(random_bytes(6));
        @mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->touched as $key) {
            putenv($key);
        }

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    public function testReadsAnEnvFile(): void
    {
        $config = Config::fromEnvironment($this->write([
            'DISCORD_TOKEN=discord-token',
            'TELEGRAM_TOKEN=telegram-token',
            'DISCORD_OWNER_ID=123',
            'LOG_LEVEL=Debug',
        ]), $this->dir . '/relay.json');

        $this->assertSame('discord-token', $config->discordToken);
        $this->assertSame('telegram-token', $config->telegramToken);
        $this->assertSame('123', $config->discordOwnerId);
        $this->assertSame('debug', $config->logLevel);
        $this->assertSame('info', $this->minimal()->logLevel);
    }

    public function testCommentsBlankLinesAndQuotesAreHandled(): void
    {
        $config = Config::fromEnvironment($this->write([
            '# a comment',
            '',
            'DISCORD_TOKEN="quoted-token"',
            "TELEGRAM_TOKEN='single'",
            'NOT_A_PAIR',
        ]), $this->dir . '/relay.json');

        $this->assertSame('quoted-token', $config->discordToken);
        $this->assertSame('single', $config->telegramToken);
    }

    public function testAValueContainingAnEqualsSignSurvives(): void
    {
        // Telegram tokens do not contain "=", but a base64-ish override might.
        $config = Config::fromEnvironment($this->write([
            'DISCORD_TOKEN=a=b=c',
            'TELEGRAM_TOKEN=t',
        ]), $this->dir . '/relay.json');

        $this->assertSame('a=b=c', $config->discordToken);
    }

    public function testTheEnvironmentWinsOverTheFile(): void
    {
        $this->putenv('DISCORD_TOKEN', 'from-environment');

        $config = Config::fromEnvironment($this->write([
            'DISCORD_TOKEN=from-file',
            'TELEGRAM_TOKEN=t',
        ]), $this->dir . '/relay.json');

        $this->assertSame('from-environment', $config->discordToken);
    }

    public function testAMissingRequiredSettingSaysWhichOne(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/TELEGRAM_TOKEN/');

        Config::fromEnvironment($this->write(['DISCORD_TOKEN=only-this']), $this->dir . '/relay.json');
    }

    public function testAMissingFileIsNotFatalWhenTheEnvironmentHasEverything(): void
    {
        $this->putenv('DISCORD_TOKEN', 'd');
        $this->putenv('TELEGRAM_TOKEN', 't');

        $config = Config::fromEnvironment($this->dir . '/nope.env', $this->dir . '/relay.json');

        $this->assertSame('d', $config->discordToken);
        $this->assertNull($config->discordOwnerId);
    }

    public function testSocketOptionsCarryTheCaBundleOnlyWhenItExists(): void
    {
        $bundle = $this->dir . '/cacert.pem';
        file_put_contents($bundle, '');

        $withBundle = Config::fromEnvironment($this->write([
            'DISCORD_TOKEN=d',
            'TELEGRAM_TOKEN=t',
            'TELEGRAM_CA_BUNDLE=' . $bundle,
        ]), $this->dir . '/relay.json');

        $this->assertSame(['tls' => ['cafile' => $bundle]], $withBundle->socketOptions());

        // A path that isn't there would fail every request with a confusing TLS
        // error, so it is treated as unset.
        $missing = Config::fromEnvironment($this->write([
            'DISCORD_TOKEN=d',
            'TELEGRAM_TOKEN=t',
            'TELEGRAM_CA_BUNDLE=' . $this->dir . '/nothing-here.pem',
        ]), $this->dir . '/relay.json');

        $this->assertNull($missing->caBundle);
        $this->assertSame([], $missing->socketOptions());
    }

    public function testALocalBotApiServerIsOptional(): void
    {
        $this->assertNull($this->minimal()->telegramBaseUrl);

        $config = Config::fromEnvironment($this->write([
            'DISCORD_TOKEN=d',
            'TELEGRAM_TOKEN=t',
            'TELEGRAM_BASE_URL=http://127.0.0.1:8081',
        ]), $this->dir . '/relay.json');

        $this->assertSame('http://127.0.0.1:8081', $config->telegramBaseUrl);
    }

    private function minimal(): Config
    {
        return Config::fromEnvironment(
            $this->write(['DISCORD_TOKEN=d', 'TELEGRAM_TOKEN=t']),
            $this->dir . '/relay.json',
        );
    }

    /** @param list<string> $lines */
    private function write(array $lines): string
    {
        $path = $this->dir . '/.env';
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    private function putenv(string $key, string $value): void
    {
        $this->touched[] = $key;
        putenv($key . '=' . $value);
    }
}
