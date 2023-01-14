<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 't3_cowriter',
    'description' => 'With the help of ai you can now work on a page together with a cowriter - a digital assistant that helps you to write your content.',
    'constraints' => [
        'depends' => [
            'typo3' => '10.4.0-12.4.99',
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'Netresearch\\T3Cowriter\\' => 'Classes/',
        ],
    ],
];
