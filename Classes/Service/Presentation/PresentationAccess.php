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
            fn (Layout $layout): bool => $this->mayCreate($backendUser, $layout, $languageId, $pageTsConfig),
        ));
    }

    /**
     * @param array<array-key, mixed> $pageTsConfig
     */
    private function mayCreate(BackendUserAuthentication $backendUser, Layout $layout, int $languageId, array $pageTsConfig): bool
    {
        $relation = self::itemRelation($layout);
        if ($relation === null || !$backendUser->check('tables_modify', $relation['table'])) {
            return false;
        }

        if (!$this->ctypeAllowed($backendUser, $layout, $pageTsConfig)) {
            return false;
        }

        // The fields the new records are written with; DataHandler leaves out
        // an excluded field the user may not edit without reporting it.
        $fields = [
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
