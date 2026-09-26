<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Prompt;

use Netresearch\T3Cowriter\Domain\DTO\SavedPrompt;
use Netresearch\T3Cowriter\Domain\DTO\SavePromptRequest;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Reads and writes the prompts that editors save from the Cowriter dialog
 * (table tx_cowriter_prompt).
 *
 * A user sees the own prompts and the shared prompts of others; with
 * approval required, a shared prompt of another user only once an
 * administrator has approved it. Only the owner deletes a prompt here.
 *
 * @internal
 */
final readonly class SavedPromptRepository
{
    public const TABLE = 'tx_cowriter_prompt';

    /** Prompts one user can keep; the save endpoint refuses more. */
    public const MAX_PER_USER = 100;

    public function __construct(
        private ConnectionPool $connectionPool,
        private PromptSharingConfiguration $sharing,
    ) {}

    /**
     * The prompts the user sees: own prompts first, then by title.
     *
     * @return list<SavedPrompt>
     */
    public function visibleTo(int $userId): array
    {
        $approvalRequired = $this->sharing->sharedNeedApproval();
        $queryBuilder     = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $expr             = $queryBuilder->expr();

        $othersShared = [
            $expr->eq('shared', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
        ];
        if ($approvalRequired) {
            $othersShared[] = $expr->eq('approved', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT));
        }

        $rows = $queryBuilder
            ->select('uid', 'be_user', 'title', 'instruction', 'shared', 'approved')
            ->from(self::TABLE)
            ->where($expr->or(
                $expr->eq('be_user', $queryBuilder->createNamedParameter($userId, Connection::PARAM_INT)),
                $expr->and(...$othersShared),
            ))
            ->orderBy('title')
            ->addOrderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $own    = [];
        $others = [];
        foreach ($rows as $row) {
            $isOwn  = $this->int($row['be_user']) === $userId;
            $shared = $this->int($row['shared']) === 1;
            $prompt = new SavedPrompt(
                $this->int($row['uid']),
                $this->string($row['title']),
                $this->string($row['instruction']),
                $isOwn,
                $shared,
                $shared && $approvalRequired && $this->int($row['approved']) !== 1,
            );
            if ($isOwn) {
                $own[] = $prompt;
            } else {
                $others[] = $prompt;
            }
        }

        return [...$own, ...$others];
    }

    public function countOwnedBy(int $userId): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return $this->int($queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq(
                'be_user',
                $queryBuilder->createNamedParameter($userId, Connection::PARAM_INT),
            ))
            ->executeQuery()
            ->fetchOne());
    }

    /**
     * Stores the prompt for the user and returns it as the user sees it.
     * An administrator's shared prompt is approved on saving.
     */
    public function add(int $userId, bool $isAdmin, SavePromptRequest $prompt): SavedPrompt
    {
        $approved = $prompt->shared && $isAdmin;
        $now      = time();

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid'         => 0,
            'tstamp'      => $now,
            'crdate'      => $now,
            'be_user'     => $userId,
            'title'       => $prompt->title,
            'instruction' => $prompt->instruction,
            'shared'      => $prompt->shared ? 1 : 0,
            'approved'    => $approved ? 1 : 0,
        ]);

        return new SavedPrompt(
            (int) $connection->lastInsertId(),
            $prompt->title,
            $prompt->instruction,
            true,
            $prompt->shared,
            $prompt->shared && !$approved && $this->sharing->sharedNeedApproval(),
        );
    }

    /**
     * Marks the user's own prompt as deleted; returns false when the user
     * owns no prompt with this uid.
     */
    public function deleteOwn(int $uid, int $userId): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        $affected = $queryBuilder
            ->update(self::TABLE)
            ->set('deleted', 1, true, Connection::PARAM_INT)
            ->set('tstamp', time(), true, Connection::PARAM_INT)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('be_user', $queryBuilder->createNamedParameter($userId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeStatement();

        return $affected > 0;
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
