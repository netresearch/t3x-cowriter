<?php
return [
    'ctrl' => [
        'title' => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang.xlf:tx_t3cowriter_domain_model_prompts.title',
        'label' => 'title',
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
        'searchFields' => 'title,prompts',
        'iconfile' => 'EXT:t3_cowriter/Resources/Public/Icons/Extension.svg'
    ],
    'types' => [
        '1' => ['showitem' => 'title, prompt']
    ],
    'columns' => [
        'title' => [
            'exclude' => 1,
            'label' => 'Title',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim'
            ],
        ],
        'prompt' => [
            'exclude' => 1,
            'label' => 'Prompt',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 15
            ],
        ],
    ],
];
