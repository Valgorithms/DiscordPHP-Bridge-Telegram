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

namespace Bridge\Telegram\Actions;

use Bridge\Builders\PanelBuilder;
use Bridge\Capability\ProvidesActions;
use Bridge\Command\Access;
use Bridge\Command\Action;
use Bridge\Command\ActionError;
use Bridge\Command\Arguments;
use Bridge\Command\Context;
use Bridge\Command\Slash;
use Bridge\Command\SlashOption;
use Bridge\Room;
use Bridge\Support\MessageText;
use Bridge\Telegram\TelegramText;
use Discord\Parts\Interactions\Interaction;
use React\Promise\PromiseInterface;

/**
 * What the bridge can do *to* a Telegram chat, beyond relaying into it.
 *
 * These were `/tg` when Telegram was the only network this bot knew. They are
 * `/telegram chat …` and `/telegram mod …` now, which is not decoration: `send`
 * and `ban` are words another connector will want, and a bare `/tg` alongside
 * `/twitch` invites the question of which one `pin` belongs to.
 *
 * All of them act on the chat the invoking Discord channel is bridged to —
 * {@see Context::requireTarget()} resolves that — which is what makes the
 * unprivileged ones safe to leave open: they can only ever reach a chat someone
 * with Manage Server already linked to that channel.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class ControlActions implements ProvidesActions
{
    use UsesTelegram;

    /** Telegram's own bounds on a poll. */
    private const POLL_MIN_OPTIONS = 2;

    private const POLL_MAX_OPTIONS = 10;

    private const POLL_QUESTION_LIMIT = 300;

    private const POLL_ANSWER_LIMIT = 100;

    /** @return list<Action> */
    public function actions(): array
    {
        return [
            new Action(
                'telegram',
                'send',
                $this->send(...),
                'Say something in the bridged Telegram chat',
                '<text>',
                group: 'chat',
                slash: new Slash([
                    new SlashOption('text', 'What to say.', SlashOption::STRING, true),
                ], ephemeral: true),
            ),
            new Action(
                'telegram',
                'photo',
                $this->photo(...),
                'Send a photo to the bridged Telegram chat',
                group: 'chat',
                // A Discord attachment picker has no meaning anywhere else.
                only: 'discord',
                slash: new Slash([
                    new SlashOption('file', 'The image to send.', SlashOption::ATTACHMENT, true),
                    new SlashOption('caption', 'A caption for it.'),
                ], ephemeral: true),
            ),
            new Action(
                'telegram',
                'poll',
                $this->poll(...),
                'Start a real Telegram poll',
                '<question> | <answer, answer, ...>',
                group: 'chat',
                slash: new Slash([
                    new SlashOption('question', 'The question to ask.', SlashOption::STRING, true),
                    new SlashOption('options', 'The answers, separated by commas.', SlashOption::STRING, true),
                    new SlashOption('multiple', 'Allow more than one answer.', SlashOption::BOOLEAN),
                    new SlashOption('anonymous', 'Hide who voted for what. Default: yes.', SlashOption::BOOLEAN),
                ], ephemeral: true),
            ),
            new Action(
                'telegram',
                'info',
                $this->info(...),
                'Show the Telegram chat this channel is bridged to',
                group: 'chat',
                // Answers with a Components v2 panel, which only Discord renders.
                only: 'discord',
                slash: new Slash(ephemeral: true),
            ),
            new Action(
                'telegram',
                'pin',
                $this->pin(...),
                'Pin a message in the Telegram chat',
                '<message-id>',
                access: Access::Administrator,
                group: 'chat',
                slash: new Slash([
                    new SlashOption('message_id', 'The Telegram message id to pin.', SlashOption::INTEGER, true),
                ], ephemeral: true),
            ),
            new Action(
                'telegram',
                'unpin',
                $this->unpin(...),
                'Unpin a message, or the most recent pin',
                '[message-id]',
                access: Access::Administrator,
                group: 'chat',
                slash: new Slash([
                    new SlashOption('message_id', 'The message to unpin. Leave out for the most recent.', SlashOption::INTEGER),
                ], ephemeral: true),
            ),
            new Action(
                'telegram',
                'ban',
                $this->ban(...),
                'Ban someone from the Telegram chat',
                '<user-id> [minutes]',
                access: Access::Administrator,
                group: 'mod',
                slash: new Slash([
                    new SlashOption('user_id', 'Their numeric Telegram user id.', SlashOption::INTEGER, true),
                    new SlashOption('minutes', 'Ban for this long, then lift it automatically.', SlashOption::INTEGER),
                ], ephemeral: true),
            ),
            new Action(
                'telegram',
                'unban',
                $this->unban(...),
                'Lift a Telegram ban',
                '<user-id>',
                access: Access::Administrator,
                group: 'mod',
                slash: new Slash([
                    new SlashOption('user_id', 'Their numeric Telegram user id.', SlashOption::INTEGER, true),
                ], ephemeral: true),
            ),
        ];
    }

    // ── Saying things ──────────────────────────────────────────────────

    /**
     * The relay in one direction, deliberately: whatever is typed here is said
     * in the chat under the speaker's name, exactly as a relayed message would
     * be.
     */
    private function send(Context $context, Arguments $arguments): PromiseInterface
    {
        $chatId = $context->requireTarget();
        $text = trim((string) ($arguments->named('text') ?? $arguments->rest()));

        if ($text === '') {
            throw new ActionError('there was nothing to send.');
        }

        $html = sprintf(
            '<b>%s</b>: %s',
            TelegramText::escapeHtml($context->invokerName),
            TelegramText::escapeHtml(MessageText::truncate($text, TelegramText::LIMIT - 200)),
        );

        return $this->telegram($context)->send($chatId, $html, ['html' => true])->then(
            fn (): string => sprintf('✅ Sent to **%s**.', $this->titleOf($context, $chatId)),
            $this->explain(...),
        );
    }

    /**
     * Discord resolves the upload and gives us its CDN URL, which Telegram then
     * fetches for itself — so the bytes never pass through this process.
     */
    private function photo(Context $context, Arguments $arguments): PromiseInterface
    {
        $chatId = $context->requireTarget();
        $url = $this->attachmentUrl($context, (string) ($arguments->named('file') ?? ''));

        $caption = trim((string) ($arguments->named('caption') ?? ''));
        $caption = $caption === '' ? null : sprintf(
            '<b>%s</b>: %s',
            TelegramText::escapeHtml($context->invokerName),
            TelegramText::escapeHtml(MessageText::truncate($caption, TelegramText::CAPTION_LIMIT - 200)),
        );

        return $this->telegram($context)->getGateway()->sendPhoto($chatId, $url, $caption)->then(
            fn (): string => sprintf('✅ Sent to **%s**.', $this->titleOf($context, $chatId)),
            $this->explain(...),
        );
    }

    /**
     * A real Telegram poll, not a relayed message with numbered lines.
     *
     * Worth reaching for: Telegram counts the votes, shows the results in the
     * chat, and it is the one thing here with no Discord equivalent to relay.
     */
    private function poll(Context $context, Arguments $arguments): PromiseInterface
    {
        $chatId = $context->requireTarget();
        $question = trim((string) ($arguments->named('question') ?? $arguments->get(0, '')));
        $answers = self::splitPollOptions((string) ($arguments->named('options') ?? $arguments->rest()));

        if ($question === '') {
            throw new ActionError('a poll needs a question.');
        }

        if (count($answers) < self::POLL_MIN_OPTIONS) {
            throw new ActionError(sprintf(
                'a poll needs at least %d answers. Separate them with commas: `options: yes, no, maybe`.',
                self::POLL_MIN_OPTIONS,
            ));
        }

        if (count($answers) > self::POLL_MAX_OPTIONS) {
            throw new ActionError(sprintf(
                'Telegram allows at most %d answers; that had %d.',
                self::POLL_MAX_OPTIONS,
                count($answers),
            ));
        }

        return $this->telegram($context)->getTelegram()->sendPoll(
            $chatId,
            MessageText::truncate($question, self::POLL_QUESTION_LIMIT),
            array_map(
                static fn (string $answer): array => ['text' => MessageText::truncate($answer, self::POLL_ANSWER_LIMIT)],
                $answers,
            ),
            is_anonymous: $arguments->named('anonymous') !== 'false',
            allows_multiple_answers: $arguments->named('multiple') === 'true',
        )->then(
            fn (): string => sprintf(
                "📊 Poll started in **%s**.\n> %s",
                $this->titleOf($context, $chatId),
                MessageText::escapeMarkdown($question),
            ),
            $this->explain(...),
        );
    }

    /** The panel: what the chat is, how many are in it, and the buttons. */
    private function info(Context $context): PromiseInterface
    {
        $chatId = $context->requireTarget();
        $connector = $this->telegram($context);

        return $connector->resolve($chatId)->then(function (?Room $room) use ($context, $chatId): PanelBuilder {
            if ($room === null) {
                throw new ActionError(
                    'I cannot see that chat any more. It may have been deleted, or I may have been '
                    . 'removed from it — `/telegram status` will say.',
                );
            }

            return PanelBuilder::room(
                $room,
                $context->bot->getStore()->links('telegram')->discordFor($chatId),
            );
        });
    }

    // ── Moderation ─────────────────────────────────────────────────────

    private function pin(Context $context, Arguments $arguments): PromiseInterface
    {
        $chatId = $context->requireTarget();
        $messageId = $this->messageId($arguments, required: true);

        return $this->telegram($context)->getTelegram()->pinChatMessage($chatId, $messageId)->then(
            static fn (): string => sprintf('📌 Pinned message `%d`.', $messageId),
            $this->explain(...),
        );
    }

    /**
     * With no id Telegram unpins the most recent pin, which is the common case
     * and the only one somebody can act on without first reading an id out of a
     * relayed message.
     */
    private function unpin(Context $context, Arguments $arguments): PromiseInterface
    {
        $chatId = $context->requireTarget();
        $messageId = $this->messageId($arguments, required: false);

        return $this->telegram($context)->getTelegram()->unpinChatMessage(
            $chatId,
            message_id: $messageId > 0 ? $messageId : null,
        )->then(
            static fn (): string => $messageId > 0
                ? sprintf('📌 Unpinned message `%d`.', $messageId)
                : '📌 Unpinned the most recent pin.',
            $this->explain(...),
        );
    }

    private function ban(Context $context, Arguments $arguments): PromiseInterface
    {
        $chatId = $context->requireTarget();
        $userId = $this->userId($arguments);
        $minutes = (int) ($arguments->named('minutes') ?? $arguments->get(1, '0'));

        // Telegram treats "less than 30 seconds or more than 366 days from now"
        // as a permanent ban, so 0 is passed through as exactly that.
        $until = $minutes > 0 ? time() + ($minutes * 60) : null;

        return $this->telegram($context)->getTelegram()->banChatMember($chatId, $userId, until_date: $until)->then(
            static fn (): string => $minutes > 0
                ? sprintf('🔨 Banned `%d` for %d minute%s.', $userId, $minutes, $minutes === 1 ? '' : 's')
                : sprintf('🔨 Banned `%d`.', $userId),
            $this->explain(...),
        );
    }

    private function unban(Context $context, Arguments $arguments): PromiseInterface
    {
        $chatId = $context->requireTarget();
        $userId = $this->userId($arguments);

        return $this->telegram($context)->getTelegram()->unbanChatMember($chatId, $userId, only_if_banned: true)->then(
            static fn (): string => sprintf('🕊️ Lifted the ban on `%d`.', $userId),
            $this->explain(...),
        );
    }

    // ── Shared ─────────────────────────────────────────────────────────

    /**
     * Splits `a, b, c` into answers, dropping the empties somebody's trailing
     * comma leaves behind.
     *
     * @return list<string>
     */
    public static function splitPollOptions(string $raw): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\s*,\s*/', $raw) ?: []),
            static fn (string $answer): bool => $answer !== '',
        ));
    }

    private function messageId(Arguments $arguments, bool $required): int
    {
        $id = (int) ($arguments->named('message_id') ?? $arguments->get(0, '0'));

        if ($required && $id <= 0) {
            throw new ActionError('that is not a Telegram message id.');
        }

        return $id;
    }

    private function userId(Arguments $arguments): int
    {
        $id = (int) ($arguments->named('user_id') ?? $arguments->get(0, '0'));

        if ($id <= 0) {
            throw new ActionError(
                "that is not a Telegram user id.\n"
                . "-# Telegram ids are numbers, not `@names`; the bridge shows one in a relayed message's author.",
            );
        }

        return $id;
    }

    /** What a bridged chat is called, for a confirmation that reads like one. */
    private function titleOf(Context $context, string $chatId): string
    {
        return MessageText::escapeMarkdown(
            $context->bot->getStore()->label('telegram', $chatId) ?? $chatId,
        );
    }

    /**
     * The URL Discord resolved for an attachment option.
     *
     * The option arrives as an attachment *id*; the file itself is in the
     * interaction's resolved data, which is the only place it exists.
     */
    private function attachmentUrl(Context $context, string $id): string
    {
        $interaction = $context->message;

        if (! $interaction instanceof Interaction || $id === '') {
            throw new ActionError('I could not read that attachment.');
        }

        $attachment = $interaction->data->resolved?->attachments?->get('id', $id);
        $url = (string) ($attachment->url ?? '');

        if ($url === '') {
            throw new ActionError('I could not read that attachment.');
        }

        return $url;
    }

    /**
     * Turns a Telegram failure into something worth reading.
     *
     * Never re-uses the exception's own message unexamined: a Telegram error
     * can quote the request URL, and a Telegram URL carries the bot token.
     */
    private function explain(\Throwable $e): never
    {
        $reason = trim(preg_replace('#https?://\S+#', '(url)', $e->getMessage()) ?? '');

        throw new ActionError(match (true) {
            str_contains($reason, 'not enough rights'),
            str_contains($reason, 'CHAT_ADMIN_REQUIRED') => 'Telegram refused: the bot is not an administrator of that chat.',
            str_contains($reason, 'chat not found') => 'Telegram does not know that chat any more.',
            str_contains($reason, 'user not found') => 'Telegram does not know that user.',
            $reason === '' => 'Telegram refused that, without saying why.',
            default => 'Telegram refused that: ' . MessageText::truncate($reason, 200),
        });
    }
}
