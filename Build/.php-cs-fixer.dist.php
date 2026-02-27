<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Dist config for PHP-CS-Fixer (repo-wide).
 * Copy to .php-cs-fixer.php locally if you want to override anything.
 */

if (PHP_SAPI !== 'cli') {
    die('Run this only from the command line.');
}

$header = <<<EOF
Copyright (c) 2025-2026 Netresearch DTT GmbH
SPDX-License-Identifier: GPL-3.0-or-later
EOF;

$repoRoot = __DIR__ . '/..';

$finder = PhpCsFixer\Finder::create()
    ->in($repoRoot)
    ->exclude(['.Build', 'config', 'node_modules', 'var']);

$config = new PhpCsFixer\Config();
$config
    // Enable fixers that might change behavior (you control which via setRules)
    ->setRiskyAllowed(true)
    ->setRules([
        // Base style + modern PHP (8.2+ required)
        '@Symfony'          => true,
        '@PER-CS2x0'        => true,
        '@PHP8x2Migration'  => true,

        // --- Your original customizations (kept / refined) ---
        'declare_strict_types' => true,

        'concat_space' => ['spacing' => 'one'],

        'header_comment' => [
            'header'       => $header,
            'comment_type' => 'comment',
            'location'     => 'after_open',
            'separate'     => 'both',
        ],

        // Keep docblock behavior stable
        'phpdoc_to_comment'          => false,
        'no_superfluous_phpdoc_tags' => false,

        'phpdoc_separation' => [
            'groups' => [['author', 'license', 'link']],
        ],

        'no_alias_functions' => true,

        'binary_operator_spaces' => [
            'operators' => [
                '='  => 'align_single_space_minimal',
                '=>' => 'align_single_space_minimal',
            ],
        ],

        'yoda_style' => [
            'equal'                => false,
            'identical'            => false,
            'less_and_greater'     => false,
            'always_move_variable' => false,
        ],

        'global_namespace_import' => [
            'import_classes'   => true,
            'import_constants' => true,
            'import_functions' => true,
        ],

        'function_declaration' => [
            'closure_function_spacing' => 'one',
            'closure_fn_spacing'       => 'one',
        ],

        'trailing_comma_in_multiline' => [
            'elements' => ['arrays', 'arguments'],
        ],

        'no_unused_imports'               => true,
        'ordered_imports'                 => ['sort_algorithm' => 'alpha'],
        'whitespace_after_comma_in_array' => ['ensure_single_space' => true],
        'single_line_throw'               => false,
        'self_accessor'                   => false,
    ])
    ->setFinder($finder);

return $config;
