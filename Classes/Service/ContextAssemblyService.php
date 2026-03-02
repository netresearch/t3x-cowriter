<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Service for assembling context from TYPO3 content records.
 *
 * Fetches and formats content from tt_content records based on the requested
 * scope (element, page, ancestor pages) for use as LLM context.
 */
final readonly class ContextAssemblyService implements ContextAssemblyServiceInterface
{
    /**
     * Text fields to extract from tt_content records.
     */
    private const TEXT_FIELDS = ['header', 'subheader', 'bodytext'];

    /**
     * Maximum number of content elements to include per page.
     */
    private const MAX_ELEMENTS_PER_PAGE = 50;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Get a lightweight context summary (word count, element count).
     *
     * @return array{summary: string, wordCount: int}
     */
    public function getContextSummary(string $table, int $uid, string $field, string $scope): array
    {
        $records      = $this->fetchRecords($table, $uid, $scope);
        $text         = $this->formatRecords($records, $scope, $uid);
        $wordCount    = $this->countWords($text);
        $elementCount = count($records);

        $scopeLabels = [
            'text'        => 'current text field',
            'element'     => '1 element',
            'page'        => $elementCount . ' element' . ($elementCount !== 1 ? 's' : ''),
            'ancestors_1' => $elementCount . ' element' . ($elementCount !== 1 ? 's' : '') . ' (+1 ancestor level)',
            'ancestors_2' => $elementCount . ' element' . ($elementCount !== 1 ? 's' : '') . ' (+2 ancestor levels)',
        ];

        $label = $scopeLabels[$scope] ?? $scope;

        return [
            'summary'   => "$label, ~$wordCount words",
            'wordCount' => $wordCount,
        ];
    }

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
    ): string {
        $records = $this->fetchRecords($table, $uid, $scope);
        $text    = $this->formatRecords($records, $scope, $uid);

        // Append reference page content
        foreach ($referencePages as $refPage) {
            $refRecords = $this->fetchContentForPage($refPage['pid']);
            if ($refRecords !== []) {
                $relation = $refPage['relation'] !== '' ? " — Relation: {$refPage['relation']}" : '';
                $text .= "\n\n=== Reference page (pid={$refPage['pid']}){$relation} ===\n";
                foreach ($refRecords as $record) {
                    $text .= $this->formatSingleRecord($record);
                }
            }
        }

        return $text;
    }

    /**
     * Fetch records based on scope.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchRecords(string $table, int $uid, string $scope): array
    {
        return match ($scope) {
            'element'     => $this->fetchSingleRecord($table, $uid),
            'page'        => $this->fetchPageContent($table, $uid),
            'ancestors_1' => $this->fetchWithAncestors($table, $uid, 1),
            'ancestors_2' => $this->fetchWithAncestors($table, $uid, 2),
            default       => [],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchSingleRecord(string $table, int $uid): array
    {
        $qb     = $this->connectionPool->getQueryBuilderForTable($table);
        $result = $qb
            ->select('uid', 'pid', 'header', 'subheader', 'bodytext')
            ->from($table)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
            ->executeQuery();

        $row = $result->fetchAssociative();

        return $row !== false ? [$row] : [];
    }

    /**
     * Fetch all content on the same page as the given record.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchPageContent(string $table, int $uid): array
    {
        $record = $this->fetchSingleRecord($table, $uid);
        if ($record === []) {
            return [];
        }

        $rawPid = $record[0]['pid'] ?? 0;
        $pid    = is_numeric($rawPid) ? (int) $rawPid : 0;
        if ($pid <= 0) {
            return $record;
        }

        return $this->fetchContentForPage($pid);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchContentForPage(int $pid): array
    {
        $qb     = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $result = $qb
            ->select('uid', 'pid', 'header', 'subheader', 'bodytext')
            ->from('tt_content')
            ->where($qb->expr()->eq('pid', $qb->createNamedParameter($pid)))
            ->orderBy('sorting')
            ->setMaxResults(self::MAX_ELEMENTS_PER_PAGE)
            ->executeQuery();

        return $result->fetchAllAssociative();
    }

    /**
     * Fetch page content plus ancestor page content.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchWithAncestors(string $table, int $uid, int $levels): array
    {
        $records = $this->fetchPageContent($table, $uid);
        if ($records === []) {
            return [];
        }

        $rawPid = $records[0]['pid'] ?? 0;
        $pid    = is_numeric($rawPid) ? (int) $rawPid : 0;

        for ($i = 0; $i < $levels && $pid > 0; ++$i) {
            $parentPid = $this->getParentPageId($pid);
            if ($parentPid <= 0) {
                break;
            }

            $parentRecords = $this->fetchContentForPage($parentPid);
            $records       = array_merge($records, $parentRecords);
            $pid           = $parentPid;
        }

        return $records;
    }

    private function getParentPageId(int $pageUid): int
    {
        $qb     = $this->connectionPool->getQueryBuilderForTable('pages');
        $result = $qb
            ->select('pid')
            ->from('pages')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($pageUid)))
            ->executeQuery();

        $row = $result->fetchAssociative();

        if ($row === false) {
            return 0;
        }

        $rawPid = $row['pid'] ?? 0;

        return is_numeric($rawPid) ? (int) $rawPid : 0;
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function formatRecords(array $records, string $scope, int $currentUid): string
    {
        $parts = [];

        foreach ($records as $record) {
            $rawUid    = $record['uid'] ?? 0;
            $uid       = is_numeric($rawUid) ? (int) $rawUid : 0;
            $isCurrent = $uid === $currentUid;
            $prefix    = $isCurrent ? '=== Current content element' : '--- Content element';
            $suffix    = $isCurrent ? ' ===' : ' ---';
            $parts[]   = "$prefix (tt_content #$uid)$suffix";
            $parts[]   = $this->formatSingleRecord($record);
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function formatSingleRecord(array $record): string
    {
        $lines = [];
        foreach (self::TEXT_FIELDS as $field) {
            $raw   = $record[$field] ?? '';
            $value = trim(is_scalar($raw) ? (string) $raw : '');
            if ($value !== '') {
                $label   = ucfirst($field);
                $lines[] = "$label: $value";
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Count words in text, stripping HTML tags first.
     */
    private function countWords(string $text): int
    {
        $stripped = strip_tags($text);
        $decoded  = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $words    = preg_split('/\s+/', trim($decoded), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? count($words) : 0;
    }
}
