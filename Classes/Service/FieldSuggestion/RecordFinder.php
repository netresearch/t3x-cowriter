<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Database reads for field suggestions. Holds no permission logic: the caller
 * ({@see RecordContextReader}) decides what the current user may see.
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
     * The full stored row of a record that is not deleted. Hidden and
     * time-restricted records are included: an editor edits those too.
     *
     * @return array<string, mixed>|null
     */
    public function findRecord(string $table, int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * Header and bodytext of the visible content elements on a page, in the
     * given language plus "all languages".
     *
     * @return list<array<string, mixed>>
     */
    public function findPageContent(int $pageUid, int $languageId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');

        return $queryBuilder
            ->select('header', 'bodytext')
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
    }
}
