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

use Bridge\Command\Access;
use Bridge\Command\Action;
use Bridge\Command\ActionRegistry;
use Bridge\Command\Slash;
use Bridge\Command\Surface;
use Bridge\Telegram\Actions\ControlActions;
use Bridge\Telegram\TelegramConnector;
use PHPUnit\Framework\TestCase;

/**
 * What `/telegram` becomes, and who is allowed to use which part of it.
 *
 * Discord caps a command at 25 options and allows one level of sub-command
 * group, and it rejects the *whole command* when either is exceeded — so
 * overflowing here does not lose one command, it loses all of them.
 */
final class ControlActionsTest extends TestCase
{
    /** Discord's cap on options, sub-commands and groups alike. */
    private const MAX_OPTIONS = 25;

    public function testEveryActionIsQualifiedTelegram(): void
    {
        foreach ($this->actions() as $action) {
            $this->assertSame(TelegramConnector::NAME, $action->qualifier, $action->qualified());
        }
    }

    public function testTheTreeFitsInsideDiscordsCaps(): void
    {
        // The six bridge verbs the core adds for every connector count too.
        $topLevel = 6;
        $groups = [];

        foreach ($this->actions() as $action) {
            if ($action->group === null) {
                ++$topLevel;

                continue;
            }

            $groups[$action->group][] = $action->name;
        }

        $this->assertLessThanOrEqual(self::MAX_OPTIONS, $topLevel + count($groups));

        foreach ($groups as $group => $names) {
            $this->assertLessThanOrEqual(self::MAX_OPTIONS, count($names), "/telegram {$group}");
        }
    }

    public function testNothingCollidesWithTheBridgeVerbsTheCoreAdds(): void
    {
        $reserved = ['link', 'here', 'unlink', 'list', 'status', 'reset'];

        foreach ($this->actions() as $action) {
            $this->assertNotContains($action->name, $reserved, $action->qualified());
        }
    }

    public function testNothingInTheCatalogueCollides(): void
    {
        // The registry throws rather than letting the last registration win.
        $registry = new ActionRegistry();

        foreach ($this->actions() as $action) {
            $registry->add($action);
        }

        $this->assertSame(count($this->actions()), $registry->count());
    }

    public function testModeratingTheChatNeedsTheAdministratorRung(): void
    {
        // Banning someone from a Telegram group is not something a relayed
        // Discord message should be able to do.
        $access = $this->accessByName();

        foreach (['pin', 'unpin', 'ban', 'unban'] as $name) {
            $this->assertSame(Access::Administrator, $access[$name] ?? null, $name . ' is not gated');
        }
    }

    public function testSpeakingIntoTheChatIsOpenToEveryone(): void
    {
        // Safe because it can only ever reach a chat somebody with Manage
        // Server already bridged to that channel.
        $access = $this->accessByName();

        foreach (['send', 'photo', 'poll', 'info'] as $name) {
            $this->assertSame(Access::Everyone, $access[$name] ?? null, $name . ' should be open');
        }
    }

    public function testWhatOnlyDiscordCanDoIsMarkedAsSuch(): void
    {
        // `photo` takes a Discord attachment picker; `info` answers with a
        // Components v2 panel. Neither means anything in another chat.
        $chat = new Surface('twitch', 'Twitch', 500);
        $actions = $this->byName();

        $this->assertFalse($actions['photo']->availableOn($chat));
        $this->assertFalse($actions['info']->availableOn($chat));

        // The rest cross over, which is the point of one bot.
        $this->assertTrue($actions['send']->availableOn($chat));
        $this->assertTrue($actions['ban']->availableOn($chat));
    }

    public function testEveryActionIsReachableAsASlashCommand(): void
    {
        foreach ($this->actions() as $action) {
            $this->assertInstanceOf(Slash::class, $action->slash, $action->qualified());
        }
    }

    public function testEveryReplyIsEphemeral(): void
    {
        // Configuration and moderation are nobody else's business, and it keeps
        // the channel clean.
        foreach ($this->actions() as $action) {
            $this->assertTrue($action->slash?->ephemeral, $action->qualified() . ' answers in public');
        }
    }

    public function testPollOptionsAreSplitOnCommasAndTheEmptiesDropped(): void
    {
        $this->assertSame(['yes', 'no', 'maybe'], ControlActions::splitPollOptions('yes, no, maybe'));
        $this->assertSame(['yes', 'no'], ControlActions::splitPollOptions('yes,no,'));
        $this->assertSame(['yes'], ControlActions::splitPollOptions('  yes  '));
        $this->assertSame([], ControlActions::splitPollOptions('   '));
    }

    /** @return list<Action> */
    private function actions(): array
    {
        return (new ControlActions())->actions();
    }

    /** @return array<string, Action> */
    private function byName(): array
    {
        $byName = [];

        foreach ($this->actions() as $action) {
            $byName[$action->name] = $action;
        }

        return $byName;
    }

    /** @return array<string, Access> */
    private function accessByName(): array
    {
        return array_map(static fn (Action $a): Access => $a->access, $this->byName());
    }
}
