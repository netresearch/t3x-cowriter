<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Variant;

use Doctrine\DBAL\ParameterType;
use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The optional link to EXT:contexts (netresearch/contexts): which contexts an
 * editor may give a variant, and the record settings that do it.
 *
 * No class of that extension is used. Contexts reads the settings of a
 * content element from the DataHandler datamap, in the field
 * `tx_contexts_settings` as `[context uid => [setting => '1' | '0' | '']]`,
 * and keeps the visibility setting `tx_contexts` in two flat columns of the
 * record (`tx_contexts_enable`, `tx_contexts_disable`), which its query
 * restriction reads in the frontend. Three properties of its DataHandler hook
 * (DataHandlerService, contexts 5.0) shape what is sent here:
 *
 * - a flat setting is written only for the strings '1' and '0', an integer
 *   is skipped without a word;
 * - the flat columns are rebuilt from the submitted contexts alone, so a
 *   change to one context of a record must carry every context the record
 *   has a setting for, or the others are lost;
 * - the field is an excluded field: without the editor's right on it the
 *   hook drops it, again without an error.
 *
 * The hook writes the flat columns with a direct database update, past
 * workspace versioning; assigning contexts is therefore refused in a
 * workspace.
 *
 * @internal
 */
class AudienceContexts
{
    public const SETTINGS_FIELD = 'tx_contexts_settings';

    public const VISIBILITY_SETTING = 'tx_contexts';

    private const CONTEXT_TABLE = 'tx_contexts_contexts';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * EXT:contexts is installed and offers its visibility setting for
     * content elements.
     */
    public function isInstalled(): bool
    {
        return Tca::column('tt_content', self::SETTINGS_FIELD) !== null
            && Tca::hasTable(self::CONTEXT_TABLE)
            && $this->visibilityColumns() !== null;
    }

    /**
     * Whether $backendUser may give content elements a context: contexts is
     * installed, the user works live and may edit the settings field.
     */
    public function mayAssign(BackendUserAuthentication $backendUser): bool
    {
        if (!$this->isInstalled() || $backendUser->workspace !== 0 || !$backendUser->check('tables_modify', 'tt_content')) {
            return false;
        }

        // Truthy like core's own check: `'exclude' => 1` counts as well as `true`.
        $excluded = (bool) (Tca::column('tt_content', self::SETTINGS_FIELD)['exclude'] ?? false);

        return !$excluded || $backendUser->isAdmin() || $backendUser->check('non_exclude_fields', 'tt_content:' . self::SETTINGS_FIELD);
    }

    /**
     * The contexts the record settings form of contexts offers: not deleted,
     * not disabled, not hidden in the backend. Context records are
     * admin-only to edit, not to assign.
     *
     * @return list<array{uid: int, title: string}>
     */
    public function available(): array
    {
        if (!$this->isInstalled()) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::CONTEXT_TABLE);
        $rows         = $queryBuilder->select('uid', 'title')
            ->from(self::CONTEXT_TABLE)
            ->where(
                $queryBuilder->expr()->eq('disabled', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('hide_in_backend', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->orderBy('title')
            ->addOrderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $contexts = [];
        foreach ($rows as $row) {
            $uid = is_numeric($row['uid'] ?? null) ? (int) $row['uid'] : 0;
            if ($uid > 0) {
                $contexts[] = ['uid' => $uid, 'title' => is_scalar($row['title'] ?? null) ? (string) $row['title'] : ''];
            }
        }

        return $contexts;
    }

    /**
     * The title of an assignable context, null when it is not one.
     */
    public function title(int $contextUid): ?string
    {
        foreach ($this->available() as $context) {
            if ($context['uid'] === $contextUid) {
                return $context['title'];
            }
        }

        return null;
    }

    /**
     * Settings that leave the record visible in $contextUid only: every flat
     * setting of the record is rebuilt from this, so values the copy took
     * over from its original are cleared.
     *
     * @return array<int, array<string, string>>
     */
    public function onlyIn(int $contextUid): array
    {
        return [$contextUid => array_replace($this->contextEntry([], $contextUid), [self::VISIBILITY_SETTING => '1'])];
    }

    /**
     * The record's current flat settings for every context it has one for,
     * with the visibility in $contextUid set to hidden ('0').
     *
     * @param array<string, mixed> $row the record with its flat columns
     *
     * @return array<int, array<string, string>>
     */
    public function hiddenIn(array $row, int $contextUid): array
    {
        $settings = $this->currentSettings($row);
        $settings[$contextUid] ??= $this->contextEntry($row, $contextUid);

        $settings[$contextUid][self::VISIBILITY_SETTING] = '0';

        return $settings;
    }

    /**
     * Whether the record is shown only while $contextUid is active, as its
     * flat columns say.
     *
     * @param array<string, mixed> $row
     */
    public function isEnabledIn(array $row, int $contextUid): bool
    {
        $columns = $this->visibilityColumns();

        return $columns !== null && in_array($contextUid, $this->uids($row[$columns[1]] ?? null), true);
    }

    /**
     * Whether the record is hidden while $contextUid is active.
     *
     * @param array<string, mixed> $row
     */
    public function isDisabledIn(array $row, int $contextUid): bool
    {
        $columns = $this->visibilityColumns();

        return $columns !== null && in_array($contextUid, $this->uids($row[$columns[0]] ?? null), true);
    }

    /**
     * Every context named in a flat column of the record, with the value of
     * each flat setting: '1' enabled, '0' disabled, '' none.
     *
     * @param array<string, mixed> $row
     *
     * @return array<int, array<string, string>>
     */
    public function currentSettings(array $row): array
    {
        $contextUids = [];
        foreach ($this->flatSettings() as $columns) {
            foreach ($columns as $column) {
                array_push($contextUids, ...$this->uids($row[$column] ?? null));
            }
        }

        $settings = [];
        foreach (array_unique($contextUids) as $contextUid) {
            $settings[$contextUid] = $this->contextEntry($row, $contextUid);
        }

        return $settings;
    }

    /**
     * Every flat setting of the table with its value for one context: the
     * hook reads each of them for every submitted context.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    private function contextEntry(array $row, int $contextUid): array
    {
        $entry = [];
        foreach ($this->flatSettings() as $setting => $columns) {
            $entry[$setting] = match (true) {
                in_array($contextUid, $this->uids($row[$columns[1]] ?? null), true) => '1',
                in_array($contextUid, $this->uids($row[$columns[0]] ?? null), true) => '0',
                default                                                             => '',
            };
        }

        return $entry;
    }

    /**
     * The flat columns of tt_content per setting: [disable column, enable
     * column], as contexts registers them in the TCA.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function flatSettings(): array
    {
        $contexts   = Tca::ctrl('tt_content')['tx_contexts'] ?? null;
        $configured = is_array($contexts) ? ($contexts['flatSettings'] ?? null) : null;
        $settings   = [];
        foreach (is_array($configured) ? $configured : [] as $setting => $columns) {
            if (is_string($setting) && is_array($columns) && is_string($columns[0] ?? null) && is_string($columns[1] ?? null)) {
                $settings[$setting] = [$columns[0], $columns[1]];
            }
        }

        return $settings;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function visibilityColumns(): ?array
    {
        return $this->flatSettings()[self::VISIBILITY_SETTING] ?? null;
    }

    /**
     * @return list<int>
     */
    private function uids(mixed $list): array
    {
        if (!is_scalar($list)) {
            return [];
        }

        return array_values(array_filter(
            array_map(intval(...), GeneralUtility::trimExplode(',', (string) $list, true)),
            static fn (int $uid): bool => $uid > 0,
        ));
    }
}
