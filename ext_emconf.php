<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$EM_CONF[$_EXTKEY] = [
    'title'          => 'AI Cowriter',
    'description'    => 'AI assistant in CKEditor that helps editors write page content.',
    'category'       => 'be',
    'author'         => 'Team der Netresearch DTT GmbH',
    'author_email'   => '',
    'author_company' => 'Netresearch DTT GmbH',
    'version'        => '3.10.3',
    'state'          => 'stable',
    'constraints'    => [
        'depends' => [
            'php'          => '8.2.0-8.9.99',
            'typo3'        => '13.4.0-14.99.99',
            'rte_ckeditor' => '13.4.0-14.99.99',
            'nr_llm'       => '0.38.0-0.38.99',
        ],
        'conflicts' => [],
        'suggests'  => [],
    ],
];
