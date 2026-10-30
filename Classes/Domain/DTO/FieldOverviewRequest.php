<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

/**
 * Request of the "all fields" overview: one record and the fields with the
 * suggestion control the form shows, each with the value it holds right now.
 *
 * The client only names fields; the server decides which of them are served.
 */
final readonly class FieldOverviewRequest
{
    /**
     * More fields than any record form carries with the control: a request
     * above it is cut, not refused.
     */
    public const MAX_FIELDS = 30;

    /**
     * @param array<string, string> $fields current form value by field name
     */
    public function __construct(
        public string $table,
        public int $uid,
        public int $pid,
        public array $fields,
    ) {}

    /**
     * @param array<array-key, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $fields = [];
        $raw    = is_array($body['fields'] ?? null) ? $body['fields'] : [];
        foreach ($raw as $field => $value) {
            if (count($fields) >= self::MAX_FIELDS) {
                break;
            }

            if (is_string($field) && self::identifier($field) !== '') {
                $fields[$field] = is_string($value)
                    ? mb_substr($value, 0, FieldSuggestionRequest::MAX_VALUE_LENGTH, 'UTF-8')
                    : '';
            }
        }

        return new self(
            table: self::identifier($body['table'] ?? null),
            // A new record carries a "NEW…" placeholder instead of a uid.
            uid: self::nonNegativeInt($body['uid'] ?? null),
            pid: self::nonNegativeInt($body['pid'] ?? null),
            fields: $fields,
        );
    }

    public function isValid(): bool
    {
        return $this->table !== '' && $this->fields !== [];
    }

    private static function identifier(mixed $value): string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_]{1,64}$/', $value) === 1 ? $value : '';
    }

    private static function nonNegativeInt(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
