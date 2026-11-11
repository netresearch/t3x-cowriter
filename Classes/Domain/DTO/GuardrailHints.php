<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

use Generator;

use function get_object_vars;
use function is_array;
use function is_object;
use function is_string;

/**
 * The hints nr-llm attaches to a delivered text, as the editor reads them.
 *
 * A guardrail such as the rule-set judge of nr-llm (ADR-287 there) can let a
 * text pass and report what it found. A response carries the hints in
 * `metadata['guardrailHints']` as arrays; a stream returns them from its
 * generator as objects with the same public properties. Both are read by shape,
 * so an nr-llm without hints yields none and nothing else changes.
 *
 * @internal
 */
final class GuardrailHints
{
    public const METADATA_KEY = 'guardrailHints';

    /**
     * @param array<string, mixed>|null $metadata
     *
     * @return list<array{kind: string, message: string}>
     */
    public static function fromMetadata(?array $metadata): array
    {
        $entries = $metadata[self::METADATA_KEY] ?? null;

        return is_array($entries) ? self::fromEntries($entries) : [];
    }

    /**
     * The hints of a drained stream: what its generator returned. An nr-llm
     * that returns nothing there yields none.
     *
     * @param Generator<mixed, mixed, mixed, mixed> $stream
     *
     * @return list<array{kind: string, message: string}>
     */
    public static function fromDrainedStream(Generator $stream): array
    {
        return self::fromStreamReturn($stream->getReturn());
    }

    /**
     * @return list<array{kind: string, message: string}>
     */
    public static function fromStreamReturn(mixed $returned): array
    {
        return is_array($returned) ? self::fromEntries($returned) : [];
    }

    /**
     * @param array<mixed> $entries
     *
     * @return list<array{kind: string, message: string}>
     */
    private static function fromEntries(array $entries): array
    {
        $hints = [];
        foreach ($entries as $entry) {
            $fields = is_object($entry) ? get_object_vars($entry) : $entry;
            if (!is_array($fields) || !is_string($fields['message'] ?? null) || $fields['message'] === '') {
                continue;
            }

            $hints[] = [
                'kind'    => is_string($fields['kind'] ?? null) ? $fields['kind'] : '',
                'message' => $fields['message'],
            ];
        }

        return $hints;
    }
}
