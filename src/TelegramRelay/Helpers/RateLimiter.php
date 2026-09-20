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

namespace TelegramRelay\Helpers;

/**
 * A token bucket, sized for Telegram's send limits.
 *
 * Telegram publishes two limits that matter to a bridge: roughly 30 messages
 * per second across all chats, and roughly 20 messages per minute into any one
 * group. Exceeding either earns a `429` with a `retry_after`, and sustained
 * abuse gets the bot limited for far longer than the burst was worth.
 *
 * TelegramPHP already honours a `retry_after` it is given — the transport
 * holds the request and replays it — so this is not about correctness but
 * about not getting there: a busy Discord channel would otherwise spend its
 * day being told to slow down. A bucket rather than a fixed delay, so normal
 * chat goes out immediately and only a genuine burst is paced.
 *
 * The clock is injected so the behaviour can be tested without sleeping.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class RateLimiter
{
    /** Messages per minute into one group, under Telegram's ~20. */
    public const GROUP_CAPACITY = 18;

    public const GROUP_WINDOW = 60.0;

    /** Messages per second across every chat, under Telegram's ~30. */
    public const GLOBAL_CAPACITY = 25;

    public const GLOBAL_WINDOW = 1.0;

    private float $tokens;

    private float $updatedAt;

    /** @var (callable(): float) */
    private $clock;

    /**
     * @param int                      $capacity How many messages may burst.
     * @param float                    $per      Over how many seconds the bucket refills.
     * @param (callable(): float)|null $clock    Defaults to `microtime(true)`.
     */
    public function __construct(
        private readonly int $capacity = self::GROUP_CAPACITY,
        private readonly float $per = self::GROUP_WINDOW,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->tokens = (float) $capacity;
        $this->updatedAt = ($this->clock)();
    }

    /** A bucket for the whole bot rather than one chat. */
    public static function global(?callable $clock = null): self
    {
        return new self(self::GLOBAL_CAPACITY, self::GLOBAL_WINDOW, $clock);
    }

    /** Takes one token if any is available. */
    public function tryConsume(): bool
    {
        $this->refill();

        if ($this->tokens < 1.0) {
            return false;
        }

        $this->tokens -= 1.0;

        return true;
    }

    /** Seconds until the next token is available; `0.0` when one is ready now. */
    public function retryAfter(): float
    {
        $this->refill();

        if ($this->tokens >= 1.0) {
            return 0.0;
        }

        return (1.0 - $this->tokens) * ($this->per / $this->capacity);
    }

    /** Tokens currently available, for logging and tests. */
    public function available(): float
    {
        $this->refill();

        return $this->tokens;
    }

    private function refill(): void
    {
        $now = ($this->clock)();
        $elapsed = $now - $this->updatedAt;

        if ($elapsed <= 0) {
            return;
        }

        $this->tokens = min((float) $this->capacity, $this->tokens + $elapsed * ($this->capacity / $this->per));
        $this->updatedAt = $now;
    }
}
