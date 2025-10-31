<?php

/*
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

$EM_CONF[$_EXTKEY] = [
    'title' => 't3_cowriter',
    'description' => 'With the help of AI you can now work on a page together with a cowriter - a digital assistant that helps you to write your content.',
    'category' => 'misc',
    'author' => 'Team der Netresearch DTT GmbH',
    'author_email' => 'info@netresearch.de',
    'author_company' => 'Netresearch DTT GmbH',
    'clearCacheOnLoad' => 0,
    'version' => '2.0.0',
    'state' => 'stable',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-8.9.99',
            'typo3' => '12.4.0-13.4.99',
            'rte_ckeditor' => '12.4.0-13.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'Netresearch\\T3Cowriter\\' => 'Classes/',
        ],
    ],
];
