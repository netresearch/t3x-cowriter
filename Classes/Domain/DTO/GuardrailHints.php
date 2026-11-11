<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

use Generator;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function array_key_exists;
use function class_exists;
use function get_object_vars;
use function is_array;
use function is_object;
use function is_string;
use function method_exists;

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

    /** The collector of nr-llm (ADR-288 there); absent before that release. */
    public const COLLECTOR = 'Netresearch\\NrLlm\\Service\\Guardrail\\GuardrailHintCollector';

    /**
     * Run a call and collect the hints nr-llm's guardrails reported during it,
     * for a call whose result carries no response metadata, such as a tool
     * loop. Without the collector the call runs as before and yields none.
     *
     * @param callable(): mixed $call
     * @param string            $collectorClass the collector to ask; a class that does not exist means none
     *
     * @return array{result: mixed, hints: list<array{kind: string, message: string}>}
     */
    public static function capture(callable $call, string $collectorClass = self::COLLECTOR): array
    {
        $collector = class_exists($collectorClass) ? GeneralUtility::makeInstance($collectorClass) : null;
        if (!is_object($collector) || !method_exists($collector, 'capture')) {
            return ['result' => $call(), 'hints' => []];
        }

        $captured = $collector->capture($call);
        $result   = is_array($captured) && array_key_exists('result', $captured) ? $captured['result'] : null;
        $hints    = is_array($captured) ? self::fromStreamReturn($captured['hints'] ?? null) : [];

        return ['result' => $result, 'hints' => $hints];
    }

    /**
     * The hints as response metadata, for a response the caller builds itself.
     *
     * @param list<array{kind: string, message: string}> $hints
     *
     * @return array<string, mixed>
     */
    public static function asMetadata(array $hints): array
    {
        return $hints === [] ? [] : [self::METADATA_KEY => $hints];
    }

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
