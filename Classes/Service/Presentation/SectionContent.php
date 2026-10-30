<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Presentation;

/**
 * One section as it becomes an item record: its title and the markup of its
 * blocks, cut from the stored text.
 *
 * @internal
 */
final readonly class SectionContent
{
    public function __construct(
        public string $title,
        public string $html,
        public string $text,
    ) {}
}
