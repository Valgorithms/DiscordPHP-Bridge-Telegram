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

use Bridge\Message\Media;
use Bridge\Message\Outgoing;
use Bridge\Telegram\TelegramText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What Telegram specifically demands of a message.
 *
 * The shared half — resolving mentions, truncating, naming a file — is the
 * core's and is tested there.
 */
final class TelegramTextTest extends TestCase
{
    // ── HTML mode ──────────────────────────────────────────────────────

    public function testEscapingCoversExactlyTelegramsThreeCharacters(): void
    {
        // Escaping more shows up as literal entities in the chat, because
        // Telegram does not decode them.
        $this->assertSame('&amp;&lt;&gt;', TelegramText::escapeHtml('&<>'));
        $this->assertSame('"\'', TelegramText::escapeHtml('"\''));
    }

    public function testTheBodyIsEscapedButTheAuthorsBoldSurvives(): void
    {
        $composed = (string) TelegramText::compose(new Outgoing('ada', '<b>not bold</b>'));

        $this->assertStringStartsWith('<b>ada</b>: ', $composed);
        $this->assertStringContainsString('&lt;b&gt;not bold&lt;/b&gt;', $composed);
    }

    public function testTheAuthorIsEscapedToo(): void
    {
        // A display name is somebody else's input.
        $composed = (string) TelegramText::compose(new Outgoing('<script>', 'hi'));

        $this->assertStringContainsString('&lt;script&gt;', $composed);
        $this->assertStringNotContainsString('<script>', $composed);
    }

    // ── Composing ──────────────────────────────────────────────────────

    public function testNewlinesSurviveBecauseTelegramCanCarryThem(): void
    {
        $this->assertStringContainsString("one\ntwo", (string) TelegramText::compose(new Outgoing('ada', "one\ntwo")));
    }

    public function testControlCharactersAreDropped(): void
    {
        $this->assertStringNotContainsString("\x07", (string) TelegramText::compose(new Outgoing('ada', "hi\x07")));
    }

    public function testThereIsNothingToRelayInAnEmptyMessage(): void
    {
        $this->assertNull(TelegramText::compose(new Outgoing('ada', '')));
        $this->assertNull(TelegramText::compose(new Outgoing('ada', "  \n ")));
    }

    public function testMentionsAreResolvedOnTheWayOut(): void
    {
        $composed = (string) TelegramText::compose(
            new Outgoing('ada', 'hi <@1> in <#2>', ['1' => 'Bob'], ['2' => 'general']),
        );

        $this->assertStringContainsString('hi @Bob in #general', $composed);
    }

    public function testAttachmentsAreAppendedAsLinks(): void
    {
        $composed = (string) TelegramText::compose($this->withMedia('look', ['https://cdn.example/a.png']));

        $this->assertStringContainsString('<a href="https://cdn.example/a.png">a.png</a>', $composed);
    }

    public function testAnAttachmentAloneIsStillWorthRelaying(): void
    {
        $composed = TelegramText::compose($this->withMedia('', ['https://cdn.example/a.png']));

        $this->assertNotNull($composed);
        $this->assertStringContainsString('cdn.example', (string) $composed);
    }

    public function testTheWholeThingFitsTelegramsLimitIncludingThePrefix(): void
    {
        $composed = (string) TelegramText::compose(new Outgoing(str_repeat('n', 60), str_repeat('x', 9000)));

        $this->assertLessThanOrEqual(TelegramText::LIMIT, mb_strlen(strip_tags($composed)));
    }

    // ── Naming a chat ──────────────────────────────────────────────────

    #[DataProvider('chatReferences')]
    public function testChatIdNormalisation(string $input, ?string $expected): void
    {
        $this->assertSame($expected, TelegramText::normalizeChatId($input));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function chatReferences(): iterable
    {
        yield 'numeric id' => ['-1001234567890', '-1001234567890'];
        yield 'positive id' => ['123456789', '123456789'];
        yield 'username' => ['mygroup', '@mygroup'];
        yield 'at username' => ['@mygroup', '@mygroup'];
        yield 'link' => ['https://t.me/mygroup', '@mygroup'];
        yield 'link without scheme' => ['t.me/mygroup', '@mygroup'];
        yield 'telegram.me' => ['telegram.me/mygroup', '@mygroup'];
        yield 'link with query' => ['https://t.me/mygroup?start=1', '@mygroup'];

        // Invite links are joins, not chats: there is no way to turn one into
        // an id from outside, so storing it would make a bridge that can never
        // resolve.
        yield 'private invite' => ['https://t.me/+AbCdEf', null];
        yield 'joinchat invite' => ['https://t.me/joinchat/AbCdEf', null];

        yield 'too short' => ['@abc', null];
        yield 'starts with a digit' => ['@1group', null];
        yield 'punctuation' => ['@my-group', null];
        yield 'empty' => ['', null];
    }

    /** @param list<string> $urls */
    private function withMedia(string $text, array $urls): Outgoing
    {
        return new Outgoing(
            author: 'ada',
            text: $text,
            media: array_map(static fn (string $url): Media => new Media(Media::IMAGE, $url), $urls),
        );
    }
}
