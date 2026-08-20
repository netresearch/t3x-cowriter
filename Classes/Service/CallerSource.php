<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use Netresearch\NrLlm\Provider\Middleware\TelemetryMiddleware;

/**
 * Caller identity this extension reports to nr-llm telemetry (nr-llm ADR-177).
 *
 * Without it every cowriter request is recorded as an anonymous call and the
 * nr-llm Analytics module lists its usage and cost under "Unattributed".
 *
 * Two channels carry the identity. Calls that pass an options object tag it
 * with `AbstractOptions::withCallerSource()`; calls that go straight to
 * `LlmServiceManagerInterface::*WithConfiguration()` pass {@see self::metadata()}
 * instead, because those methods take a metadata array and no options object.
 */
final class CallerSource
{
    /**
     * The TER extension key — not the composer package name.
     */
    public const EXTENSION = 't3_cowriter';

    /**
     * Pipeline metadata naming this extension and $operation as the caller.
     *
     * @param string $operation the editor action that triggered the call
     *
     * @return array<string, string>
     */
    public static function metadata(string $operation): array
    {
        return [
            TelemetryMiddleware::METADATA_SOURCE_EXTENSION => self::EXTENSION,
            TelemetryMiddleware::METADATA_SOURCE_OPERATION => $operation,
        ];
    }
}
