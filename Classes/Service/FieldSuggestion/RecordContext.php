<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

/**
 * What the server read about the edited record, after the permission check.
 */
final readonly class RecordContext
{
    /**
     * @param array<string, mixed> $record  the stored row, or the few known fields of a new record
     * @param int                  $slugPid the pid TYPO3's SlugHelper derives the parent path from
     */
    public function __construct(
        public string $table,
        public string $field,
        public array $record,
        public string $storedValue,
        public string $pageTitle,
        public string $pageContent,
        public int $slugPid,
    ) {}

    public function isSiteRoot(): bool
    {
        return $this->table === 'pages'
            && ($this->slugPid === 0 || in_array($this->record['is_siteroot'] ?? 0, [1, '1', true], true));
    }
}
