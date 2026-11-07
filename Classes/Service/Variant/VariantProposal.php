<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Variant;

use Netresearch\T3Cowriter\Service\Transparency\ModelUse;

/**
 * The model's rewrite of an element for an audience, before the editor
 * reviewed it.
 *
 * @internal
 */
final readonly class VariantProposal
{
    /**
     * @param string $bodytext the model's HTML, unescaped; the dialog sanitises it before showing it
     */
    public function __construct(
        public string $header,
        public string $bodytext,
        public ModelUse $modelUse,
    ) {}

    public function isEmpty(): bool
    {
        return $this->bodytext === '';
    }
}
