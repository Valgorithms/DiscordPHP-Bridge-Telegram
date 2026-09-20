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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TelegramRelay\Helpers\MessageText;

final class MessageTextTest extends TestCase
{
    public function testEscapeHtmlCoversExactlyTelegramsThreeCharacters(): void
    {
        $this->assertSame('&lt;b&gt;bold&lt;/b&gt;', MessageText::escapeHtml('<b>bold</b>'));
        $this->assertSame('a &amp;amp; b', MessageText::escapeHtml('a &amp; b'));

        // Quotes are legal in Telegram's HTML body and must not become entities.
        $this->assertSame('"quoted" \'single\'', MessageText::escapeHtml('"quoted" \'single\''));
    }

    public function testForTelegramEscapesTheBodyButKeepsTheBoldAuthor(): void
    {
        $text = MessageText::forTelegram('<script>alert(1)</script>', 'Ada');

        $this->assertSame('<b>Ada</b>: &lt;script&gt;alert(1)&lt;/script&gt;', $text);
    }

    public function testForTelegramEscapesTheAuthorToo(): void
    {
        $text = MessageText::forTelegram('hi', '<b>not bold</b>');

        $this->assertSame('<b>&lt;b&gt;not bold&lt;/b&gt;</b>: hi', $text);
    }

    public function testForTelegramKeepsNewlines(): void
    {
        // Unlike an IRC bridge, a newline is not dangerous here and carries
        // meaning, so it survives.
        $this->assertSame("<b>Ada</b>: one\ntwo", MessageText::forTelegram("one\r\ntwo", 'Ada'));
    }

    public function testForTelegramDropsControlCharacters(): void
    {
        $this->assertSame('<b>Ada</b>: ab', MessageText::forTelegram("a\x00\x07b", 'Ada'));
    }

    public function testForTelegramReturnsNullWhenThereIsNothingToRelay(): void
    {
        $this->assertNull(MessageText::forTelegram('', 'Ada'));
        $this->assertNull(MessageText::forTelegram("   \n ", 'Ada'));
    }

    public function testForTelegramAppendsAttachmentsAsLinks(): void
    {
        $text = MessageText::forTelegram('look', 'Ada', attachments: ['https://cdn.example/a%20b.png']);

        $this->assertStringContainsString('<b>Ada</b>: look', (string) $text);
        $this->assertStringContainsString('<a href="https://cdn.example/a%20b.png">a b.png</a>', (string) $text);
    }

    public function testAnAttachmentAloneIsStillWorthRelaying(): void
    {
        $text = MessageText::forTelegram('', 'Ada', attachments: ['https://cdn.example/cat.png']);

        $this->assertNotNull($text);
        $this->assertStringContainsString('cat.png', $text);
    }

    public function testForTelegramTruncatesToTheLimitIncludingThePrefix(): void
    {
        $text = (string) MessageText::forTelegram(str_repeat('x', 500), 'Ada', limit: 64);

        $this->assertLessThanOrEqual(64, mb_strlen(strip_tags($text)));
        $this->assertStringEndsWith('…', $text);
    }

    public function testForDiscordPassesContentThroughUnescaped(): void
    {
        // The delivery side sends allowed_mentions: none, so an @ stays an @.
        $this->assertSame('@everyone *hi*', MessageText::forDiscord('@everyone *hi*'));
    }

    public function testForDiscordReturnsNullForAnEmptyMessage(): void
    {
        $this->assertNull(MessageText::forDiscord("\x00 \n "));
    }

    public function testResolveMentionsNamesWhatItKnowsAndDegradesWhatItDoesnt(): void
    {
        $content = 'hi <@1> and <@!2>, see <#3> about <@&4> <:wave:5>';

        $this->assertSame(
            'hi @Ada and @someone, see #general about @mods :wave:',
            MessageText::resolveMentions($content, ['1' => 'Ada'], ['3' => 'general'], ['4' => 'mods']),
        );
    }

    #[DataProvider('chatReferences')]
    public function testNormalizeChatId(string $input, ?string $expected): void
    {
        $this->assertSame($expected, MessageText::normalizeChatId($input));
    }

    public static function chatReferences(): iterable
    {
        yield 'supergroup id' => ['-1001234567890', '-1001234567890'];
        yield 'user id' => ['123456789', '123456789'];
        yield 'padded' => ['  -1001234567890 ', '-1001234567890'];
        yield 'username' => ['durov', '@durov'];
        yield 'at username' => ['@durov', '@durov'];
        yield 'link' => ['https://t.me/durov', '@durov'];
        yield 'link without scheme' => ['t.me/durov', '@durov'];
        yield 'link with query' => ['https://t.me/durov?start=1', '@durov'];
        yield 'telegram.me' => ['https://telegram.me/durov', '@durov'];

        // Invite links are joins, not chats: there is no id in them.
        yield 'private invite' => ['https://t.me/+AbCdEf', null];
        yield 'joinchat invite' => ['https://t.me/joinchat/AbCdEf', null];

        yield 'too short' => ['abc', null];
        yield 'leading digit' => ['1abc2', null];
        yield 'empty' => ['', null];
        yield 'spaces' => ['my group', null];
    }

    public function testTruncateMarksThatItHappened(): void
    {
        $this->assertSame('abc', MessageText::truncate('abc', 3));
        $this->assertSame('ab…', MessageText::truncate('abcd', 3));
        $this->assertSame('日本…', MessageText::truncate('日本語です', 3));
    }

    public function testEscapeMarkdownProtectsStructuredOutput(): void
    {
        $this->assertSame('My\\_Group', MessageText::escapeMarkdown('My_Group'));
        $this->assertSame('\\# not a heading', MessageText::escapeMarkdown('# not a heading'));
    }

    public function testFilenameFallsBackWhenTheUrlHasNone(): void
    {
        $this->assertSame('cat.png', MessageText::filename('https://cdn.example/x/cat.png?ex=1'));
        $this->assertSame('attachment', MessageText::filename('https://cdn.example/'));
    }
}
