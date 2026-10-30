<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Presentation;

/**
 * One top-level block of a rich text: a paragraph, a list, a table, a heading,
 * or a run of loose inline content.
 *
 * @internal
 */
final readonly class Block
{
    /**
     * @param string $html         the block's markup as stored
     * @param string $text         its plain text on one line
     * @param int    $headingLevel 1 to 6 for h1 to h6, 0 for anything else
     */
    public function __construct(
        public string $html,
        public string $text,
        public int $headingLevel = 0,
    ) {}

    public function isHeading(): bool
    {
        return $this->headingLevel > 0;
    }
}
