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
 * Interface for rate limiting services.
 */
interface RateLimiterInterface
{
    /**
     * Check if a request is allowed for the given user identifier.
     *
     * @param string $userIdentifier Unique identifier for the user (e.g., backend user UID)
     *
     * @return RateLimitResult Result containing whether request is allowed and limit info
     */
    public function checkLimit(string $userIdentifier): RateLimitResult;
}
