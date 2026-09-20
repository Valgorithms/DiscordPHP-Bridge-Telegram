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
use TelegramRelay\Bridge\WebhookDelivery;

final class WebhookDeliveryTest extends TestCase
{
    public function testTheSenderIsMarkedAsComingFromTelegram(): void
    {
        $this->assertSame('Ada (telegram)', WebhookDelivery::safeUsername('Ada'));
    }

    public function testDiscordIsNeutralisedBecauseDiscordRejectsIt(): void
    {
        $name = WebhookDelivery::safeUsername('discord fan');

        $this->assertStringNotContainsStringIgnoringCase('discord ', $name);
        $this->assertStringContainsString('disc*rd', $name);
    }

    public function testALongNameIsTrimmedRatherThanDroppingTheMessage(): void
    {
        $name = WebhookDelivery::safeUsername(str_repeat('ы', 200));

        $this->assertLessThanOrEqual(WebhookDelivery::USERNAME_LIMIT, mb_strlen($name));
        $this->assertStringEndsWith(' (telegram)', $name);
    }

    public function testAnEmptyNameStillIdentifiesSomebody(): void
    {
        $this->assertSame('telegram user (telegram)', WebhookDelivery::safeUsername('   '));
    }
}
