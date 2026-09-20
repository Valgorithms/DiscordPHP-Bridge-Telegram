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

namespace TelegramRelay\Builders;

use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\ComponentObject;
use Discord\Builders\Components\Container;
use Discord\Builders\Components\Section;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\MessageBuilder;
use TelegramRelay\Helpers\ComponentRouter;
use TelegramRelay\Helpers\MessageText;

/**
 * A {@see MessageBuilder} that is a Components v2 panel: one accented
 * {@see Container} that everything else is added to.
 *
 * It extends `MessageBuilder` rather than wrapping it so a panel *is* a
 * message builder — anything that takes one (`respondWithMessage()`,
 * `updateMessage()`, `sendMessage()`, `Webhook::execute()`) takes a panel,
 * with no unwrapping step and nothing to keep in sync as DiscordPHP's builder
 * grows. The named constructors follow the same shape as
 * {@see Button::primary()}: a static per variant, then fluent methods.
 *
 * Components v2 rather than embeds because these panels are not decorated
 * text: a bridge list needs a button *per row* to unlink that row, which is
 * exactly what a {@see Section} accessory is for and which an embed cannot
 * express at all. Adding a v2 component sets the `IS_COMPONENTS_V2` flag,
 * which forbids `content` on the same message — so everything a panel says
 * lives in a {@see TextDisplay}.
 *
 * Every panel also sets `allowed_mentions: none`. Panels quote
 * Telegram-supplied text — a chat title, a user's name — and none of it should
 * be able to ping a Discord server.
 *
 * @since 1.0.0
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
class PanelBuilder extends MessageBuilder
{
    /** Telegram's blue, for ordinary panels. */
    public const ACCENT = 0x2AABEE;

    public const SUCCESS = 0x57F287;

    public const DANGER = 0xED4245;

    public const WARNING = 0xFEE75C;

    /**
     * Discord allows 40 components in one v2 message, and each row costs a
     * Section plus its text and its button. Ten rows leaves ample room for the
     * heading, the separator and the footer.
     */
    public const MAX_ROWS = 10;

    /** The container every other component is added to. */
    protected Container $container;

    /**
     * Creates an empty panel.
     *
     * @param int $accent Container accent colour; one of the constants above.
     */
    public static function new(int $accent = self::ACCENT): static
    {
        $panel = new static();
        $panel->container = Container::new()->setAccentColor($accent);

        $panel->setAllowedMentions(['parse' => []]);
        $panel->addComponent($panel->container);

        return $panel;
    }

    /** A panel that is one block of markdown. */
    public static function notice(string $markdown, int $accent = self::ACCENT): static
    {
        return static::new($accent)->addText($markdown);
    }

    public static function success(string $markdown): static
    {
        return static::notice($markdown, self::SUCCESS);
    }

    public static function error(string $markdown): static
    {
        return static::notice($markdown, self::DANGER);
    }

    public static function warning(string $markdown): static
    {
        return static::notice($markdown, self::WARNING);
    }

    /**
     * This server's bridges, one row per link, each with its own Unlink
     * button — the reason these panels are v2 and not embeds.
     *
     * @param list<array{channel_id: string, chat_id: string, title: ?string}> $rows
     */
    public static function links(array $rows): static
    {
        if ($rows === []) {
            return static::notice(
                "**No channels are bridged yet.**\n"
                . 'Use `/telegram link channel:#general chat:-1001234567890` to start one, '
                . 'or `/telegram here` in the channel you want bridged.',
            );
        }

        $panel = static::new()
            ->addText('## Bridged channels')
            ->addSeparator();

        foreach (array_slice($rows, 0, self::MAX_ROWS) as $row) {
            $panel->addRow(
                sprintf(
                    "<#%s> ⇄ **%s**\n-# `%s`",
                    $row['channel_id'],
                    MessageText::escapeMarkdown($row['title'] ?? $row['chat_id']),
                    $row['chat_id'],
                ),
                Button::danger(ComponentRouter::id('unlink', $row['channel_id']))->setLabel('Unlink'),
            );
        }

        $hidden = count($rows) - self::MAX_ROWS;
        if ($hidden > 0) {
            $panel->addText(sprintf('-# …and %d more.', $hidden));
        }

        return $panel;
    }

    /**
     * The panel for one Telegram chat: what the bot can see about it, and the
     * things it can do to it from here.
     *
     * There is deliberately no thumbnail. A chat's photo is only reachable
     * through a URL containing the bot token, which must never be posted into
     * Discord — see {@see \TelegramRelay\Helpers\Media}.
     *
     * @param array{title: string, type: string, chat_id: string, members?: ?int, description?: ?string, username?: ?string, linked_channels?: list<string>} $info
     */
    public static function chat(array $info): static
    {
        $lines = [
            '## ' . MessageText::escapeMarkdown($info['title']),
            sprintf('-# %s · `%s`', MessageText::escapeMarkdown($info['type']), $info['chat_id']),
        ];

        if (($info['username'] ?? null) !== null && $info['username'] !== '') {
            $lines[] = sprintf('🔗 https://t.me/%s', ltrim((string) $info['username'], '@'));
        }

        if (($info['members'] ?? null) !== null) {
            $members = (int) $info['members'];
            $lines[] = sprintf('👥 **%s** member%s', number_format($members), $members === 1 ? '' : 's');
        }

        if (($info['description'] ?? null) !== null && $info['description'] !== '') {
            $lines[] = '';
            $lines[] = '> ' . str_replace("\n", "\n> ", MessageText::escapeMarkdown(MessageText::truncate((string) $info['description'], 400)));
        }

        $bridged = $info['linked_channels'] ?? [];
        $lines[] = '';
        $lines[] = $bridged === []
            ? '-# Not bridged to any channel here.'
            : '-# Bridged to ' . implode(', ', array_map(static fn (string $id): string => '<#' . $id . '>', $bridged));

        $chatId = $info['chat_id'];

        return static::new()
            ->addText(implode("\n", $lines))
            ->addSeparator()
            ->addActions(
                Button::secondary(ComponentRouter::id('chat', $chatId))->setLabel('Refresh')->setEmoji('🔄'),
                Button::secondary(ComponentRouter::id('invite', $chatId))->setLabel('Invite link')->setEmoji('🔗'),
                Button::secondary(ComponentRouter::id('members', $chatId))->setLabel('Member count')->setEmoji('👥'),
            );
    }

    /**
     * A destructive action behind a second press.
     *
     * `/telegram reset` drops every bridge in the server, which is exactly the
     * kind of thing someone fires while meaning `/telegram list`.
     */
    public static function confirm(string $markdown, string $confirmCustomId, string $confirmLabel = 'Yes, do it'): static
    {
        return static::new(self::DANGER)
            ->addText($markdown)
            ->addActions(
                Button::danger($confirmCustomId)->setLabel($confirmLabel),
                Button::secondary(ComponentRouter::id('dismiss'))->setLabel('Cancel'),
            );
    }

    /** Adds a block of markdown. */
    public function addText(string $markdown): static
    {
        $this->container->addComponent(TextDisplay::new($markdown));

        return $this;
    }

    /** Adds a divider. */
    public function addSeparator(bool $divider = true): static
    {
        $this->container->addComponent(Separator::new()->setDivider($divider));

        return $this;
    }

    /** Adds a line of text with a component — usually a button — beside it. */
    public function addRow(string $markdown, ComponentObject $accessory): static
    {
        $this->container->addComponent(
            Section::new()
                ->addComponent(TextDisplay::new($markdown))
                ->setAccessory($accessory),
        );

        return $this;
    }

    /** Adds a row of buttons. */
    public function addActions(Button ...$buttons): static
    {
        $row = ActionRow::new();

        foreach ($buttons as $button) {
            $row->addComponent($button);
        }

        $this->container->addComponent($row);

        return $this;
    }

    /** Recolours the container after the fact. */
    public function setAccentColor(int $accent): static
    {
        $this->container->setAccentColor($accent);

        return $this;
    }

    /** The container, for anything these helpers do not cover. */
    public function getContainer(): Container
    {
        return $this->container;
    }
}
