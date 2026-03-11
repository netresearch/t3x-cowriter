<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;

$configure = require __DIR__ . '/../.Build/vendor/netresearch/typo3-ci-workflows/config/rector/rector.php';

return static function (RectorConfig $rectorConfig) use ($configure): void {
    $configure($rectorConfig, __DIR__ . '/..');

    $rectorConfig->sets([
        Typo3LevelSetList::UP_TO_TYPO3_14,
    ]);

    $rectorConfig->skip([
        RemoveUnusedPublicMethodParameterRector::class,
        __DIR__ . '/../ext_*.sql',
    ]);
};
