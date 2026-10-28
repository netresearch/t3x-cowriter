<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use Netresearch\NrLlm\Domain\Model\StructuredCompletionResponse;

/**
 * The payload of nr-llm's structured completion: the decoded array that
 * completeStructured() and completeStructuredForConfiguration() carry in the
 * `data` property of their StructuredCompletionResponse (nr-llm ADR-211).
 */
final class StructuredAnswer
{
    /**
     * @param StructuredCompletionResponse|null $answer the return value of a structured completion call;
     *                                                  null is what a nullsafe call on an absent service yields
     *
     * @return array<mixed> the payload; empty for null
     */
    public static function payload(?StructuredCompletionResponse $answer): array
    {
        return $answer->data ?? [];
    }
}
