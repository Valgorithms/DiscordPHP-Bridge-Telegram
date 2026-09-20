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

namespace TelegramRelay\Helpers;

/**
 * Turns the result of the startup check into something a human can act on.
 *
 * A bridge is a pair of ends that outlive the process, and either can stop
 * working while the bot is down: the Discord channel can be deleted, the bot
 * can be removed from the server, or the Telegram group can kick it. None of
 * those produce an error at startup — they produce a bridge that quietly
 * relays nothing, which is indistinguishable from "nobody has said anything".
 *
 * So each restored bridge is probed once, and anything broken is named. Pure,
 * so the wording and the counting can be tested without a gateway.
 *
 * Nothing is pruned automatically. A guild can be briefly unavailable during a
 * Discord outage, and deleting somebody's configuration because of a bad ten
 * seconds is far worse than logging a line they can act on.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class BridgeCheck
{
    /**
     * One line per broken bridge, plus how many are healthy.
     *
     * @param list<array{channel_id: string, chat_id: string, title: ?string, channel_ok: bool, chat_ok: bool, error: ?string}> $rows
     *
     * @return array{healthy: int, problems: list<string>}
     */
    public static function summarise(array $rows): array
    {
        $healthy = 0;
        $problems = [];

        foreach ($rows as $row) {
            $problem = self::describe($row);

            if ($problem === null) {
                $healthy++;

                continue;
            }

            $problems[] = $problem;
        }

        return ['healthy' => $healthy, 'problems' => $problems];
    }

    /**
     * What is wrong with one bridge, or `null` when it is fine.
     *
     * @param array{channel_id: string, chat_id: string, title: ?string, channel_ok: bool, chat_ok: bool, error: ?string} $row
     */
    public static function describe(array $row): ?string
    {
        $chat = $row['title'] ?? $row['chat_id'];

        if (! $row['channel_ok'] && ! $row['chat_ok']) {
            return sprintf(
                'channel %s ⇄ %s: neither end is reachable — the channel is gone or I was removed from the server, and I can no longer see the chat.',
                $row['channel_id'],
                $chat,
            );
        }

        if (! $row['channel_ok']) {
            return sprintf(
                'channel %s ⇄ %s: I can\'t see that Discord channel any more. Anything said in the chat has nowhere to go.',
                $row['channel_id'],
                $chat,
            );
        }

        if (! $row['chat_ok']) {
            return sprintf(
                'channel %s ⇄ %s: I can\'t see that Telegram chat any more%s. Anything said in the channel has nowhere to go.',
                $row['channel_id'],
                $chat,
                $row['error'] === null ? '' : ' (' . MessageText::truncate($row['error'], 120) . ')',
            );
        }

        return null;
    }

    /**
     * The startup line for a working bridge set: what was restored, and from
     * where.
     */
    public static function restored(int $bridges, int $guilds, string $path): string
    {
        if ($bridges === 0) {
            return sprintf('no bridges configured yet (%s)', $path);
        }

        return sprintf(
            'restored %d bridge%s across %d server%s from %s',
            $bridges,
            $bridges === 1 ? '' : 's',
            $guilds,
            $guilds === 1 ? '' : 's',
            $path,
        );
    }
}
