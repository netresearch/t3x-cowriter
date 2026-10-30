<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Presentation;

use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use Netresearch\T3Cowriter\Service\RecordEditAccess;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Which layouts an editor may turn a text element into.
 *
 * The button and the routes must not offer what DataHandler would then
 * refuse or store incompletely. DataHandler leaves out an excluded field the
 * editor may not edit without reporting it (an item header is stored empty,
 * observed on TYPO3 14.3), it refuses a CType or an item table the editor may
 * not use with an error but keeps what it wrote before, and page TSconfig
 * removeItems/keepItems only shape the form. So all of these are checked
 * here, before a record is written; PresentationWriter's read-back stays as
 * a second line of defence.
 *
 * @internal
 */
final readonly class PresentationAccess
{
    /**
     * CTypes whose bodytext can be presented differently.
     */
    public const SOURCE_CTYPES = ['text', 'textpic', 'textmedia'];

    /**
     * Fields of a text element that hold its images.
     */
    public const FILE_FIELDS = ['image', 'assets'];

    /**
     * Appearance fields the new element takes over where the editor may
     * write them; without that right it keeps their defaults.
     */
    public const APPEARANCE_FIELDS = ['header_layout', 'frame_class', 'space_before_class', 'space_after_class'];

    public function __construct(
        private RecordEditAccess $recordEditAccess = new RecordEditAccess(),
    ) {}

    /**
     * The layouts the TCA provides: the CType is selectable and its item
     * column is an inline relation to a table with header and bodytext.
     *
     * @param array<array-key, mixed>|null $tca defaults to $GLOBALS['TCA']
     *
     * @return list<Layout>
     */
    public static function layoutsInTca(?array $tca = null): array
    {
        $ctypes = self::ctypeValues($tca);

        return array_values(array_filter(
            Layout::cases(),
            static fn (Layout $layout): bool => in_array($layout->value, $ctypes, true) && self::itemRelation($layout, $tca) !== null,
        ));
    }

    /**
     * The item table of a layout and the field in it that points to the
     * content element, from the TCA of the item column.
     *
     * @param array<array-key, mixed>|null $tca
     *
     * @return array{table: string, parentField: string}|null
     */
    public static function itemRelation(Layout $layout, ?array $tca = null): ?array
    {
        $column = Tca::column('tt_content', $layout->itemColumn(), $tca);
        $config = $column === null ? [] : Tca::config($column);
        $table  = $config['foreign_table'] ?? null;
        $field  = $config['foreign_field'] ?? null;

        if (($config['type'] ?? null) !== 'inline' || !is_string($table) || !is_string($field) || $field === '') {
            return null;
        }

        // An empty or unknown table has no columns.
        if (Tca::column($table, 'header', $tca) === null || Tca::column($table, 'bodytext', $tca) === null) {
            return null;
        }

        return ['table' => $table, 'parentField' => $field];
    }

    /**
     * The fields that restrict who sees a content element and when: frontend
     * groups, start and end time (ctrl.enablecolumns), those that exist.
     *
     * @return list<string>
     */
    public static function accessFields(): array
    {
        $fields = [];
        foreach (['fe_group', 'starttime', 'endtime'] as $key) {
            $field = Tca::enableColumn('tt_content', $key);
            if ($field !== null && Tca::column('tt_content', $field) !== null) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Whether $backendUser may write $field of $table: it exists and, if it
     * is an excluded field, the user may edit it.
     */
    public function mayWrite(BackendUserAuthentication $backendUser, string $table, string $field): bool
    {
        return $this->mayWriteField($backendUser, $table, $field);
    }

    /**
     * Whether the first item of $layout may take file references: the item's
     * file field and the file reference table are writable for the editor.
     */
    public function mayCopyFiles(BackendUserAuthentication $backendUser, Layout $layout): bool
    {
        $relation = self::itemRelation($layout);

        return $relation !== null
            && $backendUser->check('tables_modify', 'sys_file_reference')
            && $this->mayWriteField($backendUser, $relation['table'], 'media')
            && $this->mayWriteField($backendUser, 'sys_file_reference', 'uid_local');
    }

    /**
     * The field that hides a content element (ctrl.enablecolumns.disabled).
     *
     * @param array<array-key, mixed>|null $tca defaults to $GLOBALS['TCA']
     */
    public static function hiddenField(?array $tca = null): ?string
    {
        return Tca::enableColumn('tt_content', 'disabled', $tca);
    }

    /**
     * Whether $backendUser may swap the visibility of a new element and its
     * original, both tt_content rows as the user's workspace shows them, on
     * $page: edit both records and their page, use their languages, and
     * write the hidden field, which DataHandler would otherwise leave out
     * without reporting it.
     *
     * @param array<string, mixed> $newElement
     * @param array<string, mixed> $original
     * @param array<string, mixed> $page
     */
    public function maySwitch(BackendUserAuthentication $backendUser, array $newElement, array $original, array $page): bool
    {
        $hiddenField = self::hiddenField();
        if ($hiddenField === null
            || Layout::tryFrom(is_string($newElement['CType'] ?? null) ? $newElement['CType'] : '') === null
            || !$backendUser->check('tables_modify', 'tt_content')
            || !$backendUser->doesUserHaveAccess($page, Permission::CONTENT_EDIT)
            || !$this->mayWriteField($backendUser, 'tt_content', $hiddenField)
        ) {
            return false;
        }

        foreach ([$newElement, $original] as $record) {
            if (!$this->recordEditAccess->mayEdit($backendUser, 'tt_content', $record)
                || !$backendUser->checkLanguageAccess($this->intValue($record['sys_language_uid'] ?? 0))
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * The layouts $backendUser may create in place of $element, a tt_content
     * row as the user's workspace shows it, on $page.
     *
     * @param array<string, mixed>    $element
     * @param array<string, mixed>    $page
     * @param array<array-key, mixed> $pageTsConfig of the element's page
     *
     * @return list<Layout>
     */
    public function layoutsFor(BackendUserAuthentication $backendUser, array $element, array $page, array $pageTsConfig): array
    {
        if (!in_array($element['CType'] ?? null, self::SOURCE_CTYPES, true)
            || !$backendUser->check('tables_modify', 'tt_content')
            || !$backendUser->doesUserHaveAccess($page, Permission::CONTENT_EDIT)
            || !$this->recordEditAccess->mayEdit($backendUser, 'tt_content', $element)
        ) {
            return [];
        }

        $languageId = $this->intValue($element['sys_language_uid'] ?? 0);
        if (!$backendUser->checkLanguageAccess($languageId)) {
            return [];
        }

        return array_values(array_filter(
            self::layoutsInTca(),
            fn (Layout $layout): bool => $this->mayCreate($backendUser, $layout, $languageId, $element, $pageTsConfig),
        ));
    }

    /**
     * @param array<string, mixed>    $element
     * @param array<array-key, mixed> $pageTsConfig
     */
    private function mayCreate(BackendUserAuthentication $backendUser, Layout $layout, int $languageId, array $element, array $pageTsConfig): bool
    {
        $relation = self::itemRelation($layout);
        if ($relation === null || !$backendUser->check('tables_modify', $relation['table'])) {
            return false;
        }

        if (!$this->ctypeAllowed($backendUser, $layout, $pageTsConfig)) {
            return false;
        }

        // The fields the new records are written with; DataHandler leaves out
        // an excluded field the user may not edit without reporting it. The
        // new element is created hidden: without the right on the hidden
        // field it would go live at once.
        $hiddenField = self::hiddenField();
        if ($hiddenField === null) {
            return false;
        }

        $fields = [
            ['tt_content', $hiddenField],
            ['tt_content', 'CType'],
            ['tt_content', 'colPos'],
            ['tt_content', 'header'],
            ['tt_content', $layout->itemColumn()],
            [$relation['table'], 'header'],
            [$relation['table'], 'bodytext'],
        ];
        $elementLanguageField = Tca::languageField('tt_content');
        if ($elementLanguageField !== null) {
            $fields[] = ['tt_content', $elementLanguageField];
        }

        // An original restricted to frontend groups or a time span must not
        // turn into a new element without that restriction.
        foreach (self::accessFields() as $accessField) {
            $value = $element[$accessField] ?? null;
            if (!in_array($value, [null, '', 0, '0'], true)) {
                $fields[] = ['tt_content', $accessField];
            }
        }

        // Items of a translated element need their language field: without
        // the right on it, TYPO3 13.4 stores them in the default language
        // under the translated element (14.3 gives them the parent's language).
        if ($languageId !== 0) {
            $itemLanguageField = Tca::languageField($relation['table']);
            if ($itemLanguageField === null) {
                return false;
            }

            $fields[] = [$relation['table'], $itemLanguageField];
        }

        foreach ($fields as [$table, $field]) {
            if (!$this->mayWriteField($backendUser, $table, $field)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The explicit allow/deny of the CType (authMode) and the page's
     * TCEFORM.tt_content.CType.removeItems and keepItems.
     *
     * @param array<array-key, mixed> $pageTsConfig
     */
    private function ctypeAllowed(BackendUserAuthentication $backendUser, Layout $layout, array $pageTsConfig): bool
    {
        $column = Tca::column('tt_content', 'CType');
        if ($column === null) {
            return false;
        }

        if ((bool) (Tca::config($column)['authMode'] ?? false) && !$backendUser->checkAuthMode('tt_content', 'CType', $layout->value)) {
            return false;
        }

        $tceForm = $pageTsConfig['TCEFORM.'] ?? null;
        $table   = is_array($tceForm) ? ($tceForm['tt_content.'] ?? null) : null;
        $ctype   = is_array($table) ? ($table['CType.'] ?? null) : null;
        $remove  = is_array($ctype) ? ($ctype['removeItems'] ?? null) : null;
        $keep    = is_array($ctype) ? ($ctype['keepItems'] ?? null) : null;

        if (is_string($remove) && in_array($layout->value, GeneralUtility::trimExplode(',', $remove), true)) {
            return false;
        }

        $keepItems = is_string($keep) ? GeneralUtility::trimExplode(',', $keep) : null;

        return $keepItems === null || in_array($layout->value, $keepItems, true);
    }

    /**
     * The field exists and, if it is an excluded field, the user may edit it.
     */
    private function mayWriteField(BackendUserAuthentication $backendUser, string $table, string $field): bool
    {
        $column = Tca::column($table, $field);
        if ($column === null) {
            return false;
        }

        // Truthy like core's own check: extensions write `'exclude' => 1` as often as `true`.
        return !(bool) ($column['exclude'] ?? false) || $backendUser->check('non_exclude_fields', $table . ':' . $field);
    }

    /**
     * @param array<array-key, mixed>|null $tca
     *
     * @return list<string>
     */
    private static function ctypeValues(?array $tca): array
    {
        $column = Tca::column('tt_content', 'CType', $tca);
        $items  = $column === null ? [] : (Tca::config($column)['items'] ?? []);

        $values = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $value = is_array($item) ? ($item['value'] ?? null) : null;
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
