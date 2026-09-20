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
use TelegramRelay\Store;

final class StoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/telegramrelay-test-' . bin2hex(random_bytes(6)) . '/relay.json';
    }

    protected function tearDown(): void
    {
        foreach (glob(\dirname($this->path) . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir(\dirname($this->path));
    }

    public function testLinkingPersistsAcrossInstances(): void
    {
        $store = new Store($this->path);
        $store->link('g1', 'chan1', '-1001', 'My Group');

        $reopened = new Store($this->path);

        $this->assertSame('-1001', $reopened->links()->telegramFor('chan1'));
        $this->assertSame('My Group', $reopened->title('-1001'));
    }

    public function testTheDirectoryIsCreatedOnDemand(): void
    {
        $this->assertDirectoryDoesNotExist(\dirname($this->path));

        (new Store($this->path))->link('g1', 'chan1', '-1001');

        $this->assertFileExists($this->path);
    }

    public function testLinkingAChannelAgainReplacesItsTarget(): void
    {
        $store = new Store($this->path);
        $store->link('g1', 'chan1', '-1001');
        $links = $store->link('g1', 'chan1', '-1002');

        $this->assertSame('-1002', $links->telegramFor('chan1'));
        $this->assertSame(1, $links->count());
    }

    public function testUnlinkingIsANoOpForAnUnbridgedChannel(): void
    {
        $store = new Store($this->path);
        $store->link('g1', 'chan1', '-1001');

        $links = $store->unlink('g1', 'nobody');

        $this->assertSame(1, $links->count());
    }

    public function testUnlinkingTheLastChannelDropsTheGuild(): void
    {
        $store = new Store($this->path);
        $store->link('g1', 'chan1', '-1001');
        $store->unlink('g1', 'chan1');

        $this->assertSame([], $store->toArray()['links'] ?? []);
    }

    public function testForgettingAGuildLeavesOthersAlone(): void
    {
        $store = new Store($this->path);
        $store->link('g1', 'chan1', '-1001');
        $store->link('g2', 'chan2', '-1002');

        $links = $store->forgetGuild('g1');

        $this->assertSame([], $links->forGuild('g1'));
        $this->assertSame(['chan2' => '-1002'], $links->forGuild('g2'));
    }

    public function testATitleSurvivesWhileAnotherGuildStillLinksTheChat(): void
    {
        $store = new Store($this->path);
        $store->link('g1', 'chan1', '-1001', 'Shared Group');
        $store->link('g2', 'chan2', '-1001', 'Shared Group');

        $store->unlink('g1', 'chan1');

        $this->assertSame('Shared Group', $store->title('-1001'));
    }

    public function testATitleIsForgottenWithTheLastLinkToIt(): void
    {
        $store = new Store($this->path);
        $store->link('g1', 'chan1', '-1001', 'Lonely Group');
        $store->unlink('g1', 'chan1');

        $this->assertNull($store->title('-1001'));
        $this->assertArrayNotHasKey('titles', $store->toArray());
    }

    public function testARenamedGroupIsRemembered(): void
    {
        $store = new Store($this->path);
        $store->link('g1', 'chan1', '-1001', 'Old Name');
        $store->rememberTitle('-1001', 'New Name');

        $this->assertSame('New Name', (new Store($this->path))->title('-1001'));
    }

    public function testRememberingAnUnchangedTitleDoesNotRewriteTheFile(): void
    {
        $store = new Store($this->path);
        $store->link('g1', 'chan1', '-1001', 'Same');

        $before = filemtime($this->path);
        clearstatcache();
        $contents = file_get_contents($this->path);

        $store->rememberTitle('-1001', 'Same');
        $store->rememberTitle('-1001', '');

        $this->assertSame($contents, file_get_contents($this->path));
        $this->assertSame($before, filemtime($this->path));
    }

    public function testAStoreOverGarbageStartsEmptyRatherThanThrowing(): void
    {
        @mkdir(\dirname($this->path), 0o777, true);
        file_put_contents($this->path, 'not json at all');

        $store = new Store($this->path);

        $this->assertTrue($store->links()->isEmpty());
    }

    public function testStaleTempFilesAreCleanedUpOnOpen(): void
    {
        @mkdir(\dirname($this->path), 0o777, true);
        $stale = $this->path . '.999999.tmp';
        file_put_contents($stale, '{}');

        new Store($this->path);

        $this->assertFileDoesNotExist($stale);
    }

    public function testUnicodeTitlesAreStoredReadably(): void
    {
        $store = new Store($this->path);
        $store->link('g1', 'chan1', '-1001', 'Группа');

        $this->assertStringContainsString('Группа', (string) file_get_contents($this->path));
        $this->assertSame('Группа', (new Store($this->path))->title('-1001'));
    }
}
