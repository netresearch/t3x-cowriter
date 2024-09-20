<?php

/**
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'extension-netresearch-module' => [
        'provider' => SvgIconProvider::class,
        'source'   => 'EXT:t3_cowriter/Resources/Public/Icons/Module.svg',
    ],
    'extension-netresearch-t3-cowriter' => [
        'provider' => SvgIconProvider::class,
        'source'   => 'EXT:t3_cowriter/Resources/Public/Icons/Extension.svg',
    ],
];
