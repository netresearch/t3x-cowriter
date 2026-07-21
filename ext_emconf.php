<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$EM_CONF[$_EXTKEY] = [
    'title'          => 'AI Cowriter',
    'description'    => 'With the help of AI you can now work on a page together with a cowriter - a digital assistant that helps you to write your content.',
    'category'       => 'be',
    'author'         => 'Team der Netresearch DTT GmbH',
    'author_email'   => '',
    'author_company' => 'Netresearch DTT GmbH',
    'version'        => '3.4.1',
    'state'          => 'stable',
    'constraints'    => [
        'depends' => [
            'php'          => '8.2.0-8.9.99',
            'typo3'        => '13.4.0-14.99.99',
            'rte_ckeditor' => '13.4.0-14.99.99',
            'nr_llm'       => '0.23.0-0.23.99',
        ],
        'conflicts' => [],
        'suggests'  => [],
    ],
];
