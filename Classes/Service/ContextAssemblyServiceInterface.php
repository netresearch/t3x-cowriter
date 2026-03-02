<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

/**
 * Interface for context assembly services.
 */
interface ContextAssemblyServiceInterface
{
    /**
     * Get a lightweight context summary (word count, element count).
     *
     * @return array{summary: string, wordCount: int}
     */
    public function getContextSummary(string $table, int $uid, string $field, string $scope): array;

    /**
     * Assemble the full context text for LLM consumption.
     *
     * @param list<array{pid: int, relation: string}> $referencePages
     */
    public function assembleContext(
        string $table,
        int $uid,
        string $field,
        string $scope,
        array $referencePages = [],
    ): string;
}
