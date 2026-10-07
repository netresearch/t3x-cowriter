<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use UnexpectedValueException;

/**
 * The payload of nr-llm's structured completion, on every supported nr-llm line.
 *
 * nr-llm 0.38 returns the decoded payload of completeStructured() and
 * completeStructuredForConfiguration() as an array; from 0.39 on it returns a
 * StructuredCompletionResponse that carries the same array as its `data`
 * property (ADR-211). This is the only place that knows about the
 * difference. The class is not named here, because it does not exist on 0.38.
 */
final class StructuredAnswer
{
    /**
     * @param array<mixed>|object|null $answer the return value of a structured completion call;
     *                                         null where the completion service is not available
     *
     * @return array<mixed> the payload; empty for null
     *
     * @throws UnexpectedValueException for an object that carries no payload array
     */
    public static function payload(array|object|null $answer): array
    {
        if ($answer === null || is_array($answer)) {
            return $answer ?? [];
        }

        $data = get_object_vars($answer)['data'] ?? null;
        if (!is_array($data)) {
            throw new UnexpectedValueException(
                sprintf('A structured completion returned %s without a payload array in "data".', $answer::class),
                1791371837,
            );
        }

        return $data;
    }
}
