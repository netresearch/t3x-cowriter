<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use Throwable;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The link to the cowriter overview that an error message carries, for a
 * backend user who may open the module. The module's access is "user", so an
 * editor needs it granted through a group.
 *
 * @internal
 */
final class OverviewLink
{
    public const MODULE = 'cowriter_status';

    /**
     * The module's URL for the current backend user; null when the user may
     * not open it, when there is no user or module provider, or when the
     * route cannot be built.
     */
    public static function forCurrentUser(?ModuleProvider $moduleProvider, UriBuilder $uriBuilder): ?string
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$moduleProvider instanceof ModuleProvider || !$user instanceof BackendUserAuthentication) {
            return null;
        }

        try {
            if (!$moduleProvider->accessGranted(self::MODULE, $user)) {
                return null;
            }

            return (string) $uriBuilder->buildUriFromRoute(self::MODULE);
        } catch (Throwable) {
            return null;
        }
    }
}
