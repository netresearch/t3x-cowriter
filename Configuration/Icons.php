<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Information\Typo3Version;

// TYPO3 v14 ships a redesigned backend with light/dark mode: use the flat,
// three-color icon that adapts via currentColor. v13 uses the colored
// (teal tile) variant that matches the classic module menu.
$moduleIcon = (new Typo3Version())->getMajorVersion() >= 14
    ? 'EXT:t3_cowriter/Resources/Public/Icons/ModuleIcon.svg'
    : 'EXT:t3_cowriter/Resources/Public/Icons/ModuleIcon.legacy.svg';

return [
    'cowriter-module' => [
        'provider' => SvgIconProvider::class,
        'source'   => $moduleIcon,
    ],
];
