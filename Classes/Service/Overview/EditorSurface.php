<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Overview;

/**
 * One place an editor finds the cowriter, and whether the extension
 * configuration switches it on. Labelled by surface.<key>.
 *
 * @internal
 */
final readonly class EditorSurface
{
    /**
     * @param string $setting the extension setting that switches it, '' when none does
     */
    public function __construct(
        public string $key,
        public bool $enabled,
        public string $setting = '',
    ) {}
}
