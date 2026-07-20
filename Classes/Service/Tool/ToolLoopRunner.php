<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Tool;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;

/**
 * Production {@see ToolLoopRunnerInterface} — delegates to nr-llm's tool loop
 * with automatic tool choice.
 */
final readonly class ToolLoopRunner implements ToolLoopRunnerInterface
{
    public function __construct(
        private ToolLoopService $toolLoopService,
    ) {}

    public function run(array $messages, LlmConfiguration $configuration, ?array $allowedToolNames): ToolLoopResult
    {
        return $this->toolLoopService->runLoop(
            $messages,
            $configuration,
            $allowedToolNames,
            ToolOptions::auto(),
        );
    }
}
