<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service;

use InvalidArgumentException;
use Netresearch\T3Cowriter\Service\RateLimiterService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(RateLimiterService::class)]
final class RateLimiterServiceTest extends TestCase
{
    private FrontendInterface&MockObject $cacheMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheMock = $this->createMock(FrontendInterface::class);
    }

    #[Test]
    public function checkLimitAllowsFirstRequest(): void
    {
        $this->cacheMock->method('get')->willReturn(false);
        $this->cacheMock->expects($this->once())->method('set');

        $service = new RateLimiterService($this->cacheMock, 20);
        $result  = $service->checkLimit('user-123');

        $this->assertTrue($result->allowed);
        $this->assertSame(20, $result->limit);
        $this->assertSame(19, $result->remaining);
    }

    #[Test]
    public function checkLimitAllowsRequestWithinLimit(): void
    {
        $now = time();
        // Simulate 5 requests in current window
        $this->cacheMock->method('get')->willReturn([
            $now - 10,
            $now - 8,
            $now - 5,
            $now - 3,
            $now - 1,
        ]);
        $this->cacheMock->expects($this->once())->method('set');

        $service = new RateLimiterService($this->cacheMock, 20);
        $result  = $service->checkLimit('user-123');

        $this->assertTrue($result->allowed);
        $this->assertSame(20, $result->limit);
        $this->assertSame(14, $result->remaining); // 20 - 6 (5 existing + 1 new)
    }

    #[Test]
    public function checkLimitDeniesRequestWhenLimitExceeded(): void
    {
        $now = time();
        // Simulate 20 requests (limit) in current window
        $requests = [];
        for ($i = 0; $i < 20; ++$i) {
            $requests[] = $now - $i;
        }
        $this->cacheMock->method('get')->willReturn($requests);
        $this->cacheMock->expects($this->never())->method('set');

        $service = new RateLimiterService($this->cacheMock, 20);
        $result  = $service->checkLimit('user-123');

        $this->assertFalse($result->allowed);
        $this->assertSame(20, $result->limit);
        $this->assertSame(0, $result->remaining);
    }

    #[Test]
    public function checkLimitCleansExpiredEntries(): void
    {
        $now = time();
        // Mix of expired (>60s ago) and current requests
        $this->cacheMock->method('get')->willReturn([
            $now - 120, // Expired
            $now - 90,  // Expired
            $now - 30,  // Current
            $now - 10,  // Current
        ]);
        $this->cacheMock->expects($this->once())
            ->method('set')
            ->with(
                $this->anything(),
                $this->callback(function (array $data): bool {
                    // Should have 3 entries: 2 current + 1 new
                    return count($data) === 3;
                }),
                $this->anything(),
                $this->anything(),
            );

        $service = new RateLimiterService($this->cacheMock, 20);
        $result  = $service->checkLimit('user-123');

        $this->assertTrue($result->allowed);
        $this->assertSame(17, $result->remaining); // 20 - 3
    }

    #[Test]
    public function checkLimitUsesCustomRequestsPerMinute(): void
    {
        $this->cacheMock->method('get')->willReturn(false);
        $this->cacheMock->expects($this->once())->method('set');

        $service = new RateLimiterService($this->cacheMock, 5);
        $result  = $service->checkLimit('user-123');

        $this->assertTrue($result->allowed);
        $this->assertSame(5, $result->limit);
        $this->assertSame(4, $result->remaining);
    }

    #[Test]
    public function checkLimitIsolatesUsersByIdentifier(): void
    {
        $cacheKeys = [];
        $this->cacheMock->method('get')->willReturn(false);
        $this->cacheMock->method('set')
            ->willReturnCallback(function (string $key) use (&$cacheKeys): bool {
                $cacheKeys[] = $key;

                return true;
            });

        $service = new RateLimiterService($this->cacheMock, 20);
        $service->checkLimit('user-1');
        $service->checkLimit('user-2');

        $this->assertCount(2, $cacheKeys);
        $this->assertNotSame($cacheKeys[0], $cacheKeys[1]);
    }

    #[Test]
    public function resultProvidesCorrectHeaders(): void
    {
        $this->cacheMock->method('get')->willReturn(false);
        $this->cacheMock->method('set');

        $service = new RateLimiterService($this->cacheMock, 20);
        $result  = $service->checkLimit('user-123');

        $headers = $result->getHeaders();

        $this->assertArrayHasKey('X-RateLimit-Limit', $headers);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $headers);
        $this->assertArrayHasKey('X-RateLimit-Reset', $headers);
        $this->assertSame('20', $headers['X-RateLimit-Limit']);
        $this->assertSame('19', $headers['X-RateLimit-Remaining']);
    }

    #[Test]
    public function resultProvidesRetryAfterForDeniedRequests(): void
    {
        $now      = time();
        $requests = [];
        for ($i = 0; $i < 20; ++$i) {
            $requests[] = $now - 10; // All 10 seconds ago
        }
        $this->cacheMock->method('get')->willReturn($requests);

        $service    = new RateLimiterService($this->cacheMock, 20);
        $result     = $service->checkLimit('user-123');
        $retryAfter = $result->getRetryAfter();

        // Reset time should be oldest entry + 60s, so ~50s from now
        $this->assertGreaterThan(40, $retryAfter);
        $this->assertLessThan(60, $retryAfter);
    }

    #[Test]
    public function checkLimitHandlesNonIntegerCacheData(): void
    {
        // Cache contains invalid data (strings instead of ints)
        $this->cacheMock->method('get')->willReturn([
            'invalid',
            123.45,
            null,
            time() - 10, // Only this is valid
        ]);
        $this->cacheMock->expects($this->once())->method('set');

        $service = new RateLimiterService($this->cacheMock, 20);
        $result  = $service->checkLimit('user-123');

        $this->assertTrue($result->allowed);
        // Should only count the valid integer entry + new one = 2
        $this->assertSame(18, $result->remaining);
    }

    #[Test]
    public function constructorRejectsZeroRequestsPerMinute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requestsPerMinute must be >= 1');

        new RateLimiterService($this->cacheMock, 0);
    }

    #[Test]
    public function constructorRejectsNegativeRequestsPerMinute(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RateLimiterService($this->cacheMock, -5);
    }

    #[Test]
    public function constructorAcceptsExactlyOneRequestPerMinute(): void
    {
        $this->cacheMock->method('get')->willReturn(false);
        $this->cacheMock->expects($this->once())->method('set');

        $service = new RateLimiterService($this->cacheMock, 1);
        $result  = $service->checkLimit('user-123');

        $this->assertTrue($result->allowed);
        $this->assertSame(1, $result->limit);
        $this->assertSame(0, $result->remaining); // 1 - 1 (the new request)
    }

    #[Test]
    public function checkLimitWithSpecialCharacterIdentifier(): void
    {
        $cacheKeys = [];
        $this->cacheMock->method('get')->willReturn(false);
        $this->cacheMock->method('set')
            ->willReturnCallback(function (string $key) use (&$cacheKeys): bool {
                $cacheKeys[] = $key;

                return true;
            });

        $service = new RateLimiterService($this->cacheMock, 20);

        // All these should produce valid cache keys via md5
        $service->checkLimit('');
        $service->checkLimit("user\x00with\x00nulls");
        $service->checkLimit(str_repeat('a', 1000));

        $this->assertCount(3, $cacheKeys);
        foreach ($cacheKeys as $key) {
            // Cache key should be prefix + md5 hash (32 hex chars)
            $this->assertMatchesRegularExpression('/^cowriter_ratelimit_[a-f0-9]{32}$/', $key);
        }
    }

    #[Test]
    public function checkLimitMultipleRapidSequentialCalls(): void
    {
        $storedData = [];
        $this->cacheMock->method('get')
            ->willReturnCallback(function () use (&$storedData) {
                return $storedData !== [] ? $storedData : false;
            });
        $this->cacheMock->method('set')
            ->willReturnCallback(function (string $key, array $data) use (&$storedData): bool {
                $storedData = $data;

                return true;
            });

        $service = new RateLimiterService($this->cacheMock, 5);

        // Make 5 rapid requests (should all be allowed)
        for ($i = 0; $i < 5; ++$i) {
            $result = $service->checkLimit('user-rapid');
            $this->assertTrue($result->allowed, "Request $i should be allowed");
        }

        // 6th request should be denied
        $result = $service->checkLimit('user-rapid');
        $this->assertFalse($result->allowed, 'Request 6 should be denied');
        $this->assertSame(0, $result->remaining);
    }

    #[Test]
    public function checkLimitResetTimeCalculationForEmptyWindow(): void
    {
        $this->cacheMock->method('get')->willReturn(false);
        $this->cacheMock->expects($this->once())->method('set');

        $service = new RateLimiterService($this->cacheMock, 20);
        $before  = time();
        $result  = $service->checkLimit('user-123');
        $after   = time();

        // Reset time should be approximately now + 60 seconds
        $this->assertGreaterThanOrEqual($before + 60, $result->resetTime);
        $this->assertLessThanOrEqual($after + 60, $result->resetTime);
    }

    #[Test]
    public function checkLimitDeniesAtExactLimit(): void
    {
        $now = time();
        // Exactly at limit (5 requests with limit of 5)
        $requests = [];
        for ($i = 0; $i < 5; ++$i) {
            $requests[] = $now - $i;
        }
        $this->cacheMock->method('get')->willReturn($requests);
        $this->cacheMock->expects($this->never())->method('set');

        $service = new RateLimiterService($this->cacheMock, 5);
        $result  = $service->checkLimit('user-123');

        $this->assertFalse($result->allowed);
        $this->assertSame(5, $result->limit);
        $this->assertSame(0, $result->remaining);
    }

    #[Test]
    public function checkLimitFailsOpenOnCacheGetException(): void
    {
        $this->cacheMock->method('get')->willThrowException(new RuntimeException('Cache unavailable'));

        $service = new RateLimiterService($this->cacheMock, 20);
        $result  = $service->checkLimit('user-123');

        // Fail-open: request should be allowed
        $this->assertTrue($result->allowed);
        $this->assertSame(20, $result->limit);
        $this->assertSame(20, $result->remaining);
    }

    #[Test]
    public function checkLimitFailsOpenOnCacheSetException(): void
    {
        $this->cacheMock->method('get')->willReturn(false);
        $this->cacheMock->method('set')->willThrowException(new RuntimeException('Cache write failed'));

        $service = new RateLimiterService($this->cacheMock, 20);
        $result  = $service->checkLimit('user-123');

        // Fail-open: request should be allowed even if write fails
        $this->assertTrue($result->allowed);
        $this->assertSame(20, $result->limit);
    }
}
