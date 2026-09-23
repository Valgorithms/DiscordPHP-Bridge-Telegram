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

use Bridge\Environment;

/**
 * What the Telegram connector needs to start, read from the same environment
 * the bot itself was.
 *
 * Kept here rather than in the core's {@see \Bridge\Config}, which is the point
 * of the connector model: installing a network should not mean the core grows
 * fields for settings it will never read.
 *
 * Secrets only ever come from the environment — never from the JSON store,
 * which is runtime state a server admin edits through chat and which should be
 * safe to read, back up, or paste into an issue.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class TelegramConfig
{
    /** What commands typed in a Telegram chat start with. */
    public const DEFAULT_PREFIX = '!';

    /** Telegram holds a long poll open for at most this many seconds. */
    public const MAX_POLL_TIMEOUT = 50;

    /**
     * @param string  $token       From @BotFather. Never logged.
     * @param ?string $baseUrl     A self-hosted Bot API server, or `null` for Telegram's own.
     * @param int     $pollTimeout How long each long poll is held open, in seconds.
     * @param ?string $caBundle    A `cacert.pem`, for a PHP build that has none.
     * @param string  $prefix      What commands typed in a Telegram chat start with.
     * @param ?string $ownerId     The operator's numeric Telegram user id, for the top rung in chat.
     */
    private function __construct(
        public readonly string $token,
        public readonly ?string $baseUrl,
        public readonly int $pollTimeout,
        public readonly ?string $caBundle,
        public readonly string $prefix = self::DEFAULT_PREFIX,
        public readonly ?string $ownerId = null,
    ) {
    }

    /**
     * @throws \RuntimeException when a required value is missing.
     */
    public static function fromEnvironment(Environment $environment): self
    {
        return new self(
            token: $environment->require('TELEGRAM_TOKEN'),
            // For a self-hosted Bot API server. Unset means Telegram's own.
            baseUrl: $environment->get('TELEGRAM_BASE_URL'),
            // Long polling: Telegram answers as soon as there is an update, so
            // this only bounds how long a quiet connection is held. Shorter
            // means more requests, never faster delivery.
            pollTimeout: max(1, min(self::MAX_POLL_TIMEOUT, (int) $environment->or('TELEGRAM_POLL_TIMEOUT', (string) self::MAX_POLL_TIMEOUT))),
            caBundle: $environment->get('TELEGRAM_CA_BUNDLE'),
            prefix: $environment->or('TELEGRAM_PREFIX', self::DEFAULT_PREFIX),
            ownerId: self::numericId($environment->get('TELEGRAM_OWNER_ID')),
        );
    }

    /** A numeric user id, or `null` for anything else — an `@name` can change hands. */
    private static function numericId(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{1,20}$/', $value) === 1 ? $value : null;
    }

    /**
     * The `socket_options` TelegramPHP should connect with.
     *
     * Only ever a CA bundle. A Windows PHP build typically ships without an
     * `openssl.cafile`, so TLS to `api.telegram.org` fails outright with
     * "unable to get local issuer certificate"; pointing the connector at a
     * `cacert.pem` is the fix. Everything else is left at ReactPHP's defaults —
     * in particular, verification is never disabled.
     *
     * @return array<string, mixed>
     */
    public function socketOptions(): array
    {
        return $this->caBundle === null ? [] : ['tls' => ['cafile' => $this->caBundle]];
    }

    /** Whether the environment carries enough to install this connector at all. */
    public static function isConfigured(Environment $environment): bool
    {
        return $environment->has('TELEGRAM_TOKEN');
    }

    /**
     * The bot's own numeric id, which Telegram puts in front of every token.
     *
     * Used to recognise the bot's own messages coming back, and needed before
     * the API has answered anything — so it is read off the token rather than
     * fetched.
     */
    public function botId(): ?string
    {
        $id = explode(':', $this->token)[0];

        return preg_match('/^\d+$/', $id) === 1 ? $id : null;
    }
}
