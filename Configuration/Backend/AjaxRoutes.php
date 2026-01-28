<?php

/*
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
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
    'tx_cowriter_configurations' => [
        'path'   => '/cowriter/configurations',
        'target' => AjaxController::class . '::getConfigurationsAction',
    ],
];
