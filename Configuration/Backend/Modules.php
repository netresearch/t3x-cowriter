<?php

use Netresearch\T3Cowriter\Controller\T3CowriterModuleController;

/**
 * Definitions for modules provided by EXT:examples
 */
return [
    'T3CowriterModule' => [
        'parent' => 'web',
        'position' => ['bottom'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/page/t3cowriter',
        'labels' => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang.xlf',
        'extensionName' => 't3-cowriter',
        'controllerActions' => [
            T3CowriterModuleController::class => [
                'index'
            ]
        ],
    ]
];