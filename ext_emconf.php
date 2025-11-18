<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 't3_cowriter',
    'description' => 'With the help of ai you can now work on a page together with a cowriter - a digital assistant that helps you to write your content.',
    'category' => 'misc',
    'author' => 'Team der Netresearch DTT GmbH',
    'author_email' => 'info@netresearch.de',
    'author_company' => 'Netresearch DTT GmbH',
    'clearCacheOnLoad' => 0,
    'version' => '1.2.6',
    'state' => 'stable',
    'constraints' => [
        'depends' => [
            'typo3' => '9.5.0-11.5.99',
            'rte_ckeditor' => '9.5.0-11.5.99',
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
