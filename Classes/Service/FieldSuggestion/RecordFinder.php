<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Database reads for field suggestions, as the given workspace sees them.
 *
 * Holds no permission logic: the caller ({@see RecordContextReader}) decides
 * what the current user may see. Both queries carry a WorkspaceRestriction,
 * so draft rows of other workspaces and version rows (t3ver_oid > 0) are never
 * selected, and the rows found are overlaid with the version of the user's own
 * workspace, the way FormEngine shows them to the editor.
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
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->add(new WorkspaceRestriction($workspaceId));

        $rows = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->in(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter([$languageId, -1], Connection::PARAM_INT_ARRAY),
                ),
            )
            ->orderBy('sorting')
            ->setMaxResults(self::MAX_CONTENT_ELEMENTS)
            ->executeQuery()
            ->fetchAllAssociative();

        $elements = [];
        foreach ($rows as $row) {
            $row = $this->overlay('tt_content', $row, $workspaceId);
            // The default restrictions judged the live row; the draft may be hidden.
            if ($row !== null && !in_array($row['hidden'] ?? 0, [1, '1', true], true)) {
                $elements[] = ['header' => $row['header'] ?? '', 'bodytext' => $row['bodytext'] ?? ''];
            }
        }

        return $elements;
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
