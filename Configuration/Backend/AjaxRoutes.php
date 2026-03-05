<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

use Netresearch\T3Cowriter\Controller\AjaxController;
use Netresearch\T3Cowriter\Controller\TemplateController;
use Netresearch\T3Cowriter\Controller\ToolController;
use Netresearch\T3Cowriter\Controller\TranslationController;
use Netresearch\T3Cowriter\Controller\VisionController;

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
    'tx_cowriter_tasks' => [
        'path'   => '/cowriter/tasks',
        'target' => AjaxController::class . '::getTasksAction',
    ],
    'tx_cowriter_task_execute' => [
        'path'   => '/cowriter/task-execute',
        'target' => AjaxController::class . '::executeTaskAction',
    ],
    'tx_cowriter_context' => [
        'path'   => '/cowriter/context',
        'target' => AjaxController::class . '::getContextAction',
    ],
    'tx_cowriter_vision' => [
        'path'   => '/cowriter/vision',
        'target' => VisionController::class . '::analyzeAction',
    ],
    'tx_cowriter_translate' => [
        'path'   => '/cowriter/translate',
        'target' => TranslationController::class . '::translateAction',
    ],
    'tx_cowriter_templates' => [
        'path'   => '/cowriter/templates',
        'target' => TemplateController::class . '::listAction',
    ],
    'tx_cowriter_tools' => [
        'path'   => '/cowriter/tools',
        'target' => ToolController::class . '::executeAction',
    ],
];
