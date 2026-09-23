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

namespace Bridge\Telegram;

use Bridge\Bot;
use Bridge\Builders\PanelBuilder;
use Bridge\Command\Access;
use Bridge\Modules\Module;
use Bridge\Room;
use Bridge\Support\MessageText;
use Bridge\Support\Permissions;
use Discord\Parts\Interactions\Interaction;
use React\Promise\PromiseInterface;

/**
 * The buttons on a `/telegram chat info` panel.
 *
 * A module rather than actions, because these are not commands: they arrive as
 * component interactions with no name attached, only a `custom_id`. An
 * {@see \Bridge\Command\Action} is a string in and a string out, which covers
 * what a chat command is and nothing else.
 *
 * Each button carries the chat id it was rendered for, so a panel still works
 * after the channel it was posted in has been re-linked somewhere else — it
 * refreshes what it was showing rather than whatever that channel points at
 * now. That is also what makes it survive a restart: nothing is remembered
 * between the panel and the press except what is written on the button.
 *
 * What the button says is not the whole check, though. A panel outlives the
 * bridge it was drawn for, and a server that has since unlinked a chat must
 * not keep minting its invite links from an old message — so every press is
 * held to a chat the pressing server bridges *now*.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Panels implements Module
{
    public function name(): string
    {
        return 'telegram-panels';
    }

    public function boot(Bot $bot): void
    {
        $bot->components()
            ->on('room', fn (Interaction $i, array $args) => $this->refresh($bot, $i, $args[0] ?? null))
            ->on('members', fn (Interaction $i, array $args) => $this->members($bot, $i, $args[0] ?? null))
            ->on('invite', fn (Interaction $i, array $args) => $this->invite($bot, $i, $args[0] ?? null));
    }

    /** Redraws the panel in place, so it does not pile up copies in the channel. */
    private function refresh(Bot $bot, Interaction $interaction, ?string $chatId): PromiseInterface
    {
        return $this->withChat($bot, $interaction, $chatId, fn (TelegramConnector $connector, string $chat) => $connector
            ->resolve($chat)
            ->then(
                fn (?Room $room) => $interaction->updateMessage($room === null
                    ? PanelBuilder::error('I cannot see that chat any more.')
                    : PanelBuilder::room($room, $bot->getStore()->links(TelegramConnector::NAME)->discordFor($chat))),
                fn (\Throwable $e) => $interaction->updateMessage($this->explain($e)),
            ));
    }

    private function members(Bot $bot, Interaction $interaction, ?string $chatId): PromiseInterface
    {
        return $this->withChat($bot, $interaction, $chatId, fn (TelegramConnector $connector, string $chat) => $connector
            ->getTelegram()
            ->getChatMemberCount($chat)
            ->then(
                fn (int $count) => $interaction->respondWithMessage(PanelBuilder::notice(sprintf(
                    '👥 **%s** member%s.',
                    number_format($count),
                    $count === 1 ? '' : 's',
                )), true),
                fn (\Throwable $e) => $interaction->respondWithMessage($this->explain($e), true),
            ));
    }

    /**
     * Mints an invite link — and says what that cost.
     *
     * Ephemeral, always, and gated: `exportChatInviteLink` **revokes the
     * previous link** and creates a new one, so the answer is both a working
     * invite to a private group and the reason the last one stopped working.
     * It is not something to leave sitting in a channel.
     */
    private function invite(Bot $bot, Interaction $interaction, ?string $chatId): PromiseInterface
    {
        $access = Permissions::accessForInteraction($interaction, $bot->getConfig()->discordOwnerId);

        if (! $access->satisfies(Access::Administrator)) {
            return $interaction->respondWithMessage(
                PanelBuilder::error(sprintf('Minting an invite link is limited to %s.', Access::Administrator->label())),
                true,
            );
        }

        return $this->withChat($bot, $interaction, $chatId, fn (TelegramConnector $connector, string $chat) => $connector
            ->getTelegram()
            ->exportChatInviteLink($chat)
            ->then(
                fn (string $link) => $interaction->respondWithMessage(PanelBuilder::warning(sprintf(
                    "🔗 %s\n-# This **replaced** the chat's previous invite link, which no longer works.",
                    $link,
                )), true),
                fn (\Throwable $e) => $interaction->respondWithMessage($this->explain($e), true),
            ));
    }

    /**
     * Resolves which chat a button is about, and refuses politely when there is
     * not one.
     *
     * The id on the button wins over the channel's current bridge; see the
     * class docblock for why.
     *
     * @param callable(TelegramConnector, string): PromiseInterface $then
     */
    private function withChat(Bot $bot, Interaction $interaction, ?string $chatId, callable $then): PromiseInterface
    {
        $connector = $bot->connector(TelegramConnector::NAME);

        if (! $connector instanceof TelegramConnector) {
            return $interaction->respondWithMessage(
                PanelBuilder::error('The Telegram side of the bridge is not connected. Try `/telegram status`.'),
                true,
            );
        }

        $links = $bot->getStore()->links(TelegramConnector::NAME);
        $chat = $chatId ?? $links->targetFor((string) $interaction->channel_id);

        if ($chat === null || $chat === '') {
            return $interaction->respondWithMessage(
                PanelBuilder::warning(
                    "This channel isn't bridged to a Telegram chat.\n"
                    . 'Someone with **Manage Server** can link it with `/telegram here`.',
                ),
                true,
            );
        }

        $guildId = (string) ($interaction->guild_id ?? '');
        $bridgedHere = array_map(strval(...), array_values($guildId === '' ? [] : $links->forGuild($guildId)));

        if (! in_array((string) $chat, $bridgedHere, true)) {
            return $interaction->respondWithMessage(
                PanelBuilder::warning('That chat is no longer bridged in this server, so this panel cannot act on it.'),
                true,
            );
        }

        return $then($connector, $chat);
    }

    /**
     * Turns a Telegram failure into something worth reading.
     *
     * Never re-uses the exception's own message unexamined: a Telegram error
     * can quote the request URL, and a Telegram URL carries the bot token.
     */
    private function explain(\Throwable $e): PanelBuilder
    {
        $reason = trim(preg_replace('#https?://\S+#', '(url)', TelegramText::redact($e->getMessage())) ?? '');

        return PanelBuilder::error(match (true) {
            str_contains($reason, 'not enough rights'),
            str_contains($reason, 'CHAT_ADMIN_REQUIRED') => 'Telegram refused: the bot is not an administrator of that chat.',
            str_contains($reason, 'chat not found') => 'Telegram does not know that chat any more.',
            $reason === '' => 'Telegram refused that, without saying why.',
            default => 'Telegram refused that: ' . MessageText::escapeMarkdown(MessageText::truncate($reason, 200)),
        });
    }
}
