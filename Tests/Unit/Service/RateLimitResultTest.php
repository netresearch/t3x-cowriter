<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service;

use Netresearch\T3Cowriter\Service\RateLimitResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RateLimitResult::class)]
final class RateLimitResultTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $resetTime = time() + 60;
        $result    = new RateLimitResult(
            allowed: true,
            limit: 100,
            remaining: 75,
            resetTime: $resetTime,
        );

        $this->assertTrue($result->allowed);
        $this->assertSame(100, $result->limit);
        $this->assertSame(75, $result->remaining);
        $this->assertSame($resetTime, $result->resetTime);
    }

    #[Test]
    public function getHeadersReturnsCorrectHeaders(): void
    {
        $resetTime = time() + 60;
        $result    = new RateLimitResult(
            allowed: true,
            limit: 100,
            remaining: 75,
            resetTime: $resetTime,
        );

        $headers = $result->getHeaders();

        $this->assertSame('100', $headers['X-RateLimit-Limit']);
        $this->assertSame('75', $headers['X-RateLimit-Remaining']);
        $this->assertSame((string) $resetTime, $headers['X-RateLimit-Reset']);
    }

    #[Test]
    public function getRetryAfterReturnsSecondsUntilReset(): void
    {
        $resetTime = time() + 45;
        $result    = new RateLimitResult(
            allowed: false,
            limit: 20,
            remaining: 0,
            resetTime: $resetTime,
        );

        $retryAfter = $result->getRetryAfter();

        // Allow for slight timing differences
        $this->assertGreaterThanOrEqual(44, $retryAfter);
        $this->assertLessThanOrEqual(45, $retryAfter);
    }

    #[Test]
    public function getRetryAfterReturnsZeroWhenResetTimeIsInPast(): void
    {
        $result = new RateLimitResult(
            allowed: false,
            limit: 20,
            remaining: 0,
            resetTime: time() - 10, // Past time
        );

        $this->assertSame(0, $result->getRetryAfter());
    }

    #[Test]
    public function retryAfterIsComputedEagerlyAtConstruction(): void
    {
        $resetTime = time() + 30;
        $result    = new RateLimitResult(
            allowed: false,
            limit: 20,
            remaining: 0,
            resetTime: $resetTime,
        );

        $firstCall  = $result->getRetryAfter();
        $secondCall = $result->getRetryAfter();

        // Both calls should return the same pre-computed value
        $this->assertSame($firstCall, $secondCall);
        // The retryAfter public property should match
        $this->assertSame($result->retryAfter, $firstCall);
    }

    #[Test]
    public function getHeadersContainsExactlyThreeHeaders(): void
    {
        $result = new RateLimitResult(
            allowed: true,
            limit: 50,
            remaining: 49,
            resetTime: time() + 60,
        );

        $headers = $result->getHeaders();

        $this->assertCount(3, $headers);
        $this->assertSame(['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'], array_keys($headers));
    }

    #[Test]
    public function deniedResultHasZeroRemaining(): void
    {
        $result = new RateLimitResult(
            allowed: false,
            limit: 20,
            remaining: 0,
            resetTime: time() + 60,
        );

        $this->assertFalse($result->allowed);
        $this->assertSame(0, $result->remaining);
    }
}
