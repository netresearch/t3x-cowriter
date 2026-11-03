<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Overview;

/**
 * One row of the readiness table.
 *
 * @internal
 */
final readonly class ReadinessRow
{
    /**
     * @param list<string> $arguments values for the reason's placeholders
     * @param string       $site      the site whose configuration the row checks; '' for the installation default
     */
    public function __construct(
        public ReadinessAction $action,
        public ReadinessState $state,
        public ReadinessReason $reason,
        public array $arguments = [],
        public string $site = '',
    ) {}

    public function fixRoute(): ?string
    {
        return $this->state === ReadinessState::NotReady || $this->state === ReadinessState::Off
            ? $this->reason->fixRoute()
            : null;
    }
}
