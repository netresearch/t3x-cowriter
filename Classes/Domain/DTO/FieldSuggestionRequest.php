<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

/**
 * Request for field suggestions from the FormEngine field control.
 *
 * Only identifies WHICH field of WHICH record the editor works on. Everything
 * the permission check and the prompt depend on (the record row, its page, the
 * page content) is read server-side; the client only contributes the value
 * currently typed into the field, which may not be saved yet.
 */
final readonly class FieldSuggestionRequest
{
    public const DEFAULT_COUNT = 3;

    public const MIN_COUNT = 1;

    public const MAX_COUNT = 5;

    /**
     * Upper bound for the in-form value sent along; longer values are cut.
     */
    public const MAX_VALUE_LENGTH = 2000;

    public function __construct(
        public string $table,
        public string $field,
        public int $uid,
        public int $pid,
        public string $currentValue,
        public int $count,
    ) {}

    /**
     * @param array<string, mixed> $body decoded JSON request body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            table: self::identifier($body['table'] ?? null),
            field: self::identifier($body['field'] ?? null),
            // A new record carries a "NEW…" placeholder instead of a uid.
            uid: self::nonNegativeInt($body['uid'] ?? null),
            pid: self::nonNegativeInt($body['pid'] ?? null),
            currentValue: is_string($body['currentValue'] ?? null)
                ? mb_substr($body['currentValue'], 0, self::MAX_VALUE_LENGTH, 'UTF-8')
                : '',
            count: self::count($body['count'] ?? null),
        );
    }

    public function isValid(): bool
    {
        return $this->table !== '' && $this->field !== '';
    }

    public function isNewRecord(): bool
    {
        return $this->uid === 0;
    }

    private static function identifier(mixed $value): string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_]{1,64}$/', $value) === 1 ? $value : '';
    }

    private static function nonNegativeInt(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }

    private static function count(mixed $value): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (!is_int($value)) {
            return self::DEFAULT_COUNT;
        }

        return max(self::MIN_COUNT, min(self::MAX_COUNT, $value));
    }
}
