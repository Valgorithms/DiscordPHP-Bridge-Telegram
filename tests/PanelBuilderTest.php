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

use Discord\Builders\Components\Button;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message;
use PHPUnit\Framework\TestCase;
use TelegramRelay\Builders\PanelBuilder;
use TelegramRelay\Helpers\ComponentRouter;

final class PanelBuilderTest extends TestCase
{
    /** Component type ids, from the Discord documentation. */
    private const TYPE_ACTION_ROW = 1;

    private const TYPE_BUTTON = 2;

    private const TYPE_SECTION = 9;

    private const TYPE_TEXT_DISPLAY = 10;

    private const TYPE_SEPARATOR = 14;

    private const TYPE_CONTAINER = 17;

    public function testAPanelIsAMessageBuilder(): void
    {
        // The reason it extends rather than wraps: anything that takes a
        // MessageBuilder takes a panel, with no unwrapping step.
        $this->assertInstanceOf(MessageBuilder::class, PanelBuilder::notice('hello'));
    }

    public function testAPanelSetsTheComponentsV2FlagAndNoContent(): void
    {
        $panel = $this->decode(PanelBuilder::notice('hello'));

        $this->assertSame(Message::FLAG_IS_COMPONENTS_V2, $panel['flags'] & Message::FLAG_IS_COMPONENTS_V2);
        $this->assertArrayNotHasKey('content', $panel);
    }

    public function testAPanelCanNeverPing(): void
    {
        $this->assertSame(['parse' => []], $this->decode(PanelBuilder::notice('@everyone'))['allowed_mentions']);
    }

    public function testEverythingLivesInOneAccentedContainer(): void
    {
        $panel = $this->decode(PanelBuilder::success('done'));

        $this->assertCount(1, $panel['components']);
        $this->assertSame(self::TYPE_CONTAINER, $panel['components'][0]['type']);
        $this->assertSame(PanelBuilder::SUCCESS, $panel['components'][0]['accent_color']);
        $this->assertSame(self::TYPE_TEXT_DISPLAY, $panel['components'][0]['components'][0]['type']);
        $this->assertSame('done', $panel['components'][0]['components'][0]['content']);
    }

    public function testTheVariantsCarryTheirOwnColour(): void
    {
        $this->assertSame(PanelBuilder::DANGER, $this->container(PanelBuilder::error('no'))['accent_color']);
        $this->assertSame(PanelBuilder::WARNING, $this->container(PanelBuilder::warning('hmm'))['accent_color']);
        $this->assertSame(PanelBuilder::ACCENT, $this->container(PanelBuilder::notice('hi'))['accent_color']);
    }

    public function testFluentAdditionsAppearInOrder(): void
    {
        $panel = PanelBuilder::new()
            ->addText('first')
            ->addSeparator()
            ->addRow('row', Button::danger(ComponentRouter::id('unlink', '1'))->setLabel('Unlink'))
            ->addActions(Button::secondary(ComponentRouter::id('dismiss'))->setLabel('Cancel'));

        $components = $this->container($panel)['components'];

        $this->assertSame(
            [self::TYPE_TEXT_DISPLAY, self::TYPE_SEPARATOR, self::TYPE_SECTION, self::TYPE_ACTION_ROW],
            array_column($components, 'type'),
        );
    }

    public function testAnEmptyLinkListExplainsHowToStartOne(): void
    {
        $text = $this->container(PanelBuilder::links([]))['components'][0]['content'];

        $this->assertStringContainsString('/telegram link', $text);
        $this->assertStringContainsString('/telegram here', $text);
    }

    public function testEachBridgeRowCarriesItsOwnUnlinkButton(): void
    {
        $panel = PanelBuilder::links([
            ['channel_id' => '111', 'chat_id' => '-1001', 'title' => 'My Group'],
            ['channel_id' => '222', 'chat_id' => '-1002', 'title' => null],
        ]);

        $sections = array_values(array_filter(
            $this->container($panel)['components'],
            static fn (array $component): bool => $component['type'] === self::TYPE_SECTION,
        ));

        $this->assertCount(2, $sections);
        $this->assertStringContainsString('<#111>', $sections[0]['components'][0]['content']);
        $this->assertStringContainsString('My Group', $sections[0]['components'][0]['content']);
        $this->assertSame(self::TYPE_BUTTON, $sections[0]['accessory']['type']);
        $this->assertSame('tg:unlink:111', $sections[0]['accessory']['custom_id']);
        $this->assertSame('tg:unlink:222', $sections[1]['accessory']['custom_id']);

        // A chat with no remembered title still has to be identifiable.
        $this->assertStringContainsString('-1002', $sections[1]['components'][0]['content']);
    }

