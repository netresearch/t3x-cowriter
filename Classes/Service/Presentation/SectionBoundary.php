<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Presentation;

/**
 * Where a section begins in the block list of a text, and its title.
 *
 * A section runs from its start block to the block before the next section's
 * start. When it was cut at a heading, that heading becomes the title and is
 * left out of the body ($consumesHeading).
 *
 * @internal
 */
final readonly class SectionBoundary
{
    public function __construct(
        public string $title,
        public int $start,
        public bool $consumesHeading = false,
    ) {}

    public function withTitle(string $title): self
    {
        return new self($title, $this->start, $this->consumesHeading);
    }
}
