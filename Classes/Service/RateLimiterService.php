<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Application-level rate limiter for LLM API requests.
 *
 * Implements a sliding window rate limiting algorithm using TYPO3's cache framework.
 * Tracks requests per backend user with configurable limits.
 */
final readonly class RateLimiterService implements RateLimiterInterface
{
    /**
     * Default requests per minute limit.
     */
    private const DEFAULT_REQUESTS_PER_MINUTE = 20;

    /**
     * Window size in seconds (1 minute).
     */
    private const WINDOW_SIZE_SECONDS = 60;

    /**
     * Cache key prefix for rate limit entries.
     */
    private const CACHE_PREFIX = 'cowriter_ratelimit_';

    public function __construct(
        private FrontendInterface $cache,
        private int $requestsPerMinute = self::DEFAULT_REQUESTS_PER_MINUTE,
    ) {}

    /**
     * Check if a request is allowed for the given user identifier.
     *
     * @param string $userIdentifier Unique identifier for the user (e.g., backend user UID)
     *
     * @return RateLimitResult Result containing whether request is allowed and limit info
     */
    public function checkLimit(string $userIdentifier): RateLimitResult
    {
        $cacheKey   = $this->getCacheKey($userIdentifier);
        $now        = time();
        $windowData = $this->getWindowData($cacheKey);

        // Clean expired entries from the window
        $windowData = $this->cleanExpiredEntries($windowData, $now);

        // Count requests in current window
        $requestCount = count($windowData);

        // Calculate reset time (oldest entry + window size, or now + window size if empty)
        $resetTime = $windowData !== []
            ? min($windowData) + self::WINDOW_SIZE_SECONDS
            : $now + self::WINDOW_SIZE_SECONDS;

        // Check if limit exceeded
        if ($requestCount >= $this->requestsPerMinute) {
            return new RateLimitResult(
                allowed: false,
                limit: $this->requestsPerMinute,
                remaining: 0,
                resetTime: $resetTime,
            );
        }

        // Request is allowed - record it
        $windowData[] = $now;
        $this->setWindowData($cacheKey, $windowData);

        return new RateLimitResult(
            allowed: true,
            limit: $this->requestsPerMinute,
            remaining: $this->requestsPerMinute - count($windowData),
            resetTime: $resetTime,
        );
    }

    /**
     * Get cache key for user identifier.
     */
    private function getCacheKey(string $userIdentifier): string
    {
        return self::CACHE_PREFIX . md5($userIdentifier);
    }

    /**
     * Get window data from cache.
     *
     * @return array<int, int> Array of timestamps
     */
    private function getWindowData(string $cacheKey): array
    {
        $data = $this->cache->get($cacheKey);
        if ($data === false || !is_array($data)) {
            return [];
        }

        // Filter to ensure we only have integer timestamps
        return array_values(array_filter(
            $data,
            static fn (mixed $value): bool => is_int($value),
        ));
    }

    /**
     * Store window data in cache.
     *
     * @param array<int, int> $windowData Array of timestamps
     */
    private function setWindowData(string $cacheKey, array $windowData): void
    {
        // Store with TTL of window size + buffer
        $this->cache->set(
            $cacheKey,
            $windowData,
            [],
            self::WINDOW_SIZE_SECONDS + 10,
        );
    }

    /**
     * Remove expired entries from window data.
     *
     * @param array<int, int> $windowData Array of timestamps
     *
     * @return array<int, int> Cleaned array of timestamps
     */
    private function cleanExpiredEntries(array $windowData, int $now): array
    {
        $cutoff = $now - self::WINDOW_SIZE_SECONDS;

        return array_values(array_filter(
            $windowData,
            static fn (int $timestamp): bool => $timestamp > $cutoff,
        ));
    }
}
