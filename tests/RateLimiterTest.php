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

namespace TelegramRelay\Tests;

use PHPUnit\Framework\TestCase;
use TelegramRelay\Helpers\RateLimiter;

final class RateLimiterTest extends TestCase
{
    private float $now = 1000.0;

    public function testABurstUpToCapacityGoesStraightOut(): void
    {
        $limiter = $this->limiter(3, 30.0);

        $this->assertTrue($limiter->tryConsume());
        $this->assertTrue($limiter->tryConsume());
        $this->assertTrue($limiter->tryConsume());
        $this->assertFalse($limiter->tryConsume());
    }

    public function testRetryAfterIsZeroWhileThereIsBudget(): void
    {
        $limiter = $this->limiter(2, 10.0);

        $this->assertSame(0.0, $limiter->retryAfter());
    }

    public function testRetryAfterSaysWhenTheNextTokenArrives(): void
    {
        $limiter = $this->limiter(2, 10.0);
        $limiter->tryConsume();
        $limiter->tryConsume();

        // 2 tokens per 10s is one every 5s.
        $this->assertEqualsWithDelta(5.0, $limiter->retryAfter(), 0.001);

        $this->now += 5.0;
        $this->assertSame(0.0, $limiter->retryAfter());
        $this->assertTrue($limiter->tryConsume());
    }

    public function testTheBucketRefillsGraduallyAndNeverOverfills(): void
    {
        $limiter = $this->limiter(4, 4.0);
        $limiter->tryConsume();
        $limiter->tryConsume();
        $limiter->tryConsume();
        $limiter->tryConsume();

        $this->now += 2.0;
        $this->assertEqualsWithDelta(2.0, $limiter->available(), 0.001);

        $this->now += 1000.0;
        $this->assertSame(4.0, $limiter->available());
    }

    public function testTheDefaultsStayUnderTelegramsPublishedLimits(): void
    {
        // Telegram: ~20 messages per minute into one group, ~30 per second
        // overall. Being limited costs far more than a message arriving late.
        $this->assertLessThan(20, RateLimiter::GROUP_CAPACITY);
        $this->assertSame(60.0, RateLimiter::GROUP_WINDOW);
        $this->assertLessThan(30, RateLimiter::GLOBAL_CAPACITY);
        $this->assertSame(1.0, RateLimiter::GLOBAL_WINDOW);
    }

    public function testTheGlobalBucketIsTheFasterOne(): void
    {
        $global = RateLimiter::global(fn (): float => $this->now);

        $this->assertSame((float) RateLimiter::GLOBAL_CAPACITY, $global->available());
    }

    private function limiter(int $capacity, float $per): RateLimiter
    {
        return new RateLimiter($capacity, $per, fn (): float => $this->now);
    }
}
