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
use TelegramRelay\Helpers\MessageMap;

final class MessageMapTest extends TestCase
{
    public function testRemembersWhatAMessageBecame(): void
    {
        $map = new MessageMap();
        $map->remember('a', ['chat_id' => '-1001', 'message_id' => 7]);

        $this->assertSame(['chat_id' => '-1001', 'message_id' => 7], $map->lookup('a'));
        $this->assertNull($map->lookup('b'));
    }

    public function testTheOldestEntryIsEvictedFirst(): void
    {
        $map = new MessageMap(2);
        $map->remember('a', 1);
        $map->remember('b', 2);
        $map->remember('c', 3);

        $this->assertNull($map->lookup('a'));
        $this->assertSame(2, $map->lookup('b'));
        $this->assertSame(3, $map->lookup('c'));
        $this->assertSame(2, $map->count());
    }

    public function testReRememberingAKeyKeepsItAlive(): void
    {
        // A message that keeps being edited keeps being worth remembering.
        $map = new MessageMap(2);
        $map->remember('a', 1);
        $map->remember('b', 2);
        $map->remember('a', 'edited');
        $map->remember('c', 3);

        $this->assertSame('edited', $map->lookup('a'));
        $this->assertNull($map->lookup('b'));
    }

    public function testForgetting(): void
    {
        $map = new MessageMap();
        $map->remember('a', 1);
        $map->forget('a');

        $this->assertNull($map->lookup('a'));
        $this->assertSame(0, $map->count());
    }

    public function testATelegramKeyIsScopedToItsChat(): void
    {
        // Message ids restart per chat, so the chat has to be part of the key.
        $this->assertSame('-1001:5', MessageMap::telegramKey('-1001', 5));
        $this->assertNotSame(MessageMap::telegramKey('-1001', 5), MessageMap::telegramKey('-1002', 5));
    }
}
