<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The site a record belongs to, so a request about that record runs with the
 * site's LLM configuration and term lists (nr-llm ADR-244).
 *
 * A page belongs to its own site, any other record to the site of its page.
 * Null when there is no record, the record cannot be read, or its page lies
 * outside every site: the request then runs on the installation's default.
 *
 * @internal
 */
final readonly class RecordSite
{
    public function __construct(
        private RecordFinder $recordFinder,
        private SiteFinder $siteFinder,
    ) {}

    /**
     * @param array{table: string, uid: int, field: string}|null $record
     */
    public function identifierFor(?array $record, int $workspaceId = 0): ?string
    {
        if ($record === null) {
            return null;
        }

        $row = $this->recordFinder->findRecord($record['table'], $record['uid'], $workspaceId);
        if ($row === null) {
            return null;
        }

        $page = $record['table'] === 'pages' ? $this->defaultLanguagePage($record['uid'], $row) : $this->intValue($row['pid'] ?? 0);
        if ($page <= 0) {
            return null;
        }

        try {
            return $this->siteFinder->getSiteByPageId($page)->getIdentifier();
        } catch (SiteNotFoundException) {
            return null;
        }
    }

    /**
     * A translated page stands for its default-language page: a translated
     * site root has only itself in its rootline, and the site is keyed by the
     * default-language root (as core's SiteResolving form data provider does).
     *
     * @param array<string, mixed> $row
     */
    private function defaultLanguagePage(int $uid, array $row): int
    {
        $parent = $this->intValue($row['l10n_parent'] ?? 0);

        return $parent > 0 ? $parent : $uid;
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
