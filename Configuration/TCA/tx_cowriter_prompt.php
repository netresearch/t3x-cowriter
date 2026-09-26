<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

/*
 * Prompts that editors save from the Cowriter dialog.
 *
 * The dialog writes the records through its own endpoints, which check the
 * owner. The records sit on the root level (pid 0), so in the List module
 * only administrators see them; that is where a shared prompt is approved.
 */
return [
    'ctrl' => [
        'title'          => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_be.xlf:tx_cowriter_prompt',
        'label'          => 'title',
        'tstamp'         => 'tstamp',
        'crdate'         => 'crdate',
        'delete'         => 'deleted',
        'default_sortby' => 'title',
        'rootLevel'      => 1,
        'enablecolumns'  => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:t3_cowriter/Resources/Public/Icons/Extension.svg',
    ],
    'columns' => [
        'hidden' => [
            'label'  => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_be.xlf:tx_cowriter_prompt.hidden',
            'config' => [
                'type'       => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
        'be_user' => [
            'label'  => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_be.xlf:tx_cowriter_prompt.be_user',
            'config' => [
                'type'     => 'group',
                'allowed'  => 'be_users',
                'size'     => 1,
                'maxitems' => 1,
                'readOnly' => true,
            ],
        ],
        'title' => [
            'label'  => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_be.xlf:tx_cowriter_prompt.title',
            'config' => [
                'type'     => 'input',
                'size'     => 40,
                'max'      => 255,
                'required' => true,
                'eval'     => 'trim',
            ],
        ],
        'instruction' => [
            'label'  => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_be.xlf:tx_cowriter_prompt.instruction',
            'config' => [
                'type'     => 'text',
                'rows'     => 10,
                'cols'     => 60,
                'required' => true,
            ],
        ],
        'shared' => [
            'label'  => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_be.xlf:tx_cowriter_prompt.shared',
            'config' => [
                'type'       => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
        'approved' => [
            'exclude' => true,
            'label'   => 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_be.xlf:tx_cowriter_prompt.approved',
            'config'  => [
                'type'       => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'title, instruction, --palette--;;sharing, --div--;LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_be.xlf:tx_cowriter_prompt.tab.access, hidden, be_user',
        ],
    ],
    'palettes' => [
        'sharing' => [
            'showitem' => 'shared, approved',
        ],
    ],
];
