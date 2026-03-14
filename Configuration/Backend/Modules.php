<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

use Netresearch\T3Cowriter\Controller\Backend\StatusController;

return [
    'cowriter_status' => [
        'parent'         => 'tools',
        'position'       => ['after' => 'nrllm'],
        'access'         => 'admin',
        'iconIdentifier' => 'content-widget-text',
        'labels'         => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_mod_status.xlf',
        'routes'         => [
            '_default' => [
                'target' => StatusController::class . '::indexAction',
            ],
        ],
    ],
];
