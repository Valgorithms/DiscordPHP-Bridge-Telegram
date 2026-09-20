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
use TelegramRelay\Links;

final class LinksTest extends TestCase
{
    public function testAnEmptyTableRoutesNothing(): void
    {
        $links = new Links();

        $this->assertTrue($links->isEmpty());
        $this->assertSame(0, $links->count());
        $this->assertNull($links->telegramFor('1'));
        $this->assertSame([], $links->discordFor('-1001'));
        $this->assertSame([], $links->chats());
    }

    public function testDiscordToTelegramIsALookup(): void
    {
        $links = new Links(['g1' => ['chan1' => '-1001', 'chan2' => '-1002']]);

        $this->assertSame('-1001', $links->telegramFor('chan1'));
        $this->assertSame('-1002', $links->telegramFor('chan2'));
        $this->assertNull($links->telegramFor('chan3'));
    }

    public function testTelegramToDiscordFansOutAcrossGuilds(): void
    {
        $links = new Links([
            'g1' => ['chan1' => '-1001'],
            'g2' => ['chan2' => '-1001', 'chan3' => '-1002'],
        ]);

        $this->assertSame(['chan1', 'chan2'], $links->discordFor('-1001'));
        $this->assertSame(['chan3'], $links->discordFor('-1002'));
    }

    public function testChatsAreDeduplicatedAndSorted(): void
    {
        $links = new Links([
            'g1' => ['chan1' => '-1002'],
            'g2' => ['chan2' => '-1002', 'chan3' => '-1001'],
        ]);

        $this->assertSame(['-1001', '-1002'], $links->chats());
    }

    public function testNumericIdsAreComparedAsStrings(): void
    {
        // A supergroup id is larger than a 32-bit int, and @username is not a
        // number at all, so everything is a string end to end.
        $links = new Links(['g1' => ['chan1' => '-1001234567890', 'chan2' => '@durov']]);

        $this->assertSame(['chan1'], $links->discordFor('-1001234567890'));
        $this->assertSame(['chan2'], $links->discordFor('@durov'));
        $this->assertTrue($links->isBridged('-1001234567890'));
        $this->assertFalse($links->isBridged('-1009999999999'));
    }

    public function testForGuildReturnsOnlyThatGuild(): void
    {
        $links = new Links([
            'g1' => ['chan1' => '-1001'],
            'g2' => ['chan2' => '-1002'],
        ]);

        $this->assertSame(['chan1' => '-1001'], $links->forGuild('g1'));
        $this->assertSame([], $links->forGuild('nobody'));
        $this->assertSame(2, $links->count());
    }
}
