<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

/**
 * Result of a rate limit check.
 *
 * @internal
 */
final class RateLimitResult
{
    /**
     * Cached retry-after value to avoid time drift between multiple calls.
     */
    private ?int $cachedRetryAfter = null;

    public function __construct(
        /** Whether the request is allowed. */
        public readonly bool $allowed,
        /** Maximum requests allowed per window. */
        public readonly int $limit,
        /** Remaining requests in current window. */
        public readonly int $remaining,
        /** Unix timestamp when the rate limit resets. */
        public readonly int $resetTime,
    ) {}

    /**
     * Get rate limit headers for HTTP response.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [
            'X-RateLimit-Limit'     => (string) $this->limit,
            'X-RateLimit-Remaining' => (string) $this->remaining,
            'X-RateLimit-Reset'     => (string) $this->resetTime,
        ];
    }

    /**
     * Get Retry-After header value in seconds.
     *
     * The value is cached on first call to ensure consistency when used
     * in both the response body and Retry-After header.
     */
    public function getRetryAfter(): int
    {
        return $this->cachedRetryAfter ??= max(0, $this->resetTime - time());
    }
}
