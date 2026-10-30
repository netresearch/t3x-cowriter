<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

use Netresearch\T3Cowriter\Domain\DTO\FieldSuggestionRequest;
use Netresearch\T3Cowriter\EventListener\RegisterFieldSuggestionControlsListener;
use Netresearch\T3Cowriter\Service\RecordEditAccess;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Checks that the current backend user may edit the requested field and reads
 * the context the suggestions are generated from.
 *
 * Every permission decision is taken on server-side data: the record row
 * (for an existing record) or the target page (for a new record). The client
 * only names table, field, uid and — for a new record — the target pid.
 */
class RecordContextReader
{
    /**
     * Upper bound for the page content handed to the model.
     */
    public const MAX_PAGE_CONTENT_LENGTH = 6000;

    /**
     * Upper bound for one field of the edited record handed to the model.
     */
    public const MAX_RECORD_FIELD_LENGTH = 1000;

    /**
     * Neighbouring pages whose titles are handed to the model at most.
     */
    public const MAX_SIBLING_PAGES = 5;

    /**
     * Page types whose title says nothing about the topic of a page: menu
     * separator, folder, recycler. They are never listed as a neighbour or
     * named as the parent.
     */
    public const EXCLUDED_DOKTYPES = [199, 254, 255];

    /**
     * The descriptive fields of a page, by name: the short text an editor
     * writes about the page. The same principle as the suggestion fields:
     * chosen by name, never by TCA type, so fields holding names, addresses,
     * identifiers or code (author, author_email, target, cache_tags, TSconfig)
     * are never read. Page fields that carry the suggestion control are read
     * as well; the title travels on its own line.
     */
    public const PAGE_FIELDS = [
        'nav_title',
        'subtitle',
        'abstract',
        'seo_title',
        'description',
        'keywords',
        'og_title',
        'og_description',
        'twitter_title',
        'twitter_description',
    ];

    public function __construct(
        private readonly RecordFinder $recordFinder,
        private readonly RecordEditAccess $recordEditAccess = new RecordEditAccess(),
    ) {}

    /**
     * A record that does not exist (or not in the user's workspace) is
     * refused with the same answer as a record the user may not edit, so the
     * endpoint does not tell which uids exist.
     *
     * @throws FieldSuggestionException when the field has no suggestion
     *                                  control, the record does not exist or
     *                                  the user may not edit the field
     */
    public function read(FieldSuggestionRequest $request, BackendUserAuthentication $backendUser): RecordContext
    {
        $table = $request->table;
        $field = $request->field;

        $column = $this->enabledColumn($table, $field);
        if ($column === null) {
            throw FieldSuggestionException::notEnabled();
        }

        if (!$backendUser->check('tables_modify', $table)) {
            throw FieldSuggestionException::accessDenied();
        }

        if (!$this->mayEditField($backendUser, $table, $field, $column)) {
            throw FieldSuggestionException::accessDenied();
        }

        return $this->readRecord($table, $field, $request->uid, $request->pid, $backendUser, $this->configuredCount($column));
    }

    /**
     * The record context for several fields of one record at once (the
     * "all fields" overview), plus the fields of $fields the user may edit
     * and that carry the suggestion control. The others are dropped without
     * a word: the client only names fields, the server decides.
     *
     * @param list<string> $fields
     *
     * @return array{context: RecordContext, fields: list<string>}
     *
     * @throws FieldSuggestionException when no field has the control, the
     *                                  user may edit none of them, the record
     *                                  does not exist or the user may not edit it
     */
    public function readFields(string $table, array $fields, int $uid, int $pid, BackendUserAuthentication $backendUser): array
    {
        // The same order as read(): the control, the table, then the field.
        $enabled = [];
        foreach (array_unique($fields) as $field) {
            $column = $this->enabledColumn($table, $field);
            if ($column !== null) {
                $enabled[$field] = $column;
            }
        }

        if ($enabled === []) {
            throw FieldSuggestionException::notEnabled();
        }

        if (!$backendUser->check('tables_modify', $table)) {
            throw FieldSuggestionException::accessDenied();
        }

        $allowed = [];
        foreach ($enabled as $field => $column) {
            if ($this->mayEditField($backendUser, $table, $field, $column)) {
                $allowed[] = $field;
            }
        }

        if ($allowed === []) {
            throw FieldSuggestionException::accessDenied();
        }

        return [
            'context' => $this->readRecord($table, $allowed[0], $uid, $pid, $backendUser, 1),
            'fields'  => $allowed,
        ];
    }

