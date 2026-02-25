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
final readonly class RateLimitResult
{
    public function __construct(
        /** Whether the request is allowed. */
        public bool $allowed,
        /** Maximum requests allowed per window. */
        public int $limit,
        /** Remaining requests in current window. */
        public int $remaining,
        /** Unix timestamp when the rate limit resets. */
        public int $resetTime,
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
     */
    public function getRetryAfter(): int
    {
        return max(0, $this->resetTime - time());
    }
}
