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
