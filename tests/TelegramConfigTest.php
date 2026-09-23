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

    public function testAUsableBaseUrlHasNothingWrongWithIt(): void
    {
        foreach ([null, 'http://localhost:8081', 'HTTPS://api.example.test', 'https://api.example.test/'] as $value) {
            $values = ['TELEGRAM_TOKEN' => '1:a'] + ($value === null ? [] : ['TELEGRAM_BASE_URL' => $value]);

            $this->assertNull($this->config($values)->baseUrlProblem(), (string) $value);
        }
    }

    public function testATrailingSlashIsDroppedSoThePathDoesNotDouble(): void
    {
        $this->assertSame(
            'http://localhost:8081',
            $this->config(['TELEGRAM_TOKEN' => '1:a', 'TELEGRAM_BASE_URL' => 'http://localhost:8081/'])->baseUrl,
        );
    }

    public function testABaseUrlWithoutASchemeIsNamed(): void
    {
        // The local Bot API server prints its address without one, and ReactPHP
        // then refuses every request with "Invalid request URL given".
        $problem = $this->config(['TELEGRAM_TOKEN' => '1:a', 'TELEGRAM_BASE_URL' => 'localhost:8081'])->baseUrlProblem();

        $this->assertNotNull($problem);
        $this->assertStringContainsString('TELEGRAM_BASE_URL', $problem);
        $this->assertStringContainsString("'localhost:8081'", $problem);
        $this->assertStringContainsString('http://', $problem);
    }

    public function testACommentAfterTheValueIsRecognisedAsOne(): void
    {
        foreach (['# local Bot API server', 'http://localhost:8081 # local'] as $value) {
            $problem = $this->config(['TELEGRAM_TOKEN' => '1:a', 'TELEGRAM_BASE_URL' => $value])->baseUrlProblem();

            $this->assertNotNull($problem, $value);
            $this->assertStringContainsString('comment', $problem, $value);
        }
    }

    public function testATokenPastedIntoTheBaseUrlIsNotRepeated(): void
    {
        $token = '123456789:AAHfiqksKZ8WmR2zSjiQ7_v4TMAKdiHm9T0';
        $problem = $this->config(['TELEGRAM_TOKEN' => $token, 'TELEGRAM_BASE_URL' => 'api.telegram.org/bot' . $token])->baseUrlProblem();

        $this->assertNotNull($problem);
        $this->assertStringNotContainsString($token, $problem);
    }

    public function testTheLongPollTimeoutStaysWithinWhatTelegramAllows(): void
    {
        // Zero would turn the long poll into a busy loop of short ones.
        $this->assertSame(50, $this->config(['TELEGRAM_TOKEN' => '1:a'])->pollTimeout);
        $this->assertSame(1, $this->config(['TELEGRAM_TOKEN' => '1:a', 'TELEGRAM_POLL_TIMEOUT' => '0'])->pollTimeout);
        $this->assertSame(50, $this->config(['TELEGRAM_TOKEN' => '1:a', 'TELEGRAM_POLL_TIMEOUT' => '600'])->pollTimeout);
        $this->assertSame(25, $this->config(['TELEGRAM_TOKEN' => '1:a', 'TELEGRAM_POLL_TIMEOUT' => '25'])->pollTimeout);
    }

    public function testAnExplicitCaBundleWinsOverPhpIni(): void
    {
        $config = $this->configWithIni(
            ['TELEGRAM_TOKEN' => '1:a', 'TELEGRAM_CA_BUNDLE' => 'D:/certs/mine.pem'],
            ['openssl.cafile' => __FILE__],
        );

        $this->assertSame('D:/certs/mine.pem', $config->caBundle);
        $this->assertSame('TELEGRAM_CA_BUNDLE', $config->caBundleSource);
        $this->assertSame(['tls' => ['cafile' => 'D:/certs/mine.pem']], $config->socketOptions());
    }

    public function testWithoutOneTheBundlePhpIniNamesIsUsed(): void
    {
        $config = $this->configWithIni(['TELEGRAM_TOKEN' => '1:a'], ['openssl.cafile' => __FILE__]);

        $this->assertSame(__FILE__, $config->caBundle);
        $this->assertSame('openssl.cafile', $config->caBundleSource);
    }

    public function testCurlsBundleIsTheFallbackWindowsInstallsUsuallyHave(): void
    {
        // PHP's streams never read curl.cainfo, which is exactly why it is
        // worth reading here.
        $config = $this->configWithIni(['TELEGRAM_TOKEN' => '1:a'], ['openssl.cafile' => '', 'curl.cainfo' => __FILE__]);

        $this->assertSame(__FILE__, $config->caBundle);
        $this->assertSame('curl.cainfo', $config->caBundleSource);
    }

    public function testAPhpIniPathThatIsNotAFileIsPassedOver(): void
    {
        // A missing bundle fails every connection; no bundle at least lets the
        // system's own certificates be tried.
        $config = $this->configWithIni(
            ['TELEGRAM_TOKEN' => '1:a'],
            ['openssl.cafile' => __DIR__ . '/no-such.pem', 'curl.cainfo' => __FILE__],
        );

        $this->assertSame('curl.cainfo', $config->caBundleSource);
    }

    public function testNothingConfiguredAnywhereLeavesTlsAtItsDefaults(): void
    {
        $config = $this->configWithIni(['TELEGRAM_TOKEN' => '1:a'], []);

        $this->assertNull($config->caBundle);
        $this->assertNull($config->caBundleSource);
        $this->assertSame([], $config->socketOptions());
    }

    public function testThePrefixDefaultsToTheOtherChatsOne(): void
    {
        $this->assertSame('!', $this->config(['TELEGRAM_TOKEN' => '1:a'])->prefix);
        $this->assertSame('?', $this->config(['TELEGRAM_TOKEN' => '1:a', 'TELEGRAM_PREFIX' => '?'])->prefix);
    }

    public function testTheOwnerIsANumericIdOrNobody(): void
    {
        // A username can be given up and claimed by somebody else; an id cannot.
        $this->assertSame('777', $this->config(['TELEGRAM_TOKEN' => '1:a', 'TELEGRAM_OWNER_ID' => '777'])->ownerId);
        $this->assertNull($this->config(['TELEGRAM_TOKEN' => '1:a', 'TELEGRAM_OWNER_ID' => '@someone'])->ownerId);
        $this->assertNull($this->config(['TELEGRAM_TOKEN' => '1:a'])->ownerId);
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

    /**
     * @param array<string, string> $values
     * @param array<string, string> $ini    php.ini directives, standing in for the real ones.
     */
    private function configWithIni(array $values, array $ini): TelegramConfig
    {
        return TelegramConfig::fromEnvironment(
            Environment::fromArray($values),
            static fn (string $directive): string|false => $ini[$directive] ?? false,
        );
    }
}
