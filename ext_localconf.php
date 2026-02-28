<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

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
 * The cache is resolved via CacheManager in RateLimiterService (not via DI reference,
 * because extension caches are not available as DI services during container compilation).
 */
if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['cowriter_ratelimit'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['cowriter_ratelimit'] = [
        'frontend' => VariableFrontend::class,
        'backend'  => Typo3DatabaseBackend::class,
        'options'  => [
            'defaultLifetime' => 120,
        ],
        'groups' => ['system'],
    ];
}
