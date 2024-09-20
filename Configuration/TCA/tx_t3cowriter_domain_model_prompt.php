<?php

/**
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

return [
    'ctrl' => [
        'title'                    => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang.xlf:tx_t3cowriter_domain_model_prompt.title',
        'label'                    => 'title',
        'tstamp'                   => 'tstamp',
        'crdate'                   => 'crdate',
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
        'searchFields' => 'title,prompts',
        'iconfile'     => 'EXT:t3_cowriter/Resources/Public/Icons/Extension.svg',
    ],
    'types' => [
        '1' => ['showitem' => 'title, prompt'],
    ],
    'columns' => [
        'title' => [
            'exclude' => 1,
            'label'   => 'Title',
            'config'  => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
            ],
        ],
        'prompt' => [
            'exclude' => 1,
            'label'   => 'Prompt',
            'config'  => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 15,
            ],
        ],
    ],
];
