<?php

/*
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
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
