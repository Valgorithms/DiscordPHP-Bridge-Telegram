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
     * @param ?string $caBundle       The CA bundle TLS to Telegram is verified with; see {@see caBundle()}.
     * @param string  $prefix         What commands typed in a Telegram chat start with.
     * @param ?string $ownerId        The operator's numeric Telegram user id, for the top rung in chat.
     * @param ?string $caBundleSource Where `$caBundle` came from — the variable or the php.ini directive — for the log.
     */
    private function __construct(
        public readonly string $token,
        public readonly ?string $baseUrl,
        public readonly int $pollTimeout,
        public readonly ?string $caBundle,
        public readonly string $prefix = self::DEFAULT_PREFIX,
        public readonly ?string $ownerId = null,
        public readonly ?string $caBundleSource = null,
    ) {
    }

    /**
     * @param (callable(string): (string|false))|null $ini Reads a php.ini directive;
     *                                                      `ini_get` unless a test says otherwise.
     *
     * @throws \RuntimeException when a required value is missing.
     */
    public static function fromEnvironment(Environment $environment, ?callable $ini = null): self
    {
        [$caBundle, $caBundleSource] = self::caBundle($environment, $ini ?? ini_get(...));

        return new self(
            token: $environment->require('TELEGRAM_TOKEN'),
            // For a self-hosted Bot API server. Unset means Telegram's own.
            baseUrl: $environment->get('TELEGRAM_BASE_URL'),
            // Long polling: Telegram answers as soon as there is an update, so
            // this only bounds how long a quiet connection is held. Shorter
            // means more requests, never faster delivery.
            pollTimeout: max(1, min(self::MAX_POLL_TIMEOUT, (int) $environment->or('TELEGRAM_POLL_TIMEOUT', (string) self::MAX_POLL_TIMEOUT))),
            caBundle: $caBundle,
            prefix: $environment->or('TELEGRAM_PREFIX', self::DEFAULT_PREFIX),
            ownerId: self::numericId($environment->get('TELEGRAM_OWNER_ID')),
            caBundleSource: $caBundleSource,
        );
    }

    /**
     * The CA bundle to verify Telegram's certificate with, and where it came
     * from.
     *
     * `TELEGRAM_CA_BUNDLE` when it is set, as given. Otherwise whatever php.ini
     * names: `openssl.cafile` first, since that is what PHP's own streams read,
     * then `curl.cainfo` — on Windows often the only one set, because it is the
     * one installers and guides mention, and PHP's streams never read it.
     *
     * A php.ini path that is not a file is passed over. Handing TLS a bundle
     * that does not exist fails every connection, where leaving it unset still
     * lets the platform's own certificate store be tried.
     *
     * @param  callable(string): (string|false) $ini
     * @return array{0: ?string, 1: ?string}
     */
    private static function caBundle(Environment $environment, callable $ini): array
    {
        $explicit = $environment->get('TELEGRAM_CA_BUNDLE');

        if ($explicit !== null) {
            return [$explicit, 'TELEGRAM_CA_BUNDLE'];
        }

        foreach (['openssl.cafile', 'curl.cainfo'] as $directive) {
            $path = trim((string) $ini($directive));

            if ($path !== '' && is_file($path)) {
                return [$path, $directive];
            }
        }

        return [null, null];
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
     * `cacert.pem` — set, or found in php.ini — is the fix. Everything else is
     * left at ReactPHP's defaults; in particular, verification is never
     * disabled.
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
