<?php

return [
    'dependencies' => ['backend'],
    'tags' => [
        'backend.form',
    ],
    'imports' => [
        '@netresearch/t3_cowriter/cowriter' => 'EXT:t3_cowriter/Resources/Public/JavaScript/Ckeditor/cowriter.js',
        '@netresearch/t3_cowriter/progress_bar.js' => 'EXT:t3_cowriter/Resources/Public/JavaScript/progress_bar.js',
    ],
];
