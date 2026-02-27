<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

defined('TYPO3') || exit;

/**
 * Register the Cowriter CKEditor preset.
 *
 * Note: LLM configuration is handled by the nr-llm extension.
 * API keys are securely stored on the server and accessed via AJAX.
 */
$GLOBALS['TYPO3_CONF_VARS']['RTE']['Presets']['cowriter']
    = 'EXT:t3_cowriter/Configuration/RTE/Cowriter.yaml';

/**
 * Register a persistent cache for rate limiting.
 *
 * Uses the database backend by default to persist rate limit data across requests.
 * cache.runtime would reset per-request, making rate limiting non-functional.
 */
if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['cowriter_ratelimit'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['cowriter_ratelimit'] = [
        'frontend' => TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend'  => TYPO3\CMS\Core\Cache\Backend\DatabaseBackend::class,
        'options'  => [
            'defaultLifetime' => 120,
        ],
        'groups' => ['system'],
    ];
}
