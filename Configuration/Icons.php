<?php

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
