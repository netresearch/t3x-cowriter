<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Database reads for field suggestions and the task context, as the given
 * workspace sees them.
 *
 * Holds no permission logic: the caller ({@see RecordContextReader},
 * {@see \Netresearch\T3Cowriter\Service\ContextAssemblyService}) decides
 * what the current user may see. Every query carries a WorkspaceRestriction,
 * so draft rows of other workspaces and version rows (t3ver_oid > 0) are never
 * selected, except the move pointers of the user's own workspace, which
 * findVisibleContent() lists under the live uid. The rows found are overlaid
 * with the version of the user's own workspace, the way FormEngine shows them
 * to the editor.
 */
class RecordFinder
{
    /**
     * Content elements taken into the page context at most.
     */
    public const MAX_CONTENT_ELEMENTS = 50;

    /**
     * Subpage rows read at most when the neighbours of a page are looked up.
     */
    public const MAX_SUBPAGES = 100;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * The row of a live record or of a record new in $workspaceId, with that
     * workspace's version laid over it. Hidden and time-restricted records are
     * included: an editor edits those too. A uid naming a version row, a record
     * of another workspace, or a record deleted in $workspaceId gives null.
     *
     * @return array<string, mixed>|null
     */
    public function findRecord(string $table, int $uid, int $workspaceId): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspaceId));

        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $this->overlay($table, $row, $workspaceId);
    }

    /**
     * Header and bodytext of the visible content elements on a page, in the
     * given language plus "all languages", as $workspaceId sees them.
     *
     * @return list<array<string, mixed>>
     */
    public function findPageContent(int $pageUid, int $languageId, int $workspaceId): array
    {
        $elements = [];
        foreach ($this->findVisibleContent($pageUid, $workspaceId, [$languageId, -1]) as $row) {
            $elements[] = ['header' => $row['header'] ?? '', 'bodytext' => $row['bodytext'] ?? ''];
        }

        return $elements;
    }

    /**
     * The whole rows of the visible content elements on a page, in every
     * language (or only in $languageIds), as $workspaceId sees them. The
     * caller decides which of them the user may read.
     *
     * @param list<int>|null $languageIds
     *
     * @return list<array<string, mixed>>
     */
    public function findVisibleContent(int $pageUid, int $workspaceId, ?array $languageIds = null): array
    {
        // Hidden and start/end time are judged after the overlay, on the row the
        // workspace shows: a draft can show what is hidden live, and the reverse.
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspaceId));

        $constraints = [
            $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
        ];
        if ($languageIds !== null) {
            $constraints[] = $queryBuilder->expr()->in(
                'sys_language_uid',
                $queryBuilder->createNamedParameter($languageIds, Connection::PARAM_INT_ARRAY),
            );
        }

        $rows = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where(...$constraints)
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        $elements = [];
        foreach ($rows as $row) {
            $liveUid = $row['t3ver_oid'] ?? 0;
            if (is_numeric($liveUid) && (int) $liveUid > 0) {
                // The WorkspaceRestriction lets through the version row of a record
                // this workspace moved onto the page; it stands for the live record.
                $row['uid'] = (int) $liveUid;
            } else {
                $row = $this->overlay('tt_content', $row, $workspaceId);
            }

            // A record this workspace moved to another page is no longer here.
            if ($row !== null && $this->isOnPage($row, $pageUid) && $this->isVisible($row)) {
                // Keyed by live uid: a record the workspace reordered on this page
                // comes as its overlaid live row and as its version row.
                $elements[$this->intValue($row['uid'] ?? 0)] = $row;
            }
        }

        // The query sorted by the live position; the workspace may have moved records.
        usort($elements, fn (array $a, array $b): int => $this->intValue($a['sorting'] ?? 0) <=> $this->intValue($b['sorting'] ?? 0));

        // Capped only now: hidden, expired and moved-away rows must not take places.
        return array_slice($elements, 0, self::MAX_CONTENT_ELEMENTS);
    }

    /**
     * The visible default-language subpages of a page, by their position in
     * the page tree, as $workspaceId sees them: hidden, time-restricted and
     * deleted pages are left out, a page this workspace moved away is not
     * listed and one it moved here is. Of a page with very many subpages only
     * the first MAX_SUBPAGES rows are read. The caller decides which of them
     * the user may see.
     *
     * @return list<array<string, mixed>>
     */
    public function findVisibleSubpages(int $pid, int $workspaceId): array
    {
        $languageField = Tca::languageField('pages');

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspaceId));

        $constraints = [
            $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)),
        ];
        if ($languageField !== null) {
            $constraints[] = $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
        }

        $rows = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where(...$constraints)
            ->orderBy('sorting')
            ->setMaxResults(self::MAX_SUBPAGES)
            ->executeQuery()
            ->fetchAllAssociative();

        $pages = [];
        foreach ($rows as $row) {
            $liveUid = $row['t3ver_oid'] ?? 0;
            if (is_numeric($liveUid) && (int) $liveUid > 0) {
                // The version row of a page this workspace moved here stands for the live page.
                $row['uid'] = (int) $liveUid;
            } else {
                $row = $this->overlay('pages', $row, $workspaceId);
            }

            if ($row !== null && $this->isOnPage($row, $pid) && $this->isVisible($row)) {
                $pages[$this->intValue($row['uid'] ?? 0)] = $row;
            }
        }

        usort($pages, fn (array $a, array $b): int => $this->intValue($a['sorting'] ?? 0) <=> $this->intValue($b['sorting'] ?? 0));

        return $pages;
    }

    /**
     * The translation of a page into $languageId, as $workspaceId sees it,
     * hidden or not; null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function findPageTranslation(int $pageUid, int $languageId, int $workspaceId): ?array
    {
        $languageField = Tca::languageField('pages');
        $pointerField  = Tca::transOrigPointerField('pages');
        if ($languageId <= 0 || $languageField === null || $pointerField === null) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspaceId));

        $rows = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq($pointerField, $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $row = $this->overlay('pages', $row, $workspaceId);
            if ($row !== null) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whether a row is shown to visitors: not hidden, within its start and
     * end time.
     *
     * @param array<string, mixed> $row
     */
    public function isVisibleRow(array $row): bool
    {
        return $this->isVisible($row);
    }

    /**
     * How many records are translations of $uid (their transOrigPointerField
     * points to it), as $workspaceId sees them.
     */
    public function countTranslations(string $table, string $pointerField, int $uid, int $workspaceId): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspaceId));

        $count = 0;
        foreach ($queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq($pointerField, $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAllAssociative() as $row) {
            // A translation this workspace deletes does not count.
            if ($this->overlay($table, $row, $workspaceId) !== null) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * The file references of a record in the given fields, in their order,
     * as $workspaceId sees them.
     *
     * @param list<string> $fields
     *
     * @return list<array<string, mixed>>
     */
    public function findFileReferences(string $table, int $uid, array $fields, int $workspaceId): array
    {
        if ($fields === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($workspaceId));

        $rows = $queryBuilder
            ->select('*')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('fieldname', $queryBuilder->createNamedParameter($fields, Connection::PARAM_STR_ARRAY)),
            )
            ->orderBy('fieldname')
            ->addOrderBy('sorting_foreign')
            ->executeQuery()
            ->fetchAllAssociative();

        $references = [];
        foreach ($rows as $row) {
            $row = $this->overlay('sys_file_reference', $row, $workspaceId);
            if ($row !== null) {
                $references[] = $row;
            }
        }

        return $references;
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Not hidden, and within its start and end time.
     *
     * @param array<string, mixed> $row
     */
    private function isVisible(array $row): bool
    {
        $now = GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp');
        $now = is_int($now) ? $now : time();

        $starttime = $this->intValue($row['starttime'] ?? 0);
        $endtime   = $this->intValue($row['endtime'] ?? 0);

        return $this->intValue($row['hidden'] ?? 0) === 0
            && ($starttime === 0 || $starttime <= $now)
            && ($endtime === 0 || $endtime > $now);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isOnPage(array $row, int $pageUid): bool
    {
        $pid = $row['pid'] ?? 0;

        return is_numeric($pid) && (int) $pid === $pageUid;
    }

    /**
     * The workspace version of $row, or null when the workspace deletes it.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function overlay(string $table, array $row, int $workspaceId): ?array
    {
        if ($workspaceId > 0) {
            BackendUtility::workspaceOL($table, $row, $workspaceId);
        }

        if (!is_array($row)) {
            return null;
        }

        $state = $row['t3ver_state'] ?? 0;
        if (is_numeric($state) && (int) $state === VersionState::DELETE_PLACEHOLDER->value) {
            return null;
        }

        /** @var array<string, mixed> $row workspaceOL() swaps in another row of the same table */
        return $row;
    }
}