    /**
     * The record and page an image is generated for, with the same checks as
     * a field suggestion except the suggestion control: the user may modify
     * the table, edit the field and the record, on a page he may edit
     * content on. Only the record's own fields and the page title are read.
     *
     * @throws FieldSuggestionException when the field does not exist, the
     *                                  record does not exist or the user may
     *                                  not edit it
     */
    public function readForImage(string $table, string $field, int $uid, int $pid, BackendUserAuthentication $backendUser): RecordContext
    {
        $column = Tca::column($table, $field);
        if ($column === null) {
            throw FieldSuggestionException::notEnabled();
        }

        if (!$backendUser->check('tables_modify', $table) || !$this->mayEditField($backendUser, $table, $field, $column)) {
            throw FieldSuggestionException::accessDenied();
        }

        return $this->readRecord($table, $field, $uid, $pid, $backendUser, 1, false);
    }

    /**
     * The fields of $table with the suggestion control the user may edit,
     * as field name => label, in TCA order.
     *
     * @return array<string, string>
     */
    public function suggestionFields(string $table, BackendUserAuthentication $backendUser): array
    {
        $fields = [];
        foreach (Tca::columns($table) as $field => $column) {
            if (is_string($field) && is_array($column)
                && $this->enabledColumn($table, $field) !== null
                && $this->mayEditField($backendUser, $table, $field, $column)
            ) {
                $fields[$field] = FieldProfile::columnLabel($field, $column);
            }
        }

        return $fields;
    }

    /**
     * @param array<array-key, mixed> $column
     */
    private function mayEditField(BackendUserAuthentication $backendUser, string $table, string $field, array $column): bool
    {
        // Truthy like core's own check: extensions write `'exclude' => 1` as often as `true`.
        return !(bool) ($column['exclude'] ?? false) || $backendUser->check('non_exclude_fields', $table . ':' . $field);
    }

    /**
     * @param bool $surroundings false: only the record and the page title, no page content, page fields or neighbours
     */
    private function readRecord(string $table, string $field, int $uid, int $pid, BackendUserAuthentication $backendUser, int $maxCount, bool $surroundings = true): RecordContext
    {
        return $uid <= 0
            ? $this->readNewRecord($table, $field, $pid, $backendUser, $maxCount, $surroundings)
            : $this->readExistingRecord($table, $field, $uid, $backendUser, $maxCount, $surroundings);
    }

    /**
     * The count configured for the field control (fieldSuggestions.count); the
     * request may ask for fewer suggestions, never for more.
     *
     * @param array<array-key, mixed> $column
     */
    private function configuredCount(array $column): int
    {
        $fieldControl = Tca::config($column)['fieldControl'] ?? null;
        $control      = is_array($fieldControl) ? ($fieldControl[RegisterFieldSuggestionControlsListener::CONTROL_NAME] ?? null) : null;
        $options      = is_array($control) && is_array($control['options'] ?? null) ? $control['options'] : [];
        $count        = $options['count'] ?? null;

        return is_numeric($count)
            ? max(FieldSuggestionRequest::MIN_COUNT, min(FieldSuggestionRequest::MAX_COUNT, (int) $count))
            : FieldSuggestionRequest::MAX_COUNT;
    }

    /**
     * The TCA column, if it carries the suggestion field control.
     *
     * The TCA registration is the allow-list: a table/field pair without the
     * control is never served, whatever the client asks for.
     *
     * @return array<array-key, mixed>|null
     */
    private function enabledColumn(string $table, string $field): ?array
    {
        $column = Tca::column($table, $field);
        if ($column === null) {
            return null;
        }

        $fieldControl = Tca::config($column)['fieldControl'] ?? null;
        $control      = is_array($fieldControl) ? ($fieldControl[RegisterFieldSuggestionControlsListener::CONTROL_NAME] ?? null) : null;
        if (!is_array($control) || (bool) ($control['disabled'] ?? false)) {
            return null;
        }

        return $column;
    }

