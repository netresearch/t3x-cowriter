<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

use Netresearch\T3Cowriter\Controller\AjaxController;

/**
 * AJAX route definitions for backend LLM interactions.
 *
 * SECURITY: These routes are automatically protected by TYPO3 backend authentication.
 * Only authenticated backend users can access these endpoints. The routes are registered
 * under /typo3/ajax/cowriter/* and require a valid backend session.
 *
 * @see TYPO3\CMS\Backend\Http\RouteDispatcher - Handles authentication check
 */
return [
    'tx_cowriter_chat' => [
        'path'   => '/cowriter/chat',
        'target' => AjaxController::class . '::chatAction',
    ],
    'tx_cowriter_complete' => [
        'path'   => '/cowriter/complete',
        'target' => AjaxController::class . '::completeAction',
    ],
    'tx_cowriter_stream' => [
        'path'   => '/cowriter/stream',
        'target' => AjaxController::class . '::streamAction',
    ],
    'tx_cowriter_configurations' => [
        'path'   => '/cowriter/configurations',
        'target' => AjaxController::class . '::getConfigurationsAction',
    ],
];
