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
use Discord\Parts\Guild\Guild;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\OAuth\Application;
use Discord\Repository\Interaction\GlobalCommandRepository;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

use Telegram\Parts\ChatFullInfo;
use TelegramRelay\Builders\PanelBuilder;
use TelegramRelay\Helpers\MessageText;
use TelegramRelay\Helpers\Permissions;
use TelegramRelay\Relay;

/**
 * `/tg` — the Telegram side's own features, driven from Discord.
 *
 * Everything here acts on **the chat the current channel is bridged to**, so
 * no command needs a chat id typed into it and none of them can reach a chat
 * this server has not linked. A channel with no bridge gets told to run
 * `/telegram link` rather than a Telegram error.
 *
 *   /tg send    text:…                 say something as the bot
 *   /tg photo   file:… caption:…       upload a photo into the chat
 *   /tg poll    question:… options:…   start a native Telegram poll
 *   /tg chat                           the chat's details, with buttons
 *   /tg pin     message_id:…           pin a message there
 *   /tg unpin   [message_id:…]        unpin one, or the latest
 *   /tg ban     user_id:… [minutes]    ban, or ban for a while
 *   /tg unban   user_id:…              lift a ban
 *
 * ## Who may run what
 *
 * `send`, `photo`, `poll` and `chat` are open to anyone who can use the
 * channel: they can already have the bridge carry their words simply by
 * typing, so gating the tidier route would be theatre. `pin`, `ban` and
 * `unban` act on the Telegram chat with the *bot's* rank rather than the
 * caller's, so they are gated on Manage Server like the wiring itself.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Controls implements Module
{
    use RoutesCommands;

    public const COMMAND = 'tg';

    /** Telegram allows 2-12 answers on a poll; the command takes them comma-separated. */
    public const POLL_MIN_OPTIONS = 2;

    public const POLL_MAX_OPTIONS = 12;

    public function name(): string
    {
        return 'controls';
    }

    public function boot(Relay $bot): void
    {
        $bot->application->commands->freshen()->then(fn (GlobalCommandRepository $repo) => $this->define($bot, $repo));

        $this->subCommand($bot, [self::COMMAND, 'send'], fn (Interaction $i, Guild $g, ExCollectionInterface $o) => $this->send($bot, $i, (string) self::option($o, 'text')), privileged: false);
        $this->subCommand($bot, [self::COMMAND, 'photo'], fn (Interaction $i, Guild $g, ExCollectionInterface $o) => $this->photo($bot, $i, $o), privileged: false);
        $this->subCommand($bot, [self::COMMAND, 'poll'], fn (Interaction $i, Guild $g, ExCollectionInterface $o) => $this->poll($bot, $i, $o), privileged: false);
        $this->subCommand($bot, [self::COMMAND, 'chat'], fn (Interaction $i) => $this->chat($bot, $i), privileged: false);
        $this->subCommand($bot, [self::COMMAND, 'pin'], fn (Interaction $i, Guild $g, ExCollectionInterface $o) => $this->pin($bot, $i, (int) self::option($o, 'message_id', 0)));
        $this->subCommand($bot, [self::COMMAND, 'ban'], fn (Interaction $i, Guild $g, ExCollectionInterface $o) => $this->ban($bot, $i, (int) self::option($o, 'user_id', 0), (int) self::option($o, 'minutes', 0)));
        $this->subCommand($bot, [self::COMMAND, 'unpin'], fn (Interaction $i, Guild $g, ExCollectionInterface $o) => $this->unpin($bot, $i, (int) self::option($o, 'message_id', 0)));
        $this->subCommand($bot, [self::COMMAND, 'unban'], fn (Interaction $i, Guild $g, ExCollectionInterface $o) => $this->unban($bot, $i, (int) self::option($o, 'user_id', 0)));

        $bot->components()
            ->on('chat', fn (Interaction $i, array $args) => $this->chat($bot, $i, $args[0] ?? null, update: true))
            ->on('members', fn (Interaction $i, array $args) => $this->members($bot, $i, $args[0] ?? null))
            ->on('invite', fn (Interaction $i, array $args) => $this->invite($bot, $i, $args[0] ?? null));
    }

    private function define(Relay $bot, GlobalCommandRepository $repo): void
    {
        if ($repo->get('name', self::COMMAND) !== null) {
            return;
        }

        $sub = static fn (string $name, string $desc): Option => (new Option($bot))
            ->setType(Option::SUB_COMMAND)->setName($name)->setDescription($desc);

        $option = static fn (int $type, string $name, string $desc, bool $required = true): Option => (new Option($bot))
            ->setType($type)->setName($name)->setDescription($desc)->setRequired($required);

        CommandBuilder::new()
            ->setType(Command::CHAT_INPUT)
            ->setName(self::COMMAND)
            ->setDescription('Act on the Telegram chat this channel is bridged to.')
            ->setContext([Interaction::CONTEXT_TYPE_GUILD])
            ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL)
            ->addOption($sub('send', 'Send a message to the Telegram chat.')
                ->addOption($option(Option::STRING, 'text', 'What to say.')))
            ->addOption($sub('photo', 'Send a photo to the Telegram chat.')
                ->addOption($option(Option::ATTACHMENT, 'file', 'The image to send.'))
                ->addOption($option(Option::STRING, 'caption', 'A caption for it.', false)))
            ->addOption($sub('poll', 'Start a poll in the Telegram chat.')
                ->addOption($option(Option::STRING, 'question', 'The question to ask.'))
                ->addOption($option(Option::STRING, 'options', 'The answers, separated by commas.'))
                ->addOption($option(Option::BOOLEAN, 'multiple', 'Allow more than one answer.', false))
                ->addOption($option(Option::BOOLEAN, 'anonymous', 'Hide who voted for what. Default: yes.', false)))
            ->addOption($sub('chat', 'Show the Telegram chat this channel is bridged to.'))
            ->addOption($sub('pin', 'Pin a message in the Telegram chat. Manage Server only.')
                ->addOption($option(Option::INTEGER, 'message_id', 'The Telegram message id to pin.')))
            ->addOption($sub('unpin', 'Unpin a message, or the most recent pin. Manage Server only.')
                ->addOption($option(Option::INTEGER, 'message_id', 'The message to unpin. Leave out for the most recent.', false)))
            ->addOption($sub('ban', 'Ban someone from the Telegram chat. Manage Server only.')
                ->addOption($option(Option::INTEGER, 'user_id', 'Their numeric Telegram user id.'))
                ->addOption($option(Option::INTEGER, 'minutes', 'Ban for this long, then lift it automatically.', false)))
            ->addOption($sub('unban', 'Lift a Telegram ban. Manage Server only.')
                ->addOption($option(Option::INTEGER, 'user_id', 'Their numeric Telegram user id.')))
            ->create($repo)
            ->save(self::COMMAND . ' command');
    }

    /** `/tg send` — the relay in one direction, deliberately. */
    private function send(Relay $bot, Interaction $interaction, string $text): PromiseInterface
    {
        return $this->withChat($bot, $interaction, function (string $chatId) use ($bot, $interaction, $text) {
            $author = $interaction->member?->nick
                ?? $interaction->user?->global_name
                ?? $interaction->user?->username
                ?? 'someone';

            $html = MessageText::forTelegram($text, (string) $author);

            if ($html === null) {
                return $interaction->respondWithMessage(PanelBuilder::error('There was nothing to send.'), true);
            }

            return $bot->gateway()->send($chatId, $html)->then(
                fn () => $interaction->respondWithMessage(
                    PanelBuilder::success(sprintf('✅ Sent to **%s**.', MessageText::escapeMarkdown($this->titleOf($bot, $chatId)))),
                    true,
                ),
                fn (\Throwable $e) => $interaction->respondWithMessage($this->telegramError($e), true),
            );
        });
    }

    /**
     * `/tg photo` — Discord resolves the upload and gives us its CDN URL,
     * which Telegram then fetches for itself, so the bytes never pass through
     * this process.
     */
    private function photo(Relay $bot, Interaction $interaction, ExCollectionInterface $options): PromiseInterface
    {
        return $this->withChat($bot, $interaction, function (string $chatId) use ($bot, $interaction, $options) {
            $attachment = $interaction->data->resolved?->attachments?->get('id', (string) self::option($options, 'file'));
            $url = (string) ($attachment->url ?? '');

            if ($url === '') {
                return $interaction->respondWithMessage(PanelBuilder::error('I could not read that attachment.'), true);
            }

            $author = $interaction->member?->nick ?? $interaction->user?->global_name ?? $interaction->user?->username ?? 'someone';
            $caption = MessageText::forTelegram(
                (string) (self::option($options, 'caption') ?? ''),
                (string) $author,
                limit: MessageText::TELEGRAM_CAPTION_LIMIT,
            );

            return $bot->gateway()->sendPhoto($chatId, $url, $caption)->then(
                fn () => $interaction->respondWithMessage(
                    PanelBuilder::success(sprintf('✅ Sent to **%s**.', MessageText::escapeMarkdown($this->titleOf($bot, $chatId)))),
                    true,
                ),
                fn (\Throwable $e) => $interaction->respondWithMessage($this->telegramError($e), true),
            );
        });
    }

    /**
     * `/tg poll` — a real Telegram poll, not a relayed message.
     *
     * Telegram's own poll is worth reaching for rather than posting a message
     * with numbered lines: it counts votes, it shows results in the chat, and
     * it is the one thing here with no Discord equivalent to relay.
     */
    private function poll(Relay $bot, Interaction $interaction, ExCollectionInterface $options): PromiseInterface
    {
        return $this->withChat($bot, $interaction, function (string $chatId) use ($bot, $interaction, $options) {
            $question = trim((string) self::option($options, 'question'));
            $answers = self::splitPollOptions((string) self::option($options, 'options'));

            if (count($answers) < self::POLL_MIN_OPTIONS) {
                return $interaction->respondWithMessage(
                    PanelBuilder::error(sprintf(
                        'A poll needs at least %d answers. Separate them with commas: `options: yes, no, maybe`.',
                        self::POLL_MIN_OPTIONS,
                    )),
                    true,
                );
            }

            if (count($answers) > self::POLL_MAX_OPTIONS) {
                return $interaction->respondWithMessage(
                    PanelBuilder::error(sprintf('Telegram allows at most %d answers; that had %d.', self::POLL_MAX_OPTIONS, count($answers))),
                    true,
                );
            }

            return $bot->getTelegram()->sendPoll(
                $chatId,
                MessageText::truncate($question, 300),
                array_map(static fn (string $answer): array => ['text' => MessageText::truncate($answer, 100)], $answers),
                is_anonymous: (bool) (self::option($options, 'anonymous') ?? true),
                allows_multiple_answers: (bool) (self::option($options, 'multiple') ?? false),
            )->then(
                fn () => $interaction->respondWithMessage(
                    PanelBuilder::success(sprintf(
                        "📊 Poll started in **%s**.\n> %s",
                        MessageText::escapeMarkdown($this->titleOf($bot, $chatId)),
                        MessageText::escapeMarkdown($question),
                    )),
                    true,
                ),
                fn (\Throwable $e) => $interaction->respondWithMessage($this->telegramError($e), true),
            );
        });
    }

    /**
     * `/tg chat`, and the Refresh button on the panel it produces.
     *
     * The button carries the chat id, so a panel still works after the channel
     * it was posted in has been re-linked somewhere else — it refreshes what
     * it was showing, not whatever the channel points at now.
     */
    private function chat(Relay $bot, Interaction $interaction, ?string $chatId = null, bool $update = false): PromiseInterface
    {
        $respond = fn (PanelBuilder $panel): PromiseInterface => $update
            ? $interaction->updateMessage($panel)
            : $interaction->respondWithMessage($panel, true);

        return $this->withChat($bot, $interaction, fn (string $chat) => $bot->getTelegram()->getChat($chat)->then(
            function (ChatFullInfo $info) use ($bot, $respond) {
                $chatId = (string) $info->id;

                return $bot->getTelegram()->getChatMemberCount($chatId)->then(
                    fn (int $members) => $respond($this->chatPanel($bot, $info, $members)),
                    fn () => $respond($this->chatPanel($bot, $info, null)),
                );
            },
            fn (\Throwable $e) => $respond($this->telegramError($e)),
        ), $chatId);
    }

    /** The Member count button. */
    private function members(Relay $bot, Interaction $interaction, ?string $chatId): PromiseInterface
    {
        return $this->withChat($bot, $interaction, fn (string $chat) => $bot->getTelegram()->getChatMemberCount($chat)->then(
            fn (int $count) => $interaction->respondWithMessage(
                PanelBuilder::notice(sprintf('👥 **%s** member%s in **%s**.', number_format($count), $count === 1 ? '' : 's', MessageText::escapeMarkdown($this->titleOf($bot, $chat)))),
                true,
            ),
            fn (\Throwable $e) => $interaction->respondWithMessage($this->telegramError($e), true),
        ), $chatId);
    }

    /**
     * The Invite link button.
     *
     * Ephemeral, always: `exportChatInviteLink` *revokes the previous link*
     * and mints a new one, so the answer is both a working invite to a private
     * group and the reason the last one stopped working. It is not something
     * to leave sitting in a channel.
     */
    private function invite(Relay $bot, Interaction $interaction, ?string $chatId): PromiseInterface
    {
        $guild = $interaction->guild;

        if (! $guild instanceof Guild || ! Permissions::mayConfigure($interaction, $guild)) {
            return $interaction->respondWithMessage(PanelBuilder::error('You need **Manage Server** to mint an invite link.'), true);
        }

        return $this->withChat($bot, $interaction, fn (string $chat) => $bot->getTelegram()->exportChatInviteLink($chat)->then(
            fn (string $link) => $interaction->respondWithMessage(
                PanelBuilder::warning(sprintf(
                    "🔗 %s\n-# This **replaced** the chat's previous invite link, which no longer works.",
                    $link,
                )),
                true,
            ),
            fn (\Throwable $e) => $interaction->respondWithMessage($this->telegramError($e), true),
        ), $chatId);
    }

    /** `/tg pin`. */
    private function pin(Relay $bot, Interaction $interaction, int $messageId): PromiseInterface
    {
        if ($messageId <= 0) {
            return $interaction->respondWithMessage(PanelBuilder::error('That is not a Telegram message id.'), true);
        }

        return $this->withChat($bot, $interaction, fn (string $chatId) => $bot->getTelegram()->pinChatMessage($chatId, $messageId)->then(
            fn () => $interaction->respondWithMessage(PanelBuilder::success(sprintf('📌 Pinned message `%d`.', $messageId)), true),
            fn (\Throwable $e) => $interaction->respondWithMessage($this->telegramError($e), true),
        ));
    }

    /**
     * `/tg unpin`.
     *
     * With no id Telegram unpins the most recent pin, which is the common
     * case and the only one someone can act on without reading an id out of a
     * relayed message first.
     */
    private function unpin(Relay $bot, Interaction $interaction, int $messageId): PromiseInterface
    {
        return $this->withChat($bot, $interaction, fn (string $chatId) => $bot->getTelegram()->unpinChatMessage(
            $chatId,
            message_id: $messageId > 0 ? $messageId : null,
        )->then(
            fn () => $interaction->respondWithMessage(
                PanelBuilder::success($messageId > 0
                    ? sprintf('📌 Unpinned message `%d`.', $messageId)
                    : '📌 Unpinned the most recent pin.'),
                true,
            ),
            fn (\Throwable $e) => $interaction->respondWithMessage($this->telegramError($e), true),
        ));
    }

    /** `/tg ban`, optionally for a while. */
    private function ban(Relay $bot, Interaction $interaction, int $userId, int $minutes): PromiseInterface
    {
        if ($userId <= 0) {
            return $interaction->respondWithMessage(
                PanelBuilder::error("That is not a Telegram user id.\n-# Telegram ids are numbers, not `@names`; the bridge shows one in the relayed message's author."),
                true,
            );
        }

        // Telegram treats "less than 30 seconds or more than 366 days from
        // now" as a permanent ban, so 0 is passed through as exactly that.
        $until = $minutes > 0 ? time() + ($minutes * 60) : null;

        return $this->withChat($bot, $interaction, fn (string $chatId) => $bot->getTelegram()->banChatMember($chatId, $userId, until_date: $until)->then(
            fn () => $interaction->respondWithMessage(
                PanelBuilder::success($minutes > 0
                    ? sprintf('🔨 Banned `%d` for %d minute%s.', $userId, $minutes, $minutes === 1 ? '' : 's')
                    : sprintf('🔨 Banned `%d`.', $userId)),
                true,
            ),
            fn (\Throwable $e) => $interaction->respondWithMessage($this->telegramError($e), true),
        ));
    }

    /** `/tg unban`. */
    private function unban(Relay $bot, Interaction $interaction, int $userId): PromiseInterface
    {
        if ($userId <= 0) {
            return $interaction->respondWithMessage(PanelBuilder::error('That is not a Telegram user id.'), true);
        }

        return $this->withChat($bot, $interaction, fn (string $chatId) => $bot->getTelegram()->unbanChatMember($chatId, $userId, only_if_banned: true)->then(
            fn () => $interaction->respondWithMessage(PanelBuilder::success(sprintf('🕊️ Lifted the ban on `%d`.', $userId)), true),
            fn (\Throwable $e) => $interaction->respondWithMessage($this->telegramError($e), true),
        ));
    }

    /**
     * Resolves which Telegram chat a command is about, and refuses politely
     * when there is not one.
     *
     * `$explicit` is the chat id carried by a button. Everything else goes by
     * the channel the command was used in, which is what makes `/tg send` safe
     * to leave open: it can only ever reach a chat someone with Manage Server
     * already bridged to that channel.
     *
     * @param callable(string): PromiseInterface $then
     */
    private function withChat(Relay $bot, Interaction $interaction, callable $then, ?string $explicit = null): PromiseInterface
    {
        if (! $bot->telegramIsUp()) {
            return $interaction->respondWithMessage(
                PanelBuilder::error('The Telegram side of the bridge is not connected. Try `/telegram status`.'),
                true,
            );
        }

        $chatId = $explicit ?? $bot->getStore()->links()->telegramFor((string) $interaction->channel_id);

        if ($chatId === null) {
            return $interaction->respondWithMessage(
                PanelBuilder::warning(
                    "This channel isn't bridged to a Telegram chat.\n"
                    . 'Someone with **Manage Server** can link it with `/telegram here chat:…`.',
                ),
                true,
            );
        }

        return resolve($then($chatId));
    }

    /** The panel {@see chat()} and its Refresh button both render. */
    private function chatPanel(Relay $bot, ChatFullInfo $info, ?int $members): PanelBuilder
    {
        $chatId = (string) $info->id;

        return PanelBuilder::chat([
            'title' => (string) ($info->title ?? $info->username ?? $info->first_name ?? $chatId),
            'type' => (string) ($info->type ?? 'chat'),
            'chat_id' => $chatId,
            'members' => $members,
            'description' => $info->description ?? $info->bio ?? null,
            'username' => $info->username ?? null,
            'linked_channels' => $bot->getStore()->links()->discordFor($chatId),
        ]);
    }

    /** The last title the bridge saw for a chat, for a confirmation line. */
    private function titleOf(Relay $bot, string $chatId): string
    {
        return $bot->getStore()->title($chatId) ?? $chatId;
    }

    /**
     * Turns a rejected Telegram call into a panel.
     *
     * Telegram's own description is included because it is usually the
     * actionable part — "not enough rights to pin a message" tells an operator
     * exactly what to change, and paraphrasing it would not.
     */
    private function telegramError(\Throwable $e): PanelBuilder
    {
        return PanelBuilder::error(sprintf(
            "Telegram refused that.\n-# `%s`",
            MessageText::escapeMarkdown(MessageText::truncate($e->getMessage(), 300)),
        ));
    }

    /**
     * Splits a comma-separated answer list, dropping the empties someone's
     * trailing comma leaves behind.
     *
     * @return list<string>
     */
    public static function splitPollOptions(string $input): array
    {
        $answers = [];

        foreach (explode(',', $input) as $answer) {
            $answer = trim($answer);

            if ($answer !== '') {
                $answers[] = $answer;
            }
        }

        return $answers;
    }
}
