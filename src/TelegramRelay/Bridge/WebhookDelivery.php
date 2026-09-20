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

namespace TelegramRelay\Bridge;

use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Channel\Webhook;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Posts Telegram messages into Discord.
 *
 * Uses a webhook so each relayed line carries the Telegram user's own name and
 * avatar instead of arriving as a wall of identical bot messages. That is not
 * only cosmetic: webhook messages carry a `webhook_id`, which is how the
 * bridge recognises its own output and refuses to relay it back to Telegram.
 *
 * Webhooks need **Manage Webhooks**. When that is missing — or creation fails
 * for any other reason — delivery falls back to an ordinary bot message with
 * the author's name inline, so a misconfigured server degrades to an uglier
 * bridge rather than a silent one.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class WebhookDelivery
{
    public const WEBHOOK_NAME = 'TelegramRelay';

    /** Discord rejects a webhook username longer than this. */
    public const USERNAME_LIMIT = 80;

    /** @var array<string, Webhook|false> Channel id => webhook, or false when we know we can't have one. */
    private array $cache = [];

    public function __construct(private readonly Discord $discord)
    {
    }

    /**
     * Delivers one Telegram message to one Discord channel.
     *
     * `allowed_mentions` is empty on every path: Telegram chat is untrusted
     * input, and someone typing `@everyone` in a group must not ping a Discord
     * server.
     *
     * Resolves with the {@see Message} that was posted, so the caller can
     * remember which Telegram message it came from and edit it later. `wait`
     * is what makes Discord answer with the message rather than a bare `204`.
     *
     * @param array{filename: string, content: string}|null $file An attachment
     *                                                            to re-upload
     *                                                            alongside the
     *                                                            text.
     *
     * @return PromiseInterface<Message|null>
     */
    public function deliver(
        Channel $channel,
        string $author,
        ?string $text,
        ?string $avatarUrl = null,
        ?array $file = null,
    ): PromiseInterface {
        if (($text === null || $text === '') && $file === null) {
            return resolve(null);
        }

        return $this->webhookFor($channel)->then(
            fn (Webhook $webhook): PromiseInterface => $webhook->execute(
                $this->builder($author, $text, $avatarUrl, $file),
                ['wait' => true],
            ),
            fn () => $this->fallback($channel, $author, $text, $file),
        );
    }

    /**
     * Rewrites a message this bridge already delivered.
     *
     * Rejects when the channel has no usable webhook: a message posted by the
     * bot itself could be edited, but the fallback path is only reached when
     * the bridge lacks **Manage Webhooks**, and the caller's answer to a
     * failed edit — post the new text as a fresh message — is the right one
     * either way.
     *
     * @return PromiseInterface<Message>
     */
    public function edit(Channel $channel, string $messageId, string $author, string $text): PromiseInterface
    {
        return $this->webhookFor($channel)->then(
            fn (Webhook $webhook): PromiseInterface => $webhook->updateMessage(
                $messageId,
                // A webhook edit may not change the username or the avatar, so
                // only the content goes.
                MessageBuilder::new()->setAllowedMentions(['parse' => []])->setContent($text),
            ),
        );
    }

    /** @param array{filename: string, content: string}|null $file */
    private function builder(string $author, ?string $text, ?string $avatarUrl, ?array $file): MessageBuilder
    {
        $builder = MessageBuilder::new()
            ->setAllowedMentions(['parse' => []])
            ->setUsername(self::safeUsername($author));

        if ($text !== null && $text !== '') {
            $builder->setContent($text);
        }

        if ($avatarUrl !== null && $avatarUrl !== '') {
            $builder->setAvatarUrl($avatarUrl);
        }

        if ($file !== null) {
            $builder->addFileFromContent($file['filename'], $file['content']);
        }

        return $builder;
    }

    /** Forgets a channel's cached webhook — call when delivery starts failing. */
    public function forget(Channel $channel): void
    {
        unset($this->cache[(string) $channel->id]);
    }

    /** @return PromiseInterface<Webhook> */
    private function webhookFor(Channel $channel): PromiseInterface
    {
        $id = (string) $channel->id;

        if (isset($this->cache[$id])) {
            return $this->cache[$id] === false
                ? reject(new \RuntimeException('no webhook available'))
                : resolve($this->cache[$id]);
        }

        return $channel->webhooks->freshen()->then(
            function ($webhooks) use ($channel, $id): PromiseInterface {
                foreach ($webhooks as $webhook) {
                    // Reuse only our own: another integration's webhook is not
                    // ours to post through.
                    if ($webhook->name === self::WEBHOOK_NAME
                        && (string) ($webhook->application_id ?? '') === (string) ($this->discord->application->id ?? '')) {
                        $this->cache[$id] = $webhook;

                        return resolve($webhook);
                    }
                }

                return $this->create($channel);
            },
            fn () => $this->create($channel),
        );
    }

    /** @return PromiseInterface<Webhook> */
    private function create(Channel $channel): PromiseInterface
    {
        $id = (string) $channel->id;

        return $channel->webhooks->save(
            $channel->webhooks->create(['name' => self::WEBHOOK_NAME]),
            'Telegram chat relay',
        )->then(
            function (Webhook $webhook) use ($id): Webhook {
                $this->cache[$id] = $webhook;

                return $webhook;
            },
            function (\Throwable $e) use ($id): never {
                // Remember the failure so every relayed line doesn't retry a
                // permission we demonstrably lack.
                $this->cache[$id] = false;

                throw $e;
            },
        );
    }

    /** @param array{filename: string, content: string}|null $file */
    private function fallback(Channel $channel, string $author, ?string $text, ?array $file): PromiseInterface
    {
        $builder = MessageBuilder::new()
            ->setAllowedMentions(['parse' => []])
            ->setContent(sprintf('**%s:** %s', self::escape($author), $text ?? ''));

        if ($file !== null) {
            $builder->addFileFromContent($file['filename'], $file['content']);
        }

        return $channel->sendMessage($builder);
    }

    /**
     * Discord rejects webhook usernames containing "discord", and caps them at
     * 80 characters. A Telegram display name can be either, but the bridge
     * should not drop a message over it.
     */
    public static function safeUsername(string $author): string
    {
        $suffix = ' (telegram)';
        $name = trim(str_ireplace('discord', 'disc*rd', $author));
        $name = mb_substr($name === '' ? 'telegram user' : $name, 0, self::USERNAME_LIMIT - mb_strlen($suffix), 'UTF-8');

        return $name . $suffix;
    }

    /** Neutralises Discord markdown in a name shown in the fallback path. */
    private static function escape(string $text): string
    {
        return preg_replace('/([*_~`|\\\\])/', '\\\\$1', $text) ?? $text;
    }
}
