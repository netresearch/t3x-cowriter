<?php

/**
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

$EM_CONF['t3_cowriter'] = [
    'title'          => 'Netresearch: Cowriter',
    'description'    => 'TYPO3 extension that allows you to work on a page using an AI-powered co-author – a digital assistant that helps you write your content.',
    'category'       => 'module',
    'author'         => 'Thomas Schöne, Martin Wunderlich, Sebastian Koschel, Rico Sonntag',
    'author_email'   => 'thomas.schoene@netresearch.de, martin.wunderlich@netresearh.de, sebastian.koschel@netresearch.de, rico.sonntag@netresearch.de',
    'author_company' => 'Netresearch DTT GmbH',
    'state'          => 'stable',
    'version'        => '2.0.0',
    'constraints'    => [
        'depends'   => [
            'typo3' => '12.4.0-12.99.99',
        ],
        'conflicts' => [
        ],
        'suggests'  => [
        ],
    ],
];
