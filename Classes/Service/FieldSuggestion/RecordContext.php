<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

use Netresearch\T3Cowriter\Domain\DTO\FieldSuggestionRequest;

/**
 * What the server read about the edited record, after the permission check.
 */
final readonly class RecordContext
{
    /**
     * @param array<string, mixed>                               $record       the stored row, or the few known fields of a new record
     * @param int                                                $slugPid      the pid TYPO3's SlugHelper derives the parent path from
     * @param int                                                $maxCount     the most suggestions the field control is configured for
     * @param array<string, array{label: string, value: string}> $recordFields text fields of the record the user may read, as plain text, by field name
     */
    public function __construct(
        public string $table,
        public string $field,
        public array $record,
        public string $storedValue,
        public string $pageTitle,
        public string $pageContent,
        public int $slugPid,
        public int $maxCount = FieldSuggestionRequest::MAX_COUNT,
        public array $recordFields = [],
    ) {}

    /**
     * The same record context for another field of the record.
     */
    public function forField(string $field): self
    {
        $storedValue = $this->record[$field] ?? '';

        return new self(
            table: $this->table,
            field: $field,
            record: $this->record,
            storedValue: is_scalar($storedValue) ? (string) $storedValue : '',
            pageTitle: $this->pageTitle,
            pageContent: $this->pageContent,
            slugPid: $this->slugPid,
            maxCount: $this->maxCount,
            recordFields: $this->recordFields,
        );
    }

    /**
     * The record's text fields as lines for the model, so a content element's
     * own text, not only the page around it, decides its headline.
     *
     * @param list<string> $skip fields that are listed elsewhere in the message
     *
     * @return list<string>
     */
    public function recordFieldLines(array $skip): array
    {
        $lines = [];
        foreach ($this->recordFields as $field => $entry) {
            if (!in_array($field, $skip, true) && trim($entry['value']) !== '') {
                $lines[] = '- ' . $entry['label'] . ': ' . $entry['value'];
            }
        }

        return $lines === [] ? [] : ['Other fields of the edited record:', ...$lines];
    }

    public function isSiteRoot(): bool
    {
        return $this->table === 'pages'
            && ($this->slugPid === 0 || in_array($this->record['is_siteroot'] ?? 0, [1, '1', true], true));
    }
}
