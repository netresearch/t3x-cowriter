<?php

/**
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use Netresearch\T3Cowriter\Controller\ProgressController;

/**
 * Definitions for modules provided by EXT:examples.
 */
return [
    't3_cowriter_progress' => [
        'path'   => '/progress',
        'target' => ProgressController::class . '::indexAction',
    ],
];
