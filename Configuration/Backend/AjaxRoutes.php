<?php

use Netresearch\T3Cowriter\Controller\ProgressController;
use Netresearch\T3Cowriter\Controller\T3CowriterModuleController;

/**
 * Definitions for modules provided by EXT:examples
 */
return [
    't3_cowriter_progress' => [
        'path' => '/progress',
        'target' => ProgressController::class . '::indexAction'
    ]
];