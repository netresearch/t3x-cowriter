<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Style;

/**
 * What the editor's style choices add to one request: the system message
 * ('' when they add nothing) and the target length in words, when the content
 * type has one.
 */
final readonly class StyleInstruction
{
    public function __construct(
        public string $message = '',
        public ?int $targetWords = null,
    ) {}
}
