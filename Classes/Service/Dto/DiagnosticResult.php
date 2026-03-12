<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Dto;

final readonly class DiagnosticResult
{
    /**
     * @param list<DiagnosticCheck> $checks
     */
    public function __construct(
        public bool $ok,
        public array $checks,
    ) {}

    public function getFirstFailure(): ?DiagnosticCheck
    {
        foreach ($this->checks as $check) {
            if (!$check->passed) {
                return $check;
            }
        }

        return null;
    }

    public function getFirstFailureMessage(): string
    {
        $failure = $this->getFirstFailure();

        return $failure !== null ? $failure->message : '';
    }
}
