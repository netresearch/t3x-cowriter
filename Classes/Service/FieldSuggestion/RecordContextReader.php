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
     * @param array<array-key, mixed> $column
     */
    private function mayEditField(BackendUserAuthentication $backendUser, string $table, string $field, array $column): bool
    {
        // Truthy like core's own check: extensions write `'exclude' => 1` as often as `true`.
        return !(bool) ($column['exclude'] ?? false) || $backendUser->check('non_exclude_fields', $table . ':' . $field);
    }

    private function readRecord(string $table, string $field, int $uid, int $pid, BackendUserAuthentication $backendUser, int $maxCount): RecordContext
    {
        return $uid <= 0
            ? $this->readNewRecord($table, $field, $pid, $backendUser, $maxCount)
            : $this->readExistingRecord($table, $field, $uid, $backendUser, $maxCount);
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

    private function readExistingRecord(string $table, string $field, int $uid, BackendUserAuthentication $backendUser, int $maxCount): RecordContext
    {
        $workspaceId = $this->workspaceId($backendUser);
        $record      = $this->recordFinder->findRecord($table, $uid, $workspaceId);
        if ($record === null) {
            throw FieldSuggestionException::accessDenied();
        }

        $pid = $this->intValue($record['pid'] ?? 0);

        if ($table === 'pages') {
            // A page translation has neither permissions nor content elements
            // of its own: both belong to the page in the default language.
            $defaultPage = $this->defaultLanguagePage($record, $workspaceId);
            if (!$backendUser->doesUserHaveAccess($defaultPage, Permission::PAGE_EDIT)) {
                throw FieldSuggestionException::accessDenied();
            }

            $page = ['uid' => $defaultPage['uid'] ?? 0, 'title' => $record['title'] ?? ''];
        } else {
            $page = $this->pageForContentEdit($pid, $backendUser, $workspaceId);
        }

        // Language, editlock and explicit allow/deny of the record itself.
        if (!$this->recordEditAccess->mayEdit($backendUser, $table, $record)) {
            throw FieldSuggestionException::accessDenied();
        }

        return new RecordContext(
            table: $table,
            field: $field,
            record: $record,
            storedValue: $this->stringValue($record[$field] ?? ''),
            pageTitle: $page === null ? '' : $this->stringValue($page['title'] ?? ''),
            pageContent: $page === null ? '' : $this->pageContent($page, $this->languageId($table, $record), $backendUser, $workspaceId),
            slugPid: $pid,
            maxCount: $maxCount,
            recordFields: $this->recordFields($table, $record, $backendUser),
        );
    }

    private function readNewRecord(string $table, string $field, int $pid, BackendUserAuthentication $backendUser, int $maxCount): RecordContext
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

        return new RecordContext(
            table: $table,
            field: $field,
            record: ['pid' => $pid],
            storedValue: '',
            // A new page has no content yet: its parent page is the context.
            pageTitle: $page === null ? '' : $this->stringValue($page['title'] ?? ''),
            pageContent: $page === null ? '' : $this->pageContent($page, 0, $backendUser, $workspaceId),
            slugPid: $pid,
            maxCount: $maxCount,
        );
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
     * label field, the fields with the suggestion control and the bodytext.
     * Excluded fields count only when the user has access to them.
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

            $isContext = $field === $labelField || $field === 'bodytext' || $this->enabledColumn($table, $field) !== null;
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
