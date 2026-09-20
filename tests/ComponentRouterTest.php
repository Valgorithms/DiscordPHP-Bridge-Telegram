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
use TelegramRelay\Helpers\ComponentRouter;

final class ComponentRouterTest extends TestCase
{
    public function testIdsRoundTrip(): void
    {
        $id = ComponentRouter::id('unlink', '123456789012345678');

        $this->assertSame('tg:unlink:123456789012345678', $id);
        $this->assertSame(
            ['action' => 'unlink', 'args' => ['123456789012345678']],
            ComponentRouter::parse($id),
        );
    }

    public function testAnActionWithoutArgumentsIsFine(): void
    {
        $this->assertSame(['action' => 'reset', 'args' => []], ComponentRouter::parse(ComponentRouter::id('reset')));
    }

    public function testSeveralArgumentsSurvive(): void
    {
        $this->assertSame(
            ['action' => 'chat', 'args' => ['-1001', 'extra']],
            ComponentRouter::parse(ComponentRouter::id('chat', '-1001', 'extra')),
        );
    }

    public function testAnotherBotsComponentIsNotOurs(): void
    {
        $this->assertNull(ComponentRouter::parse('somethingelse:unlink:1'));
        $this->assertNull(ComponentRouter::parse('unlink'));
        $this->assertNull(ComponentRouter::parse(''));
        $this->assertNull(ComponentRouter::parse(null));
        $this->assertNull(ComponentRouter::parse('tg:'));
    }

    public function testASeparatorInAnArgumentIsRejectedWhenTheIdIsBuilt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ComponentRouter::id('unlink', 'a:b');
    }

    public function testAnIdTooLongForDiscordIsRejectedWhenItIsBuilt(): void
    {
        // Better here than as a 400 from Discord when the panel is posted.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . ComponentRouter::ID_LIMIT . '/');

        ComponentRouter::id('unlink', str_repeat('9', ComponentRouter::ID_LIMIT));
    }

    public function testHandlersAreRegisteredByAction(): void
    {
        $router = (new ComponentRouter())->on('unlink', static fn (): bool => true);

        $this->assertTrue($router->handles('unlink'));
        $this->assertFalse($router->handles('reset'));
    }
}
