<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Vision;

use InvalidArgumentException;
use Locale as IntlLocale;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Resolves the language of the record the editor works on from server-side
 * data only: the client names table and uid, the language comes from the
 * record's own language field and the site configuration of its page.
 *
 * The record is read as the current backend user may read it, with the same
 * checks as {@see \Netresearch\T3Cowriter\Service\ContextAssemblyService}:
 * read access to the table, the record's language, show access to its page,
 * and the row as the user's workspace shows it. A record on the root level
 * belongs to no site and gets no language. Whenever a check fails, the answer
 * is null, the same as for a record that does not exist.
 *
 * @internal
 */
final readonly class RecordLanguageResolver implements RecordLanguageResolverInterface
{
    public function __construct(
        private RecordFinder $recordFinder,
        private SiteFinder $siteFinder,
    ) {}

    public function languageName(string $table, int $uid): ?string
    {
        // Only a table with a language field has a language to resolve. The
        // check is also the allow-list for the table name the client sent.
        $languageField = Tca::languageField($table);
        if ($languageField === null) {
            return null;
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return null;
        }

        if (!$backendUser->isAdmin() && !$backendUser->check('tables_select', $table)) {
            return null;
        }

        $workspaceId = max(0, $backendUser->workspace);
        $record      = $this->recordFinder->findRecord($table, $uid, $workspaceId);
        if ($record === null) {
            return null;
        }

        $languageId = $this->intValue($record[$languageField] ?? 0);
        if (!$backendUser->checkLanguageAccess($languageId)) {
            return null;
        }

        $page = $this->page($table, $record, $workspaceId);
        if ($page === null || !$backendUser->doesUserHaveAccess($page, Permission::PAGE_SHOW)) {
            return null;
        }

        try {
            $siteLanguage = $this->siteFinder
                ->getSiteByPageId($this->intValue($page['uid'] ?? 0))
                ->getLanguageById($languageId);
        } catch (SiteNotFoundException|InvalidArgumentException) {
            // The page belongs to no site, or the site has no such language;
            // "all languages" (-1) is never a site language.
            return null;
        }

        return self::nameOf($siteLanguage->getLocale());
    }

    /**
     * The English name of a locale, such as "German (Germany)"; null for the
     * C/POSIX locale, which names no language, and for a language intl does
     * not know.
     */
    public static function nameOf(Locale $locale): ?string
    {
        if (in_array($locale->getPosixCodeSet(), ['C', 'POSIX'], true)) {
            return null;
        }

        $languageCode = $locale->getLanguageCode();
        $language     = IntlLocale::getDisplayLanguage($languageCode, 'en');
        if (in_array($language, [false, '', $languageCode], true)) {
            return null;
        }

        $name = IntlLocale::getDisplayName($locale->getName(), 'en');

        return $name === false || $name === '' ? $language : $name;
    }

    /**
     * The page whose site and permissions apply to the record: its page for a
     * content record, the default-language page for a page (a translation has
     * no permissions of its own). Null when there is no such page, as for a
     * content record on the root level.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>|null
     */
    private function page(string $table, array $record, int $workspaceId): ?array
    {
        if ($table === 'pages') {
            $pointerField = Tca::transOrigPointerField('pages');
            $originalUid  = $pointerField === null ? 0 : $this->intValue($record[$pointerField] ?? 0);

            return $originalUid > 0
                ? $this->recordFinder->findRecord('pages', $originalUid, $workspaceId)
                : $record;
        }

        return $this->recordFinder->findRecord('pages', $this->intValue($record['pid'] ?? 0), $workspaceId);
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
