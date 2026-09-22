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
    private function __construct(
        public readonly string $token,
        public readonly ?string $baseUrl,
        public readonly float $pollInterval,
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
            pollInterval: max(0.5, (float) $environment->or('TELEGRAM_POLL_INTERVAL', '1.0')),
        );
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
