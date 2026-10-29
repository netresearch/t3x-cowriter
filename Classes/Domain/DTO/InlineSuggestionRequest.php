<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

/**
 * Request DTO for an inline suggestion: the text before the editor's cursor
 * and, optionally, the record it belongs to.
 *
 * @internal
 */
final readonly class InlineSuggestionRequest
{
    /**
     * Longest text before the cursor the route accepts, in characters. The
     * editor sends at most this many (the end of the text before the cursor).
     */
    public const MAX_TEXT_LENGTH = 1000;

    /**
     * @param string                              $text          the text before the cursor, as sent: a trailing space
     *                                                           decides whether the suggestion starts with one
     * @param array{table: string, uid: int}|null $recordContext the record the editor works on, as the client names it;
     *                                                           only used to look up the record's language on the server
     */
    public function __construct(
        public string $text,
        public ?array $recordContext = null,
    ) {}

    public function isTooLong(): bool
    {
        return mb_strlen($this->text) > self::MAX_TEXT_LENGTH;
    }

    /**
     * Whether the text has fewer than $minChars characters apart from
     * surrounding whitespace.
     */
    public function isShorterThan(int $minChars): bool
    {
        return mb_strlen(trim($this->text)) < $minChars;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromRequestBody(array $body): self
    {
        $text = $body['text'] ?? '';

        return new self(
            text: is_string($text) ? $text : '',
            recordContext: self::extractRecordContext($body),
        );
    }

    /**
     * Table and uid of the record context; null when it is missing or
     * malformed. A malformed context is not an error: the suggestion is then
     * asked for without a language.
     *
     * @param array<string, mixed> $data
     *
     * @return array{table: string, uid: int}|null
     */
    private static function extractRecordContext(array $data): ?array
    {
        $rc = $data['recordContext'] ?? null;
        if (!is_array($rc)) {
            return null;
        }

        $table = is_string($rc['table'] ?? null) ? $rc['table'] : '';
        $uid   = is_int($rc['uid'] ?? null) ? $rc['uid'] : 0;

        if ($uid <= 0 || preg_match('/^[a-z][a-z0-9_]*$/i', $table) !== 1) {
            return null;
        }

        return ['table' => $table, 'uid' => $uid];
    }
}