    private function readExistingRecord(string $table, string $field, int $uid, BackendUserAuthentication $backendUser, int $maxCount, bool $surroundings): RecordContext
    {
        $workspaceId = $this->workspaceId($backendUser);
        $record      = $this->recordFinder->findRecord($table, $uid, $workspaceId);
        if ($record === null) {
            throw FieldSuggestionException::accessDenied();
        }

        $pid        = $this->intValue($record['pid'] ?? 0);
        $languageId = $this->languageId($table, $record);

        if ($table === 'pages') {
            // A page translation has neither permissions nor content elements
            // of its own: both belong to the page in the default language.
            $defaultPage = $this->defaultLanguagePage($record, $workspaceId);
            if (!$backendUser->doesUserHaveAccess($defaultPage, Permission::PAGE_EDIT)) {
                throw FieldSuggestionException::accessDenied();
            }

            $page = ['uid' => $defaultPage['uid'] ?? 0, 'pid' => $defaultPage['pid'] ?? 0, 'title' => $record['title'] ?? ''];
            // The page's own fields are the record's fields.
            $localizedPage = null;
        } else {
            $page          = $this->pageForContentEdit($pid, $backendUser, $workspaceId);
            $localizedPage = $page === null ? null : $this->localizedPage($page, $languageId, $workspaceId);
        }

        // Language, editlock and explicit allow/deny of the record itself.
        if (!$this->recordEditAccess->mayEdit($backendUser, $table, $record)) {
            throw FieldSuggestionException::accessDenied();
        }

        $titlePage = $localizedPage ?? $page;
        $pageUid   = $page === null ? 0 : $this->intValue($page['uid'] ?? 0);

        return new RecordContext(
            table: $table,
            field: $field,
            record: $record,
            storedValue: $this->stringValue($record[$field] ?? ''),
            pageTitle: $titlePage === null ? '' : $this->stringValue($titlePage['title'] ?? ''),
            pageContent: $page === null || !$surroundings ? '' : $this->pageContent($page, $languageId, $backendUser, $workspaceId),
            slugPid: $pid,
            maxCount: $maxCount,
            recordFields: $this->recordFields($table, $record, $backendUser),
            pageFields: $localizedPage === null || !$surroundings ? [] : $this->pageFields($localizedPage, $backendUser),
            parentPageTitle: $page === null || !$surroundings ? '' : $this->parentTitle($this->intValue($page['pid'] ?? 0), $languageId, $backendUser, $workspaceId),
            siblingPageTitles: $page === null || !$surroundings ? [] : $this->siblingTitles($this->intValue($page['pid'] ?? 0), $pageUid, $languageId, $backendUser, $workspaceId),
        );
    }

    private function readNewRecord(string $table, string $field, int $pid, BackendUserAuthentication $backendUser, int $maxCount, bool $surroundings): RecordContext
    {
        $workspaceId = $this->workspaceId($backendUser);

        if ($pid === 0) {
            // Records on the root level (pid 0) are an admin affair.
            if (!$backendUser->isAdmin()) {
                throw FieldSuggestionException::accessDenied();
            }

            $page = null;
        } else {
            $page = $this->recordFinder->findRecord('pages', $pid, $workspaceId);
            if ($page === null) {
                throw FieldSuggestionException::accessDenied();
            }

            $required = $table === 'pages' ? Permission::PAGE_NEW : Permission::CONTENT_EDIT;
            if (!$backendUser->doesUserHaveAccess($page, $required)) {
                throw FieldSuggestionException::accessDenied();
            }
        }

        if ($page === null || !$surroundings) {
            return new RecordContext(
                table: $table,
                field: $field,
                record: ['pid' => $pid],
                storedValue: '',
                pageTitle: $page === null ? '' : $this->stringValue($page['title'] ?? ''),
                pageContent: '',
                slugPid: $pid,
                maxCount: $maxCount,
            );
        }

        $isPage = $table === 'pages';

        return new RecordContext(
            table: $table,
            field: $field,
            record: ['pid' => $pid],
            storedValue: '',
            // A new page has no content yet: its parent page is the context.
            pageTitle: $this->stringValue($page['title'] ?? ''),
            pageContent: $this->pageContent($page, 0, $backendUser, $workspaceId),
            slugPid: $pid,
            maxCount: $maxCount,
            pageFields: $isPage ? [] : $this->pageFields($page, $backendUser),
            // A new page's parent is the page above, already named as the page title;
            // its neighbours are the subpages of that page.
            parentPageTitle: $isPage ? '' : $this->parentTitle($this->intValue($page['pid'] ?? 0), 0, $backendUser, $workspaceId),
            siblingPageTitles: $isPage
                ? $this->siblingTitles($pid, 0, 0, $backendUser, $workspaceId)
                : $this->siblingTitles($this->intValue($page['pid'] ?? 0), $pid, 0, $backendUser, $workspaceId),
        );
    }

