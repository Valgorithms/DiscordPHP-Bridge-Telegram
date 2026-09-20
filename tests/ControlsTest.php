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
use TelegramRelay\Modules\Bridge;
use TelegramRelay\Modules\Controls;

final class ControlsTest extends TestCase
{
    public function testPollOptionsAreSplitOnCommas(): void
    {
        $this->assertSame(['yes', 'no', 'maybe'], Controls::splitPollOptions('yes, no, maybe'));
    }

    public function testATrailingCommaDoesNotBecomeAnEmptyAnswer(): void
    {
        $this->assertSame(['yes', 'no'], Controls::splitPollOptions('yes, no, ,'));
    }

    public function testAnEmptyListIsCaughtBeforeItReachesTelegram(): void
    {
        $this->assertSame([], Controls::splitPollOptions('   '));
        $this->assertLessThan(Controls::POLL_MIN_OPTIONS, count(Controls::splitPollOptions('only one')));
    }

    public function testTelegramsAnswerLimitIsRecorded(): void
    {
        $this->assertSame(2, Controls::POLL_MIN_OPTIONS);
        $this->assertSame(12, Controls::POLL_MAX_OPTIONS);
    }

    public function testFileSizesAreReadable(): void
    {
        $this->assertSame('512 B', Bridge::humanSize(512));
        $this->assertSame('1.0 KiB', Bridge::humanSize(1024));
        $this->assertSame('1.5 MiB', Bridge::humanSize((int) (1.5 * 1024 * 1024)));
        $this->assertSame('2.0 GiB', Bridge::humanSize(2 * 1024 ** 3));
    }
}
