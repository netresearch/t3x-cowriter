<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Whether a backend user may edit a record itself: its language, its
 * editlock and the explicit allow/deny of its type, as core checks them.
 * Page permissions are the caller's business.
 *
 * @internal
 */
final readonly class RecordEditAccess
{
    /**
     * TYPO3 v14 replaced recordEditAccessInternals() by checkRecordEditAccess();
     * v13.4 only has the former.
     *
     * @param array<string, mixed> $record
     */
    public function mayEdit(BackendUserAuthentication $backendUser, string $table, array $record): bool
    {
        // @phpstan-ignore function.alreadyNarrowedType (always true when analysed against v14)
        if (method_exists($backendUser, 'checkRecordEditAccess')) {
            // The method, and the AccessCheckResult it returns, are absent when analysed against v13.4.
            // @phpstan-ignore method.notFound, property.nonObject, return.type
            return $backendUser->checkRecordEditAccess($table, $record)->isAllowed;
        }

        // @phpstan-ignore method.deprecated (TYPO3 v13.4 path)
        return $backendUser->recordEditAccessInternals($table, $record);
    }
}
