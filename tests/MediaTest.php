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

use Bridge\Telegram\Media;
use PHPUnit\Framework\TestCase;

final class MediaTest extends TestCase
{
    public function testATextMessageCarriesNothing(): void
    {
        $this->assertNull(Media::describe(['message_id' => 1, 'text' => 'hello']));
    }

    public function testPhotoTakesTheLargestSize(): void
    {
        $media = Media::describe([
            'photo' => [
                ['file_id' => 'small', 'width' => 90, 'height' => 90, 'file_size' => 1000],
                ['file_id' => 'big', 'width' => 1280, 'height' => 720, 'file_size' => 200000],
                ['file_id' => 'medium', 'width' => 320, 'height' => 180, 'file_size' => 9000],
            ],
        ]);

        $this->assertNotNull($media);
        $this->assertSame('photo', $media['kind']);
        $this->assertSame('big', $media['file_id']);
        $this->assertSame('photo.jpg', $media['filename']);
        $this->assertTrue($media['mirrorable']);
    }

    public function testLargestPhotoComparesAreaRatherThanTrustingTheOrder(): void
    {
        $sizes = [
            ['file_id' => 'last-is-smaller', 'width' => 1280, 'height' => 720],
            ['file_id' => 'not-really', 'width' => 100, 'height' => 100],
        ];

        $this->assertSame('last-is-smaller', Media::largestPhoto($sizes)['file_id']);
    }

    public function testDocumentKeepsItsName(): void
    {
        $media = Media::describe([
            'document' => ['file_id' => 'doc1', 'file_name' => 'report.pdf', 'file_size' => 2048],
        ]);

        $this->assertNotNull($media);
        $this->assertSame('document', $media['kind']);
        $this->assertSame('report.pdf', $media['filename']);
        $this->assertStringContainsString('report.pdf', $media['label']);
    }

    public function testAnimationWinsOverTheDocumentTelegramSetsBesideIt(): void
    {
        // Telegram sets `document` alongside `animation` for a GIF; relaying it
        // as "a file" instead of a GIF would be a needless downgrade.
        $media = Media::describe([
            'animation' => ['file_id' => 'anim', 'file_size' => 5000],
            'document' => ['file_id' => 'doc', 'file_size' => 5000],
        ]);

        $this->assertNotNull($media);
        $this->assertSame('animation', $media['kind']);
        $this->assertSame('anim', $media['file_id']);
    }

    public function testStickerNamesItsEmojiAndPicksTheRightExtension(): void
    {
        $still = Media::describe(['sticker' => ['file_id' => 's1', 'emoji' => '👍', 'file_size' => 20]]);
        $video = Media::describe(['sticker' => ['file_id' => 's2', 'is_video' => true, 'file_size' => 20]]);

        $this->assertSame('sticker.webp', $still['filename'] ?? null);
        $this->assertStringContainsString('👍', $still['label'] ?? '');
        $this->assertSame('sticker.webm', $video['filename'] ?? null);
    }

    public function testAFileTooLargeForDiscordIsDescribedRatherThanFetched(): void
    {
        $media = Media::describe([
            'document' => ['file_id' => 'big', 'file_name' => 'dump.zip', 'file_size' => Media::DISCORD_UPLOAD_LIMIT + 1],
        ]);

        $this->assertNotNull($media);
        $this->assertFalse($media['mirrorable']);
        $this->assertSame('big', $media['file_id']);
    }

    public function testAFileTooLargeForTheCloudApiIsNotFetchedEither(): void
    {
        $media = Media::describe([
            'video' => ['file_id' => 'huge', 'file_size' => Media::TELEGRAM_DOWNLOAD_LIMIT + 1],
        ]);

        $this->assertFalse($media['mirrorable'] ?? true);
    }

    public function testAnUnknownSizeIsStillWorthTrying(): void
    {
        // Telegram omits file_size often enough that refusing without one
        // would drop ordinary photos.
        $media = Media::describe(['photo' => [['file_id' => 'p', 'width' => 100, 'height' => 100]]]);

        $this->assertNotNull($media);
        $this->assertTrue($media['mirrorable']);
        $this->assertNull($media['size']);
    }

    public function testLocationBecomesALinkAndHasNoFile(): void
    {
        $media = Media::describe(['location' => ['latitude' => 51.5007, 'longitude' => -0.1246]]);

        $this->assertNotNull($media);
        $this->assertSame('location', $media['kind']);
        $this->assertNull($media['file_id']);
        $this->assertFalse($media['mirrorable']);
        $this->assertStringContainsString('51.50070', $media['label']);
        $this->assertStringContainsString('-0.12460', $media['label']);
    }

    public function testAContactsPhoneNumberIsNotRelayed(): void
    {
        $media = Media::describe([
            'contact' => ['phone_number' => '+15551234567', 'first_name' => 'Ada', 'last_name' => 'Lovelace'],
        ]);

        $this->assertNotNull($media);
        $this->assertStringNotContainsString('5551234567', $media['label']);
        $this->assertStringContainsString('Ada Lovelace', $media['label']);
    }

    public function testPollAndDiceAreSummarised(): void
    {
        $poll = Media::describe(['poll' => ['question' => 'Tabs or spaces?']]);
        $dice = Media::describe(['dice' => ['emoji' => '🎲', 'value' => 4]]);

        $this->assertStringContainsString('Tabs or spaces?', $poll['label'] ?? '');
        $this->assertStringContainsString('4', $dice['label'] ?? '');
    }

    public function testJoinsAndPartsAreAnnouncedRatherThanDropped(): void
    {
        // A service message has no text at all, so without this a Discord
        // reader would never learn the group had changed under them.
        $joined = Media::describe([
            'new_chat_members' => [
                ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
                ['username' => 'grace'],
            ],
        ]);

        $this->assertSame('new_chat_members', $joined['kind'] ?? null);
        $this->assertStringContainsString('Ada Lovelace', $joined['label']);
        $this->assertStringContainsString('@grace', $joined['label']);

        $left = Media::describe(['left_chat_member' => ['first_name' => 'Ada']]);
        $this->assertStringContainsString('Ada left', $left['label'] ?? '');
    }

    public function testChatChangesAreAnnounced(): void
    {
        $renamed = Media::describe(['new_chat_title' => 'The New Name']);
        $repinned = Media::describe(['pinned_message' => ['text' => 'read this']]);
        $rephotoed = Media::describe(['new_chat_photo' => [['file_id' => 'x']]]);

        $this->assertStringContainsString('The New Name', $renamed['label'] ?? '');
        $this->assertStringContainsString('read this', $repinned['label'] ?? '');
        $this->assertSame('new_chat_photo', $rephotoed['kind'] ?? null);

        // None of them has a file worth fetching.
        foreach ([$renamed, $repinned, $rephotoed] as $media) {
            $this->assertFalse($media['mirrorable']);
        }
    }

    public function testCaptionIsReadBackOnlyWhenThereIsOne(): void
    {
        $this->assertSame('look at this', Media::caption(['caption' => 'look at this']));
        $this->assertNull(Media::caption(['caption' => '   ']));
        $this->assertNull(Media::caption([]));
    }

    public function testSafeFilenameCannotEscapeADirectory(): void
    {
        $this->assertSame('_.._etc_passwd', Media::safeFilename('/../etc/passwd'));
        $this->assertSame('file.bin', Media::safeFilename(''));
        $this->assertSame('file.bin', Media::safeFilename('...'));
        $this->assertSame('ab', Media::safeFilename("a\x00b"));
    }
}