    /**
     * The page's translation into $languageId when there is one, else the page.
     *
     * @param array<string, mixed> $page
     *
     * @return array<string, mixed>
     */
    private function localizedPage(array $page, int $languageId, int $workspaceId): array
    {
        if ($languageId <= 0) {
            return $page;
        }

        return $this->recordFinder->findPageTranslation($this->intValue($page['uid'] ?? 0), $languageId, $workspaceId) ?? $page;
    }

    /**
     * The descriptive fields of a page the user may read, as plain text.
     *
     * @param array<string, mixed> $page
     *
     * @return array<string, array{label: string, value: string}>
     */
    private function pageFields(array $page, BackendUserAuthentication $backendUser): array
    {
        if (!$backendUser->check('tables_select', 'pages')) {
            return [];
        }

        $fields = [];
        foreach (Tca::columns('pages') as $field => $column) {
            if (!is_string($field) || !is_array($column) || !$this->isDescriptivePageField($field, $column)
                || !$this->mayEditField($backendUser, 'pages', $field, $column)
            ) {
                continue;
            }

            $value = mb_substr($this->plainText($page[$field] ?? ''), 0, self::MAX_RECORD_FIELD_LENGTH, 'UTF-8');
            if ($value !== '') {
                $fields[$field] = ['label' => FieldProfile::columnLabel($field, $column), 'value' => $value];
            }
        }

        return $fields;
    }

    /**
     * A page field with text about the page: by name (PAGE_FIELDS), or one
     * the suggestion control is configured for. Never the title (it has its
     * own line) and never a slug.
     *
     * @param array<array-key, mixed> $column
     */
    private function isDescriptivePageField(string $field, array $column): bool
    {
        if ($field === 'title' || (Tca::config($column)['type'] ?? null) === 'slug') {
            return false;
        }

        return in_array($field, self::PAGE_FIELDS, true) || $this->enabledColumn('pages', $field) !== null;
    }

    /**
     * The title of the parent page in $languageId (else in the default
     * language), when the user may see that page and it is a visible content
     * page; '' otherwise.
     */
    private function parentTitle(int $parentUid, int $languageId, BackendUserAuthentication $backendUser, int $workspaceId): string
    {
        if ($parentUid <= 0) {
            return '';
        }

        $parent = $this->recordFinder->findRecord('pages', $parentUid, $workspaceId);
        if ($parent === null || !$this->isListablePage($parent, $backendUser)) {
            return '';
        }

        return $this->pageTitleIn($parent, $languageId, $workspaceId);
    }

    /**
     * The titles of up to MAX_SIBLING_PAGES other subpages of $parentUid the
     * user may see, in page tree order, in $languageId where a visible
     * translation exists.
     *
     * @return list<string>
     */
    private function siblingTitles(int $parentUid, int $pageUid, int $languageId, BackendUserAuthentication $backendUser, int $workspaceId): array
    {
        // A page on the root level has no parent page to share.
        if ($parentUid <= 0) {
            return [];
        }

        $titles = [];
        foreach ($this->recordFinder->findVisibleSubpages($parentUid, $workspaceId) as $sibling) {
            if ($this->intValue($sibling['uid'] ?? 0) === $pageUid || !$this->isListablePage($sibling, $backendUser)) {
                continue;
            }

            $title = $this->pageTitleIn($sibling, $languageId, $workspaceId);
            if ($title !== '') {
                $titles[] = $title;
            }

            if (count($titles) >= self::MAX_SIBLING_PAGES) {
                break;
            }
        }

        return $titles;
    }

    /**
     * Visible to visitors, a content page and one the user may see in the
     * page tree (show permission, inside a web mount).
     *
     * @param array<string, mixed> $page
     */
    private function isListablePage(array $page, BackendUserAuthentication $backendUser): bool
    {
        return $backendUser->check('tables_select', 'pages')
            && $this->recordFinder->isVisibleRow($page)
            && !in_array($this->intValue($page['doktype'] ?? 0), self::EXCLUDED_DOKTYPES, true)
            && $backendUser->doesUserHaveAccess($page, Permission::PAGE_SHOW);
    }

