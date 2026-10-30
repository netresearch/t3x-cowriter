<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Presentation;

use TYPO3\CMS\Core\Crypto\HashService;

/**
 * Binds the switch of visibility to the pair the creation just made: an HMAC
 * over the new element, its original, the backend user and the workspace,
 * keyed with the instance's encryption key. Another pair, another user or
 * another workspace gives another token.
 *
 * @internal
 */
final readonly class PresentationSwitchToken
{
    private const SECRET = 't3_cowriter-presentation-switch';

    public function __construct(
        private HashService $hashService,
    ) {}

    public function issue(int $newUid, int $originalUid, int $backendUserId, int $workspaceId): string
    {
        return $this->hashService->hmac(implode(':', [$newUid, $originalUid, $backendUserId, max(0, $workspaceId)]), self::SECRET);
    }

    public function isValid(string $token, int $newUid, int $originalUid, int $backendUserId, int $workspaceId): bool
    {
        return hash_equals($this->issue($newUid, $originalUid, $backendUserId, $workspaceId), $token);
    }
}
