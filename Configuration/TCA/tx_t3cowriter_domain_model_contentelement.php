<?php

use Netresearch\T3Cowriter\UserFunctions\FormEngine\ItemsProcFunc;

return [
    'ctrl' => [
        'title' => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_db.xlf:tx_t3cowriter_domain_model_contentelement.title',
        'label' => 'table',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'cruser_id' => 'cruser_id',
        'dividers2tabs' => true,
        'versioningWS' => 2,
        'origUid' => 't3_origuid',
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
            'starttime' => 'starttime',
            'endtime' => 'endtime',
        ],
        'searchFields' => 'table,field',
        'iconfile' => 'EXT:t3_cowriter/Resources/Public/Icons/Extension.svg'
    ],
    'types' => [
        '1' => ['showitem' => 'table, field']
    ],
    'columns' => [
        'table' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_db.xlf:tx_t3cowriter_domain_model_contentelement.title',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['-- Select a table --', 0]
                ],
                'itemsProcFunc' => ItemsProcFunc::class . '->selectTables',
                'onChange' => 'reload'
            ],
        ],
        'field' => [
            'exclude' => 1,
            'label' => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_db.xlf:tx_t3cowriter_domain_model_contentelement.field',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['-- Select a field --', 0]
                ],
                'itemsProcFunc' => ItemsProcFunc::class . '->selectFields',
            ],
        ],
        // Weitere Felder hier
    ],
];
