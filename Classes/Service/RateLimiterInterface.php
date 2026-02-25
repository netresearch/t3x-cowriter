<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
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
