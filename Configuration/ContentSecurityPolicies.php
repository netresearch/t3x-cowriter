<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Type\Map;

/**
 * Content Security Policy configuration for t3_cowriter.
 *
 * This extension does NOT require any CSP modifications:
 *
 * 1. All LLM requests go through TYPO3 backend AJAX routes (not direct external API calls),
 *    so no external connect-src is needed.
 *
 * 2. AJAX URLs are injected via a JSON data element (type="application/json") which is
 *    NOT executable, then parsed by an external ES module (UrlLoader.js). This avoids
 *    the need for 'unsafe-inline' in script-src.
 *
 * @see Netresearch\T3Cowriter\EventListener\InjectAjaxUrlsListener
 */
return Map::fromEntries([
    Scope::backend(),
    new MutationCollection(
        // No mutations needed - extension is CSP-compliant by design
    ),
]);
