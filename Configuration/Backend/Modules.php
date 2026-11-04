<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

use Netresearch\T3Cowriter\Controller\Backend\OverviewController;

// The cowriter overview sits in nr-llm's "AI" section (netresearch_ai, nr-llm
// ADR-183), after nr-llm's own entries. The identifier and the path stay as
// they were, so links to the former status page keep working. Every backend
// user may open it: an editor sees their own readiness, usage and budget, an
// administrator also the installation's figures and the settings.
return [
    'cowriter_status' => [
        'parent'         => 'netresearch_ai',
        'access'         => 'user',
        'path'           => '/module/cowriter/status',
        'iconIdentifier' => 'cowriter-module',
        'labels'         => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_mod_status.xlf',
        'routes'         => [
            '_default' => [
                'target' => OverviewController::class . '::indexAction',
            ],
        ],
    ],
];