    public function testATitleCannotStyleThePanelItIsShownIn(): void
    {
        $panel = PanelBuilder::links([['channel_id' => '111', 'chat_id' => '-1001', 'title' => '**boom**']]);

        $sections = array_values(array_filter(
            $this->container($panel)['components'],
            static fn (array $component): bool => $component['type'] === self::TYPE_SECTION,
        ));
        $content = $sections[0]['components'][0]['content'];

        $this->assertStringContainsString('\\*\\*boom\\*\\*', $content);
    }

    public function testTooManyBridgesAreTruncatedRatherThanRejectedByDiscord(): void
    {
        $rows = [];
        for ($i = 0; $i < PanelBuilder::MAX_ROWS + 3; $i++) {
            $rows[] = ['channel_id' => (string) $i, 'chat_id' => '-100' . $i, 'title' => null];
        }

        $components = $this->container(PanelBuilder::links($rows))['components'];
        $sections = array_filter($components, static fn (array $c): bool => $c['type'] === self::TYPE_SECTION);

        $this->assertCount(PanelBuilder::MAX_ROWS, $sections);
        $this->assertStringContainsString('and 3 more', end($components)['content']);
    }

    public function testTheChatPanelShowsWhatTheBotCanSeeAndWhatItCanDo(): void
    {
        $panel = PanelBuilder::chat([
            'title' => 'My Group',
            'type' => 'supergroup',
            'chat_id' => '-1001',
            'members' => 1234,
            'description' => 'A group about things',
            'username' => 'mygroup',
            'linked_channels' => ['111', '222'],
        ]);

        $components = $this->container($panel)['components'];
        $text = $components[0]['content'];

        $this->assertStringContainsString('My Group', $text);
        $this->assertStringContainsString('supergroup', $text);
        $this->assertStringContainsString('1,234', $text);
        $this->assertStringContainsString('https://t.me/mygroup', $text);
        $this->assertStringContainsString('A group about things', $text);
        $this->assertStringContainsString('<#111>', $text);

        $buttons = array_column(end($components)['components'], 'custom_id');
        $this->assertSame(['tg:chat:-1001', 'tg:invite:-1001', 'tg:members:-1001'], $buttons);
    }

    public function testTheChatPanelCopesWithAChatItKnowsLittleAbout(): void
    {
        $text = $this->container(PanelBuilder::chat([
            'title' => 'Private chat',
            'type' => 'private',
            'chat_id' => '4242',
        ]))['components'][0]['content'];

        $this->assertStringContainsString('Not bridged to any channel here.', $text);
        $this->assertStringNotContainsString('member', $text);
    }

    public function testTheChatPanelNeverCarriesAThumbnail(): void
    {
        // A chat photo is only reachable through a URL containing the bot
        // token, so the panel must not try to show one.
        $json = (string) json_encode(PanelBuilder::chat(['title' => 'g', 'type' => 'group', 'chat_id' => '-1']));

        $this->assertStringNotContainsString('"type":11', $json);
        $this->assertStringNotContainsString('thumbnail', $json);
    }

    public function testAConfirmPanelOffersBothWaysOut(): void
    {
        $panel = PanelBuilder::confirm('Sure?', ComponentRouter::id('reset'), 'Clear them all');
        $components = $this->container($panel)['components'];
        $buttons = end($components)['components'];

        $this->assertSame(PanelBuilder::DANGER, $this->container($panel)['accent_color']);
        $this->assertSame('tg:reset', $buttons[0]['custom_id']);
        $this->assertSame('Clear them all', $buttons[0]['label']);
        $this->assertSame('tg:dismiss', $buttons[1]['custom_id']);
    }

    /** @return array<string, mixed> */
    private function decode(PanelBuilder $panel): array
    {
        return json_decode((string) json_encode($panel), true);
    }

    /** @return array<string, mixed> */
    private function container(PanelBuilder $panel): array
    {
        return $this->decode($panel)['components'][0];
    }
}
