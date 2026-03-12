<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Dto;

final readonly class DiagnosticCheck
{
    public function __construct(
        public string $key,
        public bool $passed,
        public string $message,
        public Severity $severity,
        public ?string $fixRoute = null,
    ) {}
}
