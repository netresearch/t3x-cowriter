<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Dto;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;

/**
 * The outcome of choosing a configuration for a request: the configuration, or
 * the label key and HTTP status a route answers with instead.
 */
final readonly class ConfigurationSelection
{
    private function __construct(
        public ?LlmConfiguration $configuration,
        public string $errorLabel,
        public int $status,
    ) {}

    public static function selected(LlmConfiguration $configuration): self
    {
        return new self($configuration, '', 200);
    }

    public static function refused(string $errorLabel, int $status): self
    {
        return new self(null, $errorLabel, $status);
    }
}
