<?php



/**
 * Definitions for modules provided by EXT:examples
 */
return [
    'T3CowriterModuleController' => [
        'parent' => 'web',
        'position' => ['bottom'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/page/example',
        'labels' => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_db.xlf',
        'extensionName' => 't3-cowriter',
        'controllerActions' => [
            T3CowriterModuleController::class => [
                'indexAction'
            ]
        ],
    ]
];