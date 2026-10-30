<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

use Netresearch\T3Cowriter\Service\Transparency\ContextSources;

/**
 * The fenced record data of a field suggestion request and what it holds.
 */
final readonly class ComposedContext
{
    public function __construct(
        public string $message,
        public ContextSources $sources,
    ) {}
}
