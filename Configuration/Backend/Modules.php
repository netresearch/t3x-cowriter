<?php

declare(strict_types=1);

use Netresearch\T3Cowriter\Controller\T3CowriterModuleController;

// Caution, variable name must not exist within \TYPO3\CMS\Core\Package\AbstractServiceProvider::configureBackendModules
return [
    'netresearch_module'              => [
        'labels'         => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_mod.xlf',
        'iconIdentifier' => 'extension-netresearch-module',
        'position'       => [
            'after' => 'web',
        ],
    ],
    'netresearch_t3_cowriter' => [
        'parent'                                   => 'netresearch_module',
        'position'                                 => [],
        'access'                                   => 'admin',
        'workspaces'                               => 'live',
        'iconIdentifier'                           => 'extension-netresearch-t3-cowriter',
        'path'                                     => '/module/netresearch/cowriter',
        'labels'                                   => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_mod_um.xlf',
        'extensionName'                            => 'T3Cowriter',
        'inheritNavigationComponentFromMainModule' => false,
        'navigationComponent'                      => '@typo3/backend/page-tree/page-tree-element',
        'controllerActions'                        => [
            T3CowriterModuleController::class => [
                'index',
                'sendPromptToAiButton',
                'searchSelectedContentElements',
            ],
        ],
    ],
];
