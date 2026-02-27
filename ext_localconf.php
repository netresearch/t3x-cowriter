<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Register the Cowriter CKEditor preset.
 *
 * Note: LLM configuration is handled by the nr-llm extension.
 * API keys are securely stored on the server and accessed via AJAX.
 */
$GLOBALS['TYPO3_CONF_VARS']['RTE']['Presets']['cowriter']
    = 'EXT:t3_cowriter/Configuration/RTE/Cowriter.yaml';
