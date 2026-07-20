<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Tool;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;

/**
 * Thin seam over nr-llm's {@see \Netresearch\NrLlm\Service\Tool\ToolLoopService}.
 *
 * ToolLoopService is a final class, so it cannot be test-doubled directly.
 * Depending on this interface lets ToolController be unit-tested with a fake
 * runner while production wiring delegates to the real loop.
 */
interface ToolLoopRunnerInterface
{
    /**
     * Run the bounded function-calling agent loop.
     *
     * @param list<array<string, mixed>> $messages
     * @param list<string>|null          $allowedToolNames null ⇒ the globally
     *                                                     enabled tool set; a
     *                                                     list ⇒ that set
     *                                                     intersected with the
     *                                                     enabled tools
     */
    public function run(array $messages, LlmConfiguration $configuration, ?array $allowedToolNames): ToolLoopResult;
}
