<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Variant;

use TYPO3\CMS\Core\Crypto\HashService;

/**
 * Binds "show the variant" to the variant the creation just made: an HMAC
 * over the variant, its original, its context, the backend user and the
 * workspace, keyed with the instance's encryption key. Another pair, another
 * context, another user or another workspace gives another token.
 *
 * @internal
 */
final readonly class VariantShowToken
{
    private const SECRET = 't3_cowriter-audience-variant-show';

    public function __construct(
        private HashService $hashService,
    ) {}

    public function issue(int $variantUid, int $originalUid, int $contextUid, int $backendUserId, int $workspaceId): string
    {
        return $this->hashService->hmac(implode(':', [$variantUid, $originalUid, $contextUid, $backendUserId, max(0, $workspaceId)]), self::SECRET);
    }

    public function isValid(string $token, int $variantUid, int $originalUid, int $contextUid, int $backendUserId, int $workspaceId): bool
    {
        return hash_equals($this->issue($variantUid, $originalUid, $contextUid, $backendUserId, $workspaceId), $token);
    }
}
