<?php

/**
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use Netresearch\T3Cowriter\UserFunctions\FormEngine\ItemsProcFunc;

return [
    'ctrl' => [
        'title'                    => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang.xlf:tx_t3cowriter_domain_model_contentelement.title',
        'label'                    => 'title',
        'tstamp'                   => 'tstamp',
        'crdate'                   => 'crdate',
        'cruser_id'                => 'cruser_id',
        'dividers2tabs'            => true,
        'versioningWS'             => 2,
        'origUid'                  => 't3_origuid',
        'languageField'            => 'sys_language_uid',
        'transOrigPointerField'    => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'delete'                   => 'deleted',
        'enablecolumns'            => [
            'disabled'  => 'hidden',
            'starttime' => 'starttime',
            'endtime'   => 'endtime',
        ],
        'searchFields' => 'title, table,field',
        'iconfile'     => 'EXT:t3_cowriter/Resources/Public/Icons/Extension.svg',
    ],
    'types' => [
        '1' => ['showitem' => 'title, table, field'],
    ],
    'columns' => [
        'title' => [
            'exclude' => 1,
            'label'   => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_db.xlf:tx_t3cowriter_domain_model_contentelement.title',
            'config'  => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
            ],
        ],
        'table' => [
            'exclude'  => 1,
            'label'    => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_db.xlf:tx_t3cowriter_domain_model_contentelement.title',
            'onChange' => 'reload',
            'config'   => [
                'type'       => 'select',
                'renderType' => 'selectSingle',
                'items'      => [
                    ['-- Select a table --', 0],
                ],
                'itemsProcFunc' => ItemsProcFunc::class . '->selectTables',
            ],
        ],
        'field' => [
            'exclude' => 1,
            'label'   => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_db.xlf:tx_t3cowriter_domain_model_contentelement.field',
            'config'  => [
                'type'       => 'select',
                'renderType' => 'selectSingleBox',
                'items'      => [
                    ['-- Select a field --', 0],
                ],
                'itemsProcFunc' => ItemsProcFunc::class . '->selectFields',
            ],
        ],
    ],
];
