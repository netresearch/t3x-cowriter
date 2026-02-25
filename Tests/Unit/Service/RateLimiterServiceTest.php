<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service;

use Netresearch\T3Cowriter\Service\RateLimiterService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
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
        $this->assertEquals(20, $result->limit);
        $this->assertEquals(19, $result->remaining);
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
        $this->assertEquals(20, $result->limit);
        $this->assertEquals(14, $result->remaining); // 20 - 6 (5 existing + 1 new)
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
        $this->assertEquals(20, $result->limit);
        $this->assertEquals(0, $result->remaining);
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
        $this->assertEquals(17, $result->remaining); // 20 - 3
    }

    #[Test]
    public function checkLimitUsesCustomRequestsPerMinute(): void
    {
        $this->cacheMock->method('get')->willReturn(false);
        $this->cacheMock->expects($this->once())->method('set');

        $service = new RateLimiterService($this->cacheMock, 5);
        $result  = $service->checkLimit('user-123');

        $this->assertTrue($result->allowed);
        $this->assertEquals(5, $result->limit);
        $this->assertEquals(4, $result->remaining);
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
        $this->assertNotEquals($cacheKeys[0], $cacheKeys[1]);
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
        $this->assertEquals('20', $headers['X-RateLimit-Limit']);
        $this->assertEquals('19', $headers['X-RateLimit-Remaining']);
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
        $this->assertEquals(18, $result->remaining);
    }
}
