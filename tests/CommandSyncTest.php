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
use TelegramRelay\Helpers\CommandSync;

/**
 * A restart must publish a command whose definition changed in code, and must
 * not republish one that only *looks* different because Discord echoed it back
 * with its own fields and defaults.
 */
final class CommandSyncTest extends TestCase
{
    public function testAnUnchangedCommandIsNotRepublished(): void
    {
        $this->assertFalse(CommandSync::differs($this->published(), $this->built()));
    }

    public function testDiscordsOwnFieldsAreIgnored(): void
    {
        $published = $this->published() + [
            'id' => '123456789',
            'application_id' => '987654321',
            'version' => '111',
            'dm_permission' => true,
            'guild_id' => null,
        ];

        $this->assertFalse(CommandSync::differs($published, $this->built()));
    }

    public function testKeyOrderIsNotAChange(): void
    {
        $published = array_reverse($this->published(), true);

        $this->assertFalse(CommandSync::differs($published, $this->built()));
    }

    public function testAnOmittedFalseIsTheSameAsAnExplicitOne(): void
    {
        // Discord omits `required: false` rather than sending it.
        $published = $this->published();
        unset($published['options'][1]['options'][0]['required'], $published['nsfw']);

        $this->assertFalse(CommandSync::differs($published, $this->built()));
    }

    public function testAnOmittedTypeMeansChatInput(): void
    {
        $published = $this->published();
        unset($published['type']);

        $this->assertFalse(CommandSync::differs($published, $this->built()));
    }

    public function testContextsAndIntegrationTypesAreSets(): void
    {
        $published = $this->published();
        $published['contexts'] = [1, 0];
        $published['integration_types'] = [1, 0];

        $built = $this->built();
        $built['contexts'] = [0, 1];
        $built['integration_types'] = [0, 1];

        $this->assertFalse(CommandSync::differs($published, $built));
    }

    public function testANewSubCommandIsAChange(): void
    {
        // The case this exists for: a sub-command added in code and routed by
        // the bot, which Discord would never offer without an update.
        $built = $this->built();
        $built['options'][] = ['type' => 1, 'name' => 'status', 'description' => 'Is the bridge up?'];

        $this->assertTrue(CommandSync::differs($this->published(), $built));
    }

    public function testARewordedDescriptionIsAChange(): void
    {
        $built = $this->built();
        $built['options'][1]['description'] = 'Bridge a Discord channel to a Telegram chat, now with feeling.';

        $this->assertTrue(CommandSync::differs($this->published(), $built));
    }

    public function testAnOptionBecomingRequiredIsAChange(): void
    {
        $built = $this->built();
        $built['options'][1]['options'][0]['required'] = true;

        $this->assertTrue(CommandSync::differs($this->published(), $built));
    }

    public function testReorderedSubCommandsAreAChange(): void
    {
        // Options are shown in the order they were declared, so their order is
        // part of the definition, unlike contexts.
        $built = $this->built();
        $built['options'] = array_reverse($built['options']);

        $this->assertTrue(CommandSync::differs($this->published(), $built));
    }

    public function testObjectShapedPayloadsAreHandled(): void
    {
        // A Part or a raw gateway payload arrives as objects, not arrays.
        $published = json_decode((string) json_encode($this->published()));

        $this->assertFalse(CommandSync::differs((array) $published, $this->built()));
    }

    /** What Discord has: the same command, echoed back with its own fields. */
    private function published(): array
    {
        return $this->built() + ['id' => '1', 'application_id' => '2', 'version' => '3'];
    }

    /** What this build defines. */
    private function built(): array
    {
        return [
            'type' => 1,
            'name' => 'telegram',
            'description' => 'Bridge this server to a Telegram chat. Manage Server only.',
            'contexts' => [0],
            'integration_types' => [0],
            'nsfw' => false,
            'options' => [
                ['type' => 1, 'name' => 'list', 'description' => 'Show which channels are bridged.'],
                [
                    'type' => 1,
                    'name' => 'link',
                    'description' => 'Bridge a Discord channel to a Telegram chat.',
                    'options' => [
                        ['type' => 7, 'name' => 'channel', 'description' => 'The Discord channel to bridge.', 'required' => false],
                        ['type' => 3, 'name' => 'chat', 'description' => 'The Telegram chat.', 'required' => true],
                    ],
                ],
            ],
        ];
    }
}
