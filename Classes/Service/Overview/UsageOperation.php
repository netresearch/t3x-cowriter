<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Overview;

/**
 * Calls of one cowriter operation in the period, with the label it is shown
 * under: a label key (locallang_mod_status.xlf, usage.operation.<key>) with
 * an optional argument, or, for a name the cowriter does not know, the name
 * itself.
 *
 * @internal
 */
final readonly class UsageOperation
{
    public function __construct(
        public string $operation,
        public ?string $labelKey,
        public string $labelArgument,
        public int $calls,
        public int $failedCalls,
        public int $lastCallAt,
    ) {}
}
