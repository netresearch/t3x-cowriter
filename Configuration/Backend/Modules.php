<?php

use Netresearch\T3Cowriter\Controller\T3CowriterModuleController;

/**
 * Definitions for modules provided by EXT:examples
 */
return [
    'T3CowriterModule' => [
        'parent' => 'site',
        'position' => ['bottom'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/page/t3cowriter',
        'labels' => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang.xlf',
        'extensionName' => 't3-cowriter',
        'navigationComponent' => '@typo3/backend/page-tree/page-tree-element',
        'controllerActions' => [
            T3CowriterModuleController::class => [
                'index'
            ]
        ],
    ]
];