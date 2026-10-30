<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

use Netresearch\T3Cowriter\Service\Transparency\ContextSources;
use Netresearch\T3Cowriter\Service\Transparency\ModelUse;

/**
 * The answer of one field suggestion or field overview request, with what
 * answered it and what it was sent.
 *
 * @template T of string|FieldReview
 */
final readonly class SuggestionResult
{
    /**
     * @param list<T> $items the suggestions (strings) or the reviews of the overview
     */
    public function __construct(
        public array $items,
        public ModelUse $modelUse,
        public ContextSources $sources,
    ) {}
}
