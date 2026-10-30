<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Presentation;

use Netresearch\T3Cowriter\Service\Transparency\ModelUse;

/**
 * The model's split of a text into sections, with what answered it.
 */
final readonly class SectionProposal
{
    /**
     * @param list<SectionBoundary> $sections empty when the answer gives no usable split
     */
    public function __construct(
        public array $sections,
        public ModelUse $modelUse,
    ) {}
}
