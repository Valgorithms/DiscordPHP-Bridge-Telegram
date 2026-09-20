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
use TelegramRelay\Helpers\BridgeCheck;
use TelegramRelay\Modules\Startup;

final class BridgeCheckTest extends TestCase
{
    public function testAWorkingBridgeIsNotReported(): void
    {
        $this->assertNull(BridgeCheck::describe($this->row()));
    }

    public function testAMissingDiscordChannelSaysWhereMessagesWouldGo(): void
    {
        $problem = (string) BridgeCheck::describe($this->row(channel_ok: false));

        $this->assertStringContainsString('chan1', $problem);
        $this->assertStringContainsString('My Group', $problem);
        $this->assertStringContainsString('Discord channel', $problem);
    }

    public function testAnUnreachableChatIncludesWhatTelegramSaid(): void
    {
        $problem = (string) BridgeCheck::describe($this->row(chat_ok: false, error: 'Forbidden: bot was kicked from the supergroup chat'));

        $this->assertStringContainsString('Telegram chat', $problem);
        $this->assertStringContainsString('kicked', $problem);
    }

    public function testBothEndsGoneIsOneLineNotTwo(): void
    {
        $problem = (string) BridgeCheck::describe($this->row(channel_ok: false, chat_ok: false));

        $this->assertStringContainsString('neither end', $problem);
    }

    public function testAChatWithNoRememberedTitleIsIdentifiedByItsId(): void
    {
        $problem = (string) BridgeCheck::describe($this->row(title: null, chat_ok: false));

        $this->assertStringContainsString('-1001', $problem);
    }

    public function testALongTelegramErrorIsTrimmed(): void
    {
        $problem = (string) BridgeCheck::describe($this->row(chat_ok: false, error: str_repeat('x', 400)));

        $this->assertStringContainsString('…', $problem);
        $this->assertLessThan(400, mb_strlen($problem));
    }

    public function testTheSummaryCountsWhatWorksAndNamesWhatDoesnt(): void
    {
        $summary = BridgeCheck::summarise([
            $this->row(),
            $this->row(channel_ok: false),
            $this->row(),
            $this->row(chat_ok: false, error: 'chat not found'),
        ]);

        $this->assertSame(2, $summary['healthy']);
        $this->assertCount(2, $summary['problems']);
    }

    public function testAnEmptySetIsHealthy(): void
    {
        $this->assertSame(['healthy' => 0, 'problems' => []], BridgeCheck::summarise([]));
    }

    public function testTheRestoredLineNamesTheFileItCameFrom(): void
    {
        $line = BridgeCheck::restored(3, 2, '/srv/bot/var/relay.json');

        $this->assertStringContainsString('3 bridges', $line);
        $this->assertStringContainsString('2 servers', $line);
        $this->assertStringContainsString('/srv/bot/var/relay.json', $line);
    }

    public function testTheRestoredLineIsSingularForOne(): void
    {
        $this->assertStringContainsString('1 bridge across 1 server', BridgeCheck::restored(1, 1, 'relay.json'));
    }

    public function testAFreshInstallSaysSoRatherThanReportingZero(): void
    {
        $this->assertStringContainsString('no bridges configured yet', BridgeCheck::restored(0, 0, 'relay.json'));
    }

    public function testTheProbeIsDelayedAndCapped(): void
    {
        // Probing the instant modules boot reports channels as missing that
        // are merely not cached yet, and a bot bridging hundreds of chats
        // should not make hundreds of getChat calls on startup.
        $this->assertGreaterThanOrEqual(5.0, Startup::CHECK_DELAY);
        $this->assertLessThanOrEqual(30, Startup::MAX_CHAT_PROBES);
    }

    /** @return array{channel_id: string, chat_id: string, title: ?string, channel_ok: bool, chat_ok: bool, error: ?string} */
    private function row(
        bool $channel_ok = true,
        bool $chat_ok = true,
        ?string $title = 'My Group',
        ?string $error = null,
    ): array {
        return [
            'channel_id' => 'chan1',
            'chat_id' => '-1001',
            'title' => $title,
            'channel_ok' => $channel_ok,
            'chat_ok' => $chat_ok,
            'error' => $error,
        ];
    }
}
