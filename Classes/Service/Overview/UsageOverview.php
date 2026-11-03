<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Overview;

/**
 * The cowriter's usage in a period as nr-llm recorded it.
 *
 * @internal
 */
final readonly class UsageOverview
{
    /**
     * @param list<UsageOperation> $operations
     * @param bool                 $ownOnly      the figures are the viewer's own, not the installation's
     * @param int|null             $completeFrom Unix time from which the per-operation counts are complete,
     *                                           when that is later than the period's start; null when they cover it
     */
    public function __construct(
        public int $days,
        public int $from,
        public int $to,
        public bool $ownOnly,
        public float $estimatedCost,
        public int $requests,
        public int $tokens,
        public array $operations,
        public bool $telemetryEnabled,
        public ?int $completeFrom,
    ) {}

    public function callCount(): int
    {
        return array_sum(array_map(static fn (UsageOperation $operation): int => $operation->calls, $this->operations));
    }

    public function failedCount(): int
    {
        return array_sum(array_map(static fn (UsageOperation $operation): int => $operation->failedCalls, $this->operations));
    }
}
