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

namespace TelegramRelay\Modules;

use Discord\Parts\Channel\Channel;

use function React\Promise\all;
use function React\Promise\resolve;

use TelegramRelay\Helpers\BridgeCheck;
use TelegramRelay\Relay;

/**
 * Checks, once per start, that the bridges restored from disk still work.
 *
 * Configuration outlives the process, and both ends of a bridge can stop
 * working while the bot is down — a channel deleted, the bot removed from the
 * server, the bot kicked from the Telegram group. None of that produces an
 * error at startup; it produces a bridge that quietly relays nothing, which
 * looks exactly like a quiet day. This module is what turns that into a line
 * in the log and a DM to the owner.
 *
 * It probes rather than assumes, and it never prunes: a guild can be briefly
 * unavailable during a Discord outage, and deleting somebody's configuration
 * over a bad ten seconds would be far worse than saying so.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Startup implements Module
{
    /**
     * How long to wait before probing.
     *
     * Modules boot as soon as the gateway is ready, and a large bot is still
     * receiving `GUILD_CREATE` for a while after that; probing immediately
     * would report channels as missing that are merely not cached yet.
     */
    public const CHECK_DELAY = 10.0;

    /**
     * How many distinct Telegram chats to probe.
     *
     * `getChat` is a real API call, and a bot bridging hundreds of chats
     * should not spend its first seconds after a restart making hundreds of
     * them. The rest are reported as unchecked rather than as broken.
     */
    public const MAX_CHAT_PROBES = 25;

    public function __construct(private readonly float $delay = self::CHECK_DELAY)
    {
    }

    public function name(): string
    {
        return 'startup';
    }

    public function boot(Relay $bot): void
    {
        $store = $bot->getStore();
        $links = $store->links();

        $bot->logger->info('[startup] ' . BridgeCheck::restored(
            $links->count(),
            count($links->guilds()),
            $store->path(),
        ));

        // A file that could not be read is the one thing here that needs
        // somebody's attention immediately: the bridge is running with less
        // configuration than it was given.
        if ($store->warnings() !== []) {
            foreach ($store->warnings() as $warning) {
                $bot->logger->warning('[startup] ' . $warning);
            }

            $bot->notifyOwner(
                "⚠️ **I had trouble reading my bridge configuration.**\n- "
                . implode("\n- ", $store->warnings()),
            );
        }

        if ($links->isEmpty()) {
            return;
        }

        $bot->getLoop()->addTimer($this->delay, fn () => $this->verify($bot));
    }

    /** Probes the Telegram end of every distinct chat, then reports. */
    private function verify(Relay $bot): void
    {
        $chats = $bot->getStore()->links()->chats();
        $probes = [];

        // When the Telegram side never came up, every bridge would be
        // reported as unreachable for one reason that is already logged and
        // already in the owner's DMs. Report the Discord end only.
        if ($bot->telegramIsUp()) {
            foreach (array_slice($chats, 0, self::MAX_CHAT_PROBES) as $chatId) {
                $probes[$chatId] = $bot->getTelegram()->getChat($chatId)->then(
                    static fn (): ?string => null,
                    static fn (\Throwable $e): string => $e->getMessage(),
                );
            }
        }

        ($probes === [] ? resolve([]) : all($probes))->then(fn (array $errors) => $this->report($bot, $errors, count($chats)));
    }

    /**
     * @param array<string, string|null> $errors  chat id => why it failed, or null
     * @param int                        $chats   how many distinct chats exist
     */
    private function report(Relay $bot, array $errors, int $chats): void
    {
        $store = $bot->getStore();
        $links = $store->links();
        $rows = [];

        foreach ($links->guilds() as $guildId) {
            foreach ($links->forGuild($guildId) as $channelId => $chatId) {
                $channelId = (string) $channelId;
                $chatId = (string) $chatId;

                $rows[] = [
                    'channel_id' => $channelId,
                    'chat_id' => $chatId,
                    'title' => $store->title($chatId),
                    'channel_ok' => $bot->getChannel($channelId) instanceof Channel,
                    // A chat past the probe cap was never asked about, so it
                    // is reported as working rather than as broken.
                    'chat_ok' => ! array_key_exists($chatId, $errors) || $errors[$chatId] === null,
                    'error' => $errors[$chatId] ?? null,
                ];
            }
        }

        $summary = BridgeCheck::summarise($rows);
        $unchecked = max(0, $chats - self::MAX_CHAT_PROBES);

        $headline = sprintf(
            '%d of %d bridge%s working%s',
            $summary['healthy'],
            count($rows),
            count($rows) === 1 ? '' : 's',
            $unchecked === 0 ? '' : sprintf(' (%d chat(s) not probed)', $unchecked),
        );

        $bot->rememberCheck($headline);

        if ($summary['problems'] === []) {
            $bot->logger->info('[startup] ' . $headline);

            return;
        }

        $bot->logger->warning('[startup] ' . $headline);

        foreach ($summary['problems'] as $problem) {
            $bot->logger->warning('[startup] ' . $problem);
        }

        $bot->notifyOwner(
            sprintf("⚠️ **%s.**\n- ", $headline) . implode("\n- ", $summary['problems'])
            . "\n-# Nothing has been removed — use `/telegram list` to fix or unlink these.",
        );
    }
}
