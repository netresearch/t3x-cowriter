<?php

declare(strict_types=1);

/*
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

use Netresearch\T3Cowriter\Controller\AjaxController;

/**
 * AJAX route definitions for backend LLM interactions.
 */
return [
    'tx_cowriter_chat' => [
        'path' => '/cowriter/chat',
        'target' => AjaxController::class . '::chatAction',
    ],
    'tx_cowriter_complete' => [
        'path' => '/cowriter/complete',
        'target' => AjaxController::class . '::completeAction',
    ],
];
