<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

return [
    'dependencies' => ['backend'],
    'tags'         => [
        'backend.form',
    ],
    'imports' => [
        '@netresearch/t3_cowriter/cowriter'       => 'EXT:t3_cowriter/Resources/Public/JavaScript/Ckeditor/cowriter.js',
        '@netresearch/t3_cowriter/AIService'      => 'EXT:t3_cowriter/Resources/Public/JavaScript/Ckeditor/AIService.js',
        '@netresearch/t3_cowriter/CowriterDialog' => 'EXT:t3_cowriter/Resources/Public/JavaScript/Ckeditor/CowriterDialog.js',
    ],
];
