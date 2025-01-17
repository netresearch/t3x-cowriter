<?php

/**
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

return [
    'dependencies' => [
        'core',
        'backend',
        'rte_ckeditor',
    ],
    'tags' => [
        'backend.form',
    ],
    'imports' => [
        '@netresearch/t3-cowriter/cowriter'        => 'EXT:t3_cowriter/Resources/Public/JavaScript/Ckeditor/cowriter.js',
        '@netresearch/t3-cowriter/progress_bar.js' => 'EXT:t3_cowriter/Resources/Public/JavaScript/progress_bar.js',
    ],
];
