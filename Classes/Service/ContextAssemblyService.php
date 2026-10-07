<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Service for assembling context from TYPO3 content records.
 *
 * Fetches and formats content from tt_content records based on the requested
 * scope (element, page, ancestor pages) for use as LLM context.
 * Every record is read as the current backend user may read it: content
 * elements only with read access to tt_content, only on pages the user may
 * show (records on the root level only for administrators), only in
 * languages the user may access, and as the user's workspace shows them
 * (drafts of other workspaces are never read).
 */
final readonly class ContextAssemblyService implements ContextAssemblyServiceInterface
{
    /**
     * Tables allowed for context assembly queries.
     */
    private const ALLOWED_TABLES = ['tt_content'];

    /**
     * Text fields to extract from tt_content records.
     */
    private const TEXT_FIELDS = ['header', 'subheader', 'bodytext'];

    private RecordFinder $recordFinder;

    public function __construct(
        ConnectionPool $connectionPool,
        ?RecordFinder $recordFinder = null,
    ) {
        $this->recordFinder = $recordFinder ?? new RecordFinder($connectionPool);
    }

    /**
     * The current backend user, if there is one and they may read content
     * elements at all.
     */
    private function contentReader(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return null;
        }

        if (!$backendUser->isAdmin() && !$backendUser->check('tables_select', 'tt_content')) {
            return null;
        }

        return $backendUser;
    }

    /**
     * The workspace the user works in; 0 is live.
     */
    private function workspaceId(BackendUserAuthentication $backendUser): int
    {
        return max(0, $backendUser->workspace);
    }

    /**
     * Check if the backend user has read access to the given page. Records on
     * the root level (pid 0) are an administrator's affair.
     */
    private function userHasPageAccess(int $pid, BackendUserAuthentication $backendUser): bool
    {
        // Admin users have unrestricted access
        if ($backendUser->isAdmin()) {
            return true;
        }

        if ($pid <= 0) {
            return false;
        }

        // The whole page row: core calcPerms() needs perms_userid/perms_user/
        // perms_groupid/perms_group/perms_everybody to reflect the user's real
        // rights; a uid-only row gives Permission::NOTHING for every non-admin.
        $page = $this->recordFinder->findRecord('pages', $pid, $this->workspaceId($backendUser));

        return $page !== null && $backendUser->doesUserHaveAccess($page, Permission::PAGE_SHOW);
    }

    /**
     * The languages the user may read content in, "all languages" (-1)
     * included; null when the user is not limited to some languages.
     *
     * @return list<int>|null
     */
    private function allowedLanguageIds(BackendUserAuthentication $backendUser): ?array
    {
        $allowed = $backendUser->groupData['allowed_languages'] ?? '';
        if ($backendUser->isAdmin() || !is_string($allowed) || trim($allowed) === '') {
            return null;
        }

        $ids = [-1];
        foreach (explode(',', $allowed) as $id) {
            if (is_numeric(trim($id))) {
                $ids[] = (int) trim($id);
            }
        }

        return $ids;
    }

    /**
     * Whether the user may read a content element in its language.
     *
     * @param array<string, mixed> $record
     */
    private function userHasLanguageAccess(array $record, BackendUserAuthentication $backendUser): bool
    {
        if ($backendUser->isAdmin()) {
            return true;
        }

        $rawLanguage = $record['sys_language_uid'] ?? 0;

        return $backendUser->checkLanguageAccess(is_numeric($rawLanguage) ? (int) $rawLanguage : 0);
    }

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
            'summary'   => sprintf('%s, ~%d words', $label, $wordCount),
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
                $relation = $refPage['relation'] !== '' ? ' — Relation: ' . $refPage['relation'] : '';
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
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            return [];
        }

        $backendUser = $this->contentReader();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return [];
        }

        $row = $this->recordFinder->findRecord($table, $uid, $this->workspaceId($backendUser));
        if ($row === null || !$this->userHasLanguageAccess($row, $backendUser)) {
            return [];
        }

        // Enforce page access check for every record
        $rawPid = $row['pid'] ?? 0;
        $pid    = is_numeric($rawPid) ? (int) $rawPid : 0;
        if (!$this->userHasPageAccess($pid, $backendUser)) {
            return [];
        }

        return [$row];
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
        $backendUser = $this->contentReader();
        if (!$backendUser instanceof BackendUserAuthentication || !$this->userHasPageAccess($pid, $backendUser)) {
            return [];
        }

        // The language rule goes into the query, so that elements in other
        // languages do not take places under the element cap.
        $records = [];
        foreach ($this->recordFinder->findVisibleContent($pid, $this->workspaceId($backendUser), $this->allowedLanguageIds($backendUser)) as $record) {
            if ($this->userHasLanguageAccess($record, $backendUser)) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Fetch page content plus ancestor page content.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchWithAncestors(string $table, int $uid, int $levels): array
    {
        $records     = $this->fetchPageContent($table, $uid);
        $backendUser = $this->contentReader();
        if ($records === [] || !$backendUser instanceof BackendUserAuthentication) {
            return [];
        }

        // The walk starts at the page of the requested element, which the first
        // record of its page need not tell.
        $element = $this->fetchSingleRecord($table, $uid);
        $rawPid  = $element[0]['pid'] ?? 0;
        $pid     = is_numeric($rawPid) ? (int) $rawPid : 0;

        for ($i = 0; $i < $levels && $pid > 0; ++$i) {
            $parentPid = $this->getParentPageId($pid, $this->workspaceId($backendUser));
            if ($parentPid <= 0) {
                break;
            }

            $parentRecords = $this->fetchContentForPage($parentPid);
            $records       = array_merge($records, $parentRecords);
            $pid           = $parentPid;
        }

        return $records;
    }

    /**
     * The parent of a page in the tree the user's workspace shows, so a page
     * moved in that workspace is followed to its new parent.
     */
    private function getParentPageId(int $pageUid, int $workspaceId): int
    {
        $row = $this->recordFinder->findRecord('pages', $pageUid, $workspaceId);
        if ($row === null) {
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
            $parts[]   = sprintf('%s (tt_content #%d)%s', $prefix, $uid, $suffix);
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
                $lines[] = sprintf('%s: %s', $label, $value);
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
