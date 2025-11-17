<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 't3_cowriter',
    'description' => 'With the help of AI you can now work on a page together with a cowriter - a digital assistant that helps you to write your content.',
    'category' => 'misc',
    'author' => 'Team der Netresearch DTT GmbH',
    'author_email' => 'info@netresearch.de',
    'author_company' => 'Netresearch DTT GmbH',
    'clearCacheOnLoad' => 0,
    'version' => '2.0.1',
    'state' => 'stable',
    'constraints' => [
        'depends' => [
            'typo3' => '12.0.0-12.5.99',
            'rte_ckeditor' => '12.0.0-12.5.99',
        ],
        'conflicts' => [],
        'suggests' => []
    ],

    'autoload' => [
        'psr-4' => [
            'Netresearch\\T3Cowriter\\' => 'Classes/',
        ],
    ],
];
