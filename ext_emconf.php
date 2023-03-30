<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 't3_cowriter',
    'description' => 'With the help of ai you can now work on a page together with a cowriter - a digital assistant that helps you to write your content.',
    'category' => 'misc',
    'author' => 'Netresearch DTT GmbH',
    'author_email' => 'info@netresearch.de',
    'clearCacheOnLoad' => 0,
    'version' => '1.2.1',
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
