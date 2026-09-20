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

use Discord\Builders\CommandBuilder;
use Discord\Helpers\ExCollectionInterface;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\OAuth\Application;
use Discord\Repository\Interaction\GlobalCommandRepository;
use React\Promise\PromiseInterface;
use Telegram\Parts\ChatFullInfo;
use TelegramRelay\Builders\PanelBuilder;
use TelegramRelay\Helpers\ComponentRouter;
use TelegramRelay\Helpers\MessageText;
use TelegramRelay\Helpers\Permissions;
use TelegramRelay\Relay;

/**
 * `/telegram` — the bridge's per-server wiring, editable from Discord so
 * nobody has to touch a file on the host.
 *
 *   /telegram link   #channel  -1001234567890   bridge a channel
 *   /telegram here             -1001234567890   bridge the current one
 *   /telegram unlink [#channel]                 stop bridging it
 *   /telegram list                              this server's bridges
 *   /telegram status                            is the bridge actually up?
 *   /telegram reset                             clear them all
 *
 * Each sub-command is registered with `listenCommand(['telegram', '<sub>'])`,
 * so DiscordPHP's {@see \Discord\Helpers\RegisteredCommand} does the routing
 * and hands each handler a collection of its own options — the same shape as
 * a DiscordPHP application command anywhere else.
 *
 * The answers are Components v2 panels, and `list` is the reason why: each row
 * carries its own Unlink button, so removing a bridge is a click rather than
 * retyping the channel it was on.
 *
 * Guild-only and gated on server owner / Administrator / Manage Server. That
 * gate is the whole security model: whoever can run `/telegram link` chooses
 * which Discord channel gets copied into a Telegram group.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Configuration implements Module
{
    use RoutesCommands;

    public const COMMAND = 'telegram';

    public function name(): string
    {
        return 'config';
    }

    public function boot(Relay $bot): void
    {
        $bot->application->commands->freshen()->then(fn (GlobalCommandRepository $repo) => $this->define($bot, $repo));

        $this->subCommand($bot, [self::COMMAND, 'list'], fn (Interaction $i, Guild $g) => $i->respondWithMessage(PanelBuilder::links($this->rows($bot, $g)), true));
        $this->subCommand($bot, [self::COMMAND, 'status'], fn (Interaction $i, Guild $g) => $this->status($bot, $i, $g));
        $this->subCommand($bot, [self::COMMAND, 'link'], fn (Interaction $i, Guild $g, ExCollectionInterface $o) => $this->link($bot, $i, $g, (string) self::option($o, 'channel'), (string) self::option($o, 'chat')));
        $this->subCommand($bot, [self::COMMAND, 'here'], fn (Interaction $i, Guild $g, ExCollectionInterface $o) => $this->link($bot, $i, $g, (string) $i->channel_id, (string) self::option($o, 'chat')));
        $this->subCommand($bot, [self::COMMAND, 'unlink'], fn (Interaction $i, Guild $g, ExCollectionInterface $o) => $this->unlink($bot, $i, $g, (string) (self::option($o, 'channel') ?? $i->channel_id)));
        $this->subCommand($bot, [self::COMMAND, 'reset'], fn (Interaction $i, Guild $g) => $this->reset($bot, $i, $g));

        $bot->components()
            ->on('unlink', fn (Interaction $i, array $args) => $this->unlinkButton($bot, $i, $args[0] ?? ''))
            ->on('reset', fn (Interaction $i) => $this->resetButton($bot, $i));
    }

    /**
     * Publishes the global command.
     *
     * Created when Discord has never seen it, updated when this build defines
     * something different, and left alone otherwise — see
     * {@see RoutesCommands::publishCommand()}.
     */
    private function define(Relay $bot, GlobalCommandRepository $repo): void
    {
        $sub = static fn (string $name, string $desc): Option => (new Option($bot))
            ->setType(Option::SUB_COMMAND)->setName($name)->setDescription($desc);

        $channelOption = static fn (bool $required): Option => (new Option($bot))
            ->setType(Option::CHANNEL)
            ->setName('channel')
            ->setDescription('The Discord channel to bridge.')
            ->setRequired($required);

        $chatOption = static fn (): Option => (new Option($bot))
            ->setType(Option::STRING)
            ->setName('chat')
            ->setDescription('The Telegram chat: an id like -1001234567890, or @username for a public group.')
            ->setRequired(true);

        $this->publishCommand($bot, $repo, CommandBuilder::new()
            ->setType(Command::CHAT_INPUT)
            ->setName(self::COMMAND)
            ->setDescription('Bridge this server to a Telegram chat. Manage Server only.')
            ->setContext([Interaction::CONTEXT_TYPE_GUILD])
            ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL)
            ->addOption($sub('list', 'Show which channels are bridged to which Telegram chats.'))
            ->addOption($sub('status', 'Check whether the Telegram side of the bridge is up.'))
            ->addOption($sub('link', 'Bridge a Discord channel to a Telegram chat.')
                ->addOption($channelOption(true))
                ->addOption($chatOption()))
            ->addOption($sub('here', 'Bridge the channel you are in to a Telegram chat.')
                ->addOption($chatOption()))
            ->addOption($sub('unlink', 'Stop bridging a channel. Defaults to the one you are in.')
                ->addOption($channelOption(false)))
            ->addOption($sub('reset', 'Clear every bridge on this server.')));
    }

    /**
     * What the bridge is actually doing right now.
     *
     * Worth its own sub-command because almost every "it isn't relaying"
     * report has one of three causes, and two of them are visible from here:
     * the Telegram side never started, or nothing is linked. The third —
     * Telegram's privacy mode hiding group messages from the bot — cannot be
     * read back through the API, so it is named in the panel instead.
     */
    private function status(Relay $bot, Interaction $interaction, Guild $guild): PromiseInterface
    {
        $links = $bot->getStore()->links();
        $here = count($links->forGuild($guild->id));
        $up = $bot->telegramIsUp();

        $lines = [
            '## Bridge status',
            $up
                ? sprintf('✅ Telegram connected as **@%s**', MessageText::escapeMarkdown((string) ($bot->getTelegram()->getBotUser()?->username ?? '?')))
                : '❌ **The Telegram side is not connected.** Check `TELEGRAM_TOKEN` and the log.',
            sprintf('🔗 **%d** bridge%s on this server, **%d** in total', $here, $here === 1 ? '' : 's', $links->count()),
        ];

        if ($up) {
            $queued = $bot->gateway()->queued();
            $lines[] = sprintf('📤 **%d** message%s queued for Telegram', $queued, $queued === 1 ? '' : 's');
        }

        $check = $bot->getLastCheck();
        if ($check !== null) {
            $lines[] = sprintf('🩺 Last startup check: %s', $check);
        }

        $lines[] = '';
        $lines[] = '-# Relaying nothing from a Telegram group? Its privacy mode is on by default — '
            . 'set `/setprivacy` → Disable with @BotFather, then remove and re-add the bot to the group.';

        return $interaction->respondWithMessage(
            PanelBuilder::notice(implode("\n", $lines), $up ? PanelBuilder::ACCENT : PanelBuilder::DANGER),
            true,
        );
    }

    private function link(Relay $bot, Interaction $interaction, Guild $guild, string $channelId, string $chat): PromiseInterface
    {
        $chatId = MessageText::normalizeChatId($chat);

        if ($chatId === null) {
            return $interaction->respondWithMessage(
                PanelBuilder::error(sprintf(
                    "`%s` doesn't look like a Telegram chat.\n\n"
                    . 'Use the numeric id (`-1001234567890`) or the public `@username`. A `t.me/+…` invite link '
                    . "won't work — it's a join link, not an id.\n"
                    . '-# Add the bot to the group first; forwarding one of its messages to @userinfobot shows the id.',
                    MessageText::escapeMarkdown(mb_substr($chat, 0, 60)),
                )),
                true,
            );
        }

        if (! $bot->telegramIsUp()) {
            return $interaction->respondWithMessage(
                PanelBuilder::error('The Telegram side of the bridge is not connected, so I can\'t check that chat. Try `/telegram status`.'),
                true,
            );
        }

        $channel = $guild->channels->get('id', $channelId);

        if ($channel instanceof Channel && ! $channel->isTextBased()) {
            return $interaction->respondWithMessage(PanelBuilder::error('That channel can\'t carry text messages.'), true);
        }

        // Confirm the chat exists and the bot is in it before wiring anything
        // up — otherwise this produces a bridge that is silently never going
        // to work, which is the most confusing failure mode here.
        return $bot->getTelegram()->getChat($chatId)->then(
            function (ChatFullInfo $chatInfo) use ($bot, $interaction, $guild, $channelId) {
                $title = (string) ($chatInfo->title ?? $chatInfo->username ?? $chatInfo->id);

                $bot->getStore()->link($guild->id, $channelId, (string) $chatInfo->id, $title);

                $lines = [
                    sprintf('✅ <#%s> is now bridged to **%s**.', $channelId, MessageText::escapeMarkdown($title)),
                    'Messages posted there will appear in Telegram, and vice-versa.',
                ];

                $warning = $this->deliveryWarning($guild, $channelId);
                if ($warning !== null) {
                    $lines[] = '';
                    $lines[] = $warning;
                }

                if (($chatInfo->type ?? '') !== 'private') {
                    $lines[] = '';
                    $lines[] = '-# If nothing arrives from Telegram, the bot\'s privacy mode is hiding group '
                        . 'messages from it: @BotFather → `/setprivacy` → Disable, then re-add the bot to the group.';
                }

                return $interaction->respondWithMessage(PanelBuilder::success(implode("\n", $lines)), true);
            },
            fn (\Throwable $e) => $interaction->respondWithMessage(
                PanelBuilder::error(sprintf(
                    "I can't see the chat `%s`.\n-# Telegram said: `%s`\n\nThe bot has to be a member of the group before it can be bridged.",
                    MessageText::escapeMarkdown($chatId),
                    MessageText::escapeMarkdown($e->getMessage()),
                )),
                true,
            ),
        );
    }

    private function unlink(Relay $bot, Interaction $interaction, Guild $guild, string $channelId): PromiseInterface
    {
        $before = $bot->getStore()->links()->telegramFor($channelId);

        if ($before === null) {
            return $interaction->respondWithMessage(
                PanelBuilder::warning(sprintf('<#%s> wasn\'t bridged.', $channelId)),
                true,
            );
        }

        $title = $bot->getStore()->title($before) ?? $before;
        $bot->getStore()->unlink($guild->id, $channelId);

        return $interaction->respondWithMessage(
            PanelBuilder::success(sprintf('🧹 <#%s> is no longer bridged to **%s**.', $channelId, MessageText::escapeMarkdown($title))),
            true,
        );
    }

    private function reset(Relay $bot, Interaction $interaction, Guild $guild): PromiseInterface
    {
        $count = count($bot->getStore()->links()->forGuild($guild->id));

        if ($count === 0) {
            return $interaction->respondWithMessage(PanelBuilder::warning('There are no bridges on this server to clear.'), true);
        }

        return $interaction->respondWithMessage(
            PanelBuilder::confirm(
                sprintf("## Clear every bridge?\nThis removes **%d** bridge%s from this server. It can't be undone.", $count, $count === 1 ? '' : 's'),
                ComponentRouter::id('reset'),
                'Clear them all',
            ),
            true,
        );
    }

    /** The Unlink button on a `/telegram list` row. */
    private function unlinkButton(Relay $bot, Interaction $interaction, string $channelId): PromiseInterface
    {
        $guild = $interaction->guild;

        if (! $guild instanceof Guild || ! Permissions::mayConfigure($interaction, $guild)) {
            return $interaction->respondWithMessage(PanelBuilder::error('You need **Manage Server** to change the bridge.'), true);
        }

        $bot->getStore()->unlink($guild->id, $channelId);

        // Redraw the panel in place, so the row that was just removed is
        // visibly gone rather than leaving a button that now does nothing.
        return $interaction->updateMessage(PanelBuilder::links($this->rows($bot, $guild)));
    }

    /** The confirm button on `/telegram reset`. */
    private function resetButton(Relay $bot, Interaction $interaction): PromiseInterface
    {
        $guild = $interaction->guild;

        if (! $guild instanceof Guild || ! Permissions::mayConfigure($interaction, $guild)) {
            return $interaction->respondWithMessage(PanelBuilder::error('You need **Manage Server** to change the bridge.'), true);
        }

        $bot->getStore()->forgetGuild($guild->id);

        return $interaction->updateMessage(PanelBuilder::success('♻️ Cleared every bridge on this server.'));
    }

    /**
     * This guild's bridges, in the shape {@see PanelBuilder::links()} takes.
     *
     * @return list<array{channel_id: string, chat_id: string, title: ?string}>
     */
    private function rows(Relay $bot, Guild $guild): array
    {
        $rows = [];

        foreach ($bot->getStore()->links()->forGuild($guild->id) as $channelId => $chatId) {
            $rows[] = [
                'channel_id' => (string) $channelId,
                'chat_id' => (string) $chatId,
                'title' => $bot->getStore()->title($chatId),
            ];
        }

        return $rows;
    }

    /**
     * Warns now if the bot can't actually deliver into the channel, rather
     * than letting the first Telegram message disappear silently.
     */
    private function deliveryWarning(Guild $guild, string $channelId): ?string
    {
        $channel = $guild->channels->get('id', $channelId);
        if (! $channel instanceof Channel) {
            return null;
        }

        $perms = $channel->getBotPermissions();
        if ($perms === null || ($perms->administrator ?? false)) {
            return null;
        }

        $missing = [];
        foreach (['view_channel' => 'View Channel', 'send_messages' => 'Send Messages'] as $flag => $label) {
            if (! ($perms->{$flag} ?? false)) {
                $missing[] = $label;
            }
        }

        if ($missing !== []) {
            return "⚠️ I can't post there yet — grant me **" . implode('**, **', $missing) . '**.';
        }

        if (! ($perms->manage_webhooks ?? false)) {
            return 'ℹ️ Grant me **Manage Webhooks** there and Telegram senders will show up under their own names.';
        }

        return null;
    }
}
