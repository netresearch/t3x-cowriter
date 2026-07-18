<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

/**
 * The kinds of nr-llm failure the cowriter controllers react to differently
 * (a user-facing message, a link to the setup-status page). Produced by
 * {@see LlmErrorClassifier} from nr-llm's typed exceptions instead of scraping
 * exception message text.
 */
enum LlmErrorKind
{
    /** No active/default LLM configuration or provider is set up. */
    case Configuration;

    /** The provider rejected the API key (HTTP 401). */
    case Authentication;

    /** The provider throttled the request (HTTP 429). */
    case RateLimit;

    /** Anything else — surface a generic "check the log" message. */
    case Unknown;
}
