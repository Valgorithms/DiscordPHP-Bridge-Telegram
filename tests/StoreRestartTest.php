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

/**
 * The configuration has to survive a restart — it is the only record of what
 * `/telegram link` was told, and losing it means every admin has to do it
 * again.
 */
final class StoreRestartTest extends TestCase
{
    private string $dir;

    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/telegramrelay-restart-' . bin2hex(random_bytes(6));
        $this->path = $this->dir . '/relay.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    public function testEveryGuildsBridgesComeBackAfterARestart(): void
    {
        $before = new Store($this->path);
        $before->link('guild1', 'chan1', '-1001', 'First Group');
        $before->link('guild1', 'chan2', '@second');
        $before->link('guild2', 'chan3', '-1001', 'First Group');

        $after = $this->restart();

        $this->assertSame(3, $after->links()->count());
        $this->assertSame('-1001', $after->links()->telegramFor('chan1'));
        $this->assertSame('@second', $after->links()->telegramFor('chan2'));
        $this->assertSame(['chan1', 'chan3'], $after->links()->discordFor('-1001'));
        $this->assertSame('First Group', $after->title('-1001'));
        $this->assertSame([], $after->warnings());
    }

    public function testAnUnlinkSurvivesTooRatherThanComingBack(): void
    {
        $before = new Store($this->path);
        $before->link('guild1', 'chan1', '-1001');
        $before->link('guild1', 'chan2', '-1002');
        $before->unlink('guild1', 'chan1');

        $after = $this->restart();

        $this->assertNull($after->links()->telegramFor('chan1'));
        $this->assertSame('-1002', $after->links()->telegramFor('chan2'));
    }

    public function testABackupIsKeptBesideTheConfiguration(): void
    {
        (new Store($this->path))->link('guild1', 'chan1', '-1001');

        $this->assertFileExists($this->path . Store::BACKUP_SUFFIX);
        $this->assertSame(
            file_get_contents($this->path),
            file_get_contents($this->path . Store::BACKUP_SUFFIX),
            'the backup should be the state that was just saved, not the one before it',
        );
    }

    public function testADamagedFileIsRecoveredFromTheBackup(): void
    {
        $before = new Store($this->path);
        $before->link('guild1', 'chan1', '-1001', 'First Group');
        $before->link('guild2', 'chan2', '-1002');

        // Truncated mid-write by something outside this process.
        file_put_contents($this->path, '{"links": {"guild1": {"chan1": "-100');

        $after = $this->restart();

        $this->assertSame(2, $after->links()->count());
        $this->assertSame('-1002', $after->links()->telegramFor('chan2'));
        $this->assertCount(1, $after->warnings());
        $this->assertStringContainsString('recovered', $after->warnings()[0]);
    }

    public function testAnUnreadableFileIsKeptRatherThanOverwritten(): void
    {
        $before = new Store($this->path);
        $before->link('guild1', 'chan1', '-1001');

        // Both copies damaged: there is nothing to recover, but the evidence
        // must not be destroyed by the next write.
        file_put_contents($this->path, 'not json');
        file_put_contents($this->path . Store::BACKUP_SUFFIX, 'not json either');

        $after = $this->restart();
        $after->link('guild9', 'chan9', '-1009');

        $kept = glob($this->dir . '/relay.json.corrupt-*') ?: [];

        $this->assertCount(1, $kept);
        $this->assertSame('not json', file_get_contents($kept[0]));
        $this->assertStringContainsString('kept as', $after->warnings()[0] ?? '');
    }

    public function testAnEmptyFileIsNotTreatedAsDamage(): void
    {
        @mkdir($this->dir, 0o777, true);
        file_put_contents($this->path, '');

        $store = new Store($this->path);

        $this->assertTrue($store->links()->isEmpty());
        $this->assertSame([], $store->warnings());
    }

    public function testAHandEditedFileCannotTakeTheBridgeDown(): void
    {
        @mkdir($this->dir, 0o777, true);
        file_put_contents($this->path, (string) json_encode([
            'links' => [
                'guild1' => ['chan1' => '-1001', 'chan2' => ['not' => 'a chat id'], 'chan3' => ''],
                'guild2' => 'not a list of channels',
            ],
            'titles' => ['-1001' => 'Fine', '-1002' => ['also' => 'wrong']],
        ]));

        $store = new Store($this->path);

        $this->assertSame(['chan1' => '-1001'], $store->links()->forGuild('guild1'));
        $this->assertSame([], $store->links()->forGuild('guild2'));
        $this->assertSame('Fine', $store->title('-1001'));
        $this->assertNull($store->title('-1002'));
        $this->assertCount(3, $store->warnings());
    }

    public function testTheGoodEntriesOfAHandEditedFileAreNotLostOnTheNextWrite(): void
    {
        @mkdir($this->dir, 0o777, true);
        file_put_contents($this->path, (string) json_encode([
            'links' => ['guild1' => ['chan1' => '-1001', 'chan2' => ['broken']]],
        ]));

        $store = new Store($this->path);
        $store->link('guild2', 'chan3', '-1003');

        $this->assertSame('-1001', $this->restart()->links()->telegramFor('chan1'));
    }

    public function testStaleTempFilesFromAKilledProcessAreCleanedUp(): void
    {
        @mkdir($this->dir, 0o777, true);
        file_put_contents($this->path . '.999999.tmp', '{}');
        file_put_contents($this->path . '.999999.bak.tmp', '{}');

        new Store($this->path);

        $this->assertSame([], glob($this->dir . '/*.tmp'));
    }

    public function testTheStorePathIsReportableForTheStartupLine(): void
    {
        $this->assertSame($this->path, (new Store($this->path))->path());
    }

    /** Re-opens the same file the way a restarted process would. */
    private function restart(): Store
    {
        return new Store($this->path);
    }
}