    /**
     * The title of a default-language page in $languageId when it has a
     * visible translation there, else its own title, as plain text.
     *
     * @param array<string, mixed> $page
     */
    private function pageTitleIn(array $page, int $languageId, int $workspaceId): string
    {
        $translation = $this->recordFinder->findPageTranslation($this->intValue($page['uid'] ?? 0), $languageId, $workspaceId);
        if ($translation !== null && $this->recordFinder->isVisibleRow($translation)) {
            $title = $this->plainText($translation['title'] ?? '');
            if ($title !== '') {
                return $title;
            }
        }

        return $this->plainText($page['title'] ?? '');
    }

    /**
     * The default-language row of a page (the page itself when it is not a
     * translation). A translation whose original is gone falls back to itself.
     *
     * @param array<string, mixed> $page
     *
     * @return array<string, mixed>
     */
    private function defaultLanguagePage(array $page, int $workspaceId): array
    {
        $pointerField = Tca::transOrigPointerField('pages');
        $originalUid  = $pointerField === null ? 0 : $this->intValue($page[$pointerField] ?? 0);
        if ($originalUid <= 0) {
            return $page;
        }

        return $this->recordFinder->findRecord('pages', $originalUid, $workspaceId) ?? $page;
    }

    /**
     * The page a non-page record lives on, after checking CONTENT_EDIT there.
     *
     * @return array<string, mixed>|null
     */
    private function pageForContentEdit(int $pid, BackendUserAuthentication $backendUser, int $workspaceId): ?array
    {
        if ($pid === 0) {
            if (!$backendUser->isAdmin()) {
                throw FieldSuggestionException::accessDenied();
            }

            return null;
        }

        $page = $this->recordFinder->findRecord('pages', $pid, $workspaceId);
        if ($page === null || !$backendUser->doesUserHaveAccess($page, Permission::CONTENT_EDIT)) {
            throw FieldSuggestionException::accessDenied();
        }

        return $page;
    }

    /**
     * Plain text of the content elements on the page, if the user may read
     * content elements at all.
     *
     * @param array<string, mixed> $page
     */
    private function pageContent(array $page, int $languageId, BackendUserAuthentication $backendUser, int $workspaceId): string
    {
        if (!Tca::hasTable('tt_content') || !$backendUser->check('tables_select', 'tt_content')) {
            return '';
        }

        $parts = [];
        foreach ($this->recordFinder->findPageContent($this->intValue($page['uid'] ?? 0), $languageId, $workspaceId) as $element) {
            $text = trim($this->plainText($element['header'] ?? '') . "\n" . $this->plainText($element['bodytext'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return mb_substr(implode("\n\n", $parts), 0, self::MAX_PAGE_CONTENT_LENGTH, 'UTF-8');
    }

    /**
     * The record's own text the user may read, as context for the model: its
     * label field, the fields with the suggestion control and the bodytext;
     * of a page also its descriptive fields (PAGE_FIELDS). Excluded fields
     * count only when the user has access to them.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, array{label: string, value: string}>
     */
    private function recordFields(string $table, array $record, BackendUserAuthentication $backendUser): array
    {
        $labelField = Tca::labelField($table);
        $fields     = [];
        foreach (Tca::columns($table) as $field => $column) {
            if (!is_string($field) || !is_array($column)) {
                continue;
            }

            $isContext = $field === $labelField || $field === 'bodytext' || $this->enabledColumn($table, $field) !== null
                || ($table === 'pages' && in_array($field, self::PAGE_FIELDS, true));
            if (!$isContext || !$this->mayEditField($backendUser, $table, $field, $column)) {
                continue;
            }

            $value = mb_substr($this->plainText($record[$field] ?? ''), 0, self::MAX_RECORD_FIELD_LENGTH, 'UTF-8');
            if ($value !== '') {
                $fields[$field] = ['label' => FieldProfile::columnLabel($field, $column), 'value' => $value];
            }
        }

        return $fields;
    }

    /**
     * The workspace the user works in; 0 is live.
     */
    private function workspaceId(BackendUserAuthentication $backendUser): int
    {
        return max(0, $backendUser->workspace);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function languageId(string $table, array $record): int
    {
        $languageField = Tca::languageField($table);

        return $languageField === null ? 0 : max(0, $this->intValue($record[$languageField] ?? 0));
    }

    private function plainText(mixed $value): string
    {
        $text = html_entity_decode(strip_tags($this->stringValue($value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
