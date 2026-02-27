<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

$EM_CONF[$_EXTKEY] = [
    'title'            => 't3_cowriter',
    'description'      => 'With the help of AI you can now work on a page together with a cowriter - a digital assistant that helps you to write your content.',
    'category'         => 'misc',
    'author'           => 'Team der Netresearch DTT GmbH',
    'author_email'     => '',
    'author_company'   => 'Netresearch DTT GmbH',
    'clearCacheOnLoad' => 0,
    'version'          => '3.0.0',
    'state'            => 'stable',
    'constraints'      => [
        'depends' => [
            'php'          => '8.2.0-8.9.99',
            'typo3'        => '13.4.0-14.4.99',
            'rte_ckeditor' => '13.4.0-14.4.99',
            'nr_llm'       => '1.0.0-1.9.99',
        ],
        'conflicts' => [],
        'suggests'  => [],
    ],
    'autoload' => [
        'psr-4' => [
            'Netresearch\\T3Cowriter\\' => 'Classes/',
        ],
    ],
];
