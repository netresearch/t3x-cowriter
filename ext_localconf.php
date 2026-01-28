<?php

/*
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Register the Cowriter CKEditor preset.
 *
 * Note: LLM configuration is handled by the nr-llm extension.
 * API keys are securely stored on the server and accessed via AJAX.
 */
$GLOBALS['TYPO3_CONF_VARS']['RTE']['Presets']['cowriter']
    = 'EXT:t3_cowriter/Configuration/RTE/Pluginv12.yaml';
