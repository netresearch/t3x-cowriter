<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Presentation;

use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Reads a text element in the user's workspace and checks that the user may
 * present it differently. Every decision is taken on the stored rows; the
 * request only names the uid.
 *
 * @internal
 */
class PresentationElementReader
{
    public function __construct(
        private readonly RecordFinder $recordFinder,
        private readonly PresentationAccess $access = new PresentationAccess(),
    ) {}

    /**
     * @throws PresentationException when the element does not exist in the
     *                               user's workspace, is no text element or
     *                               no layout is open to the user
     */
    public function read(int $uid, BackendUserAuthentication $backendUser): PresentationElement
    {
        $workspaceId = max(0, $backendUser->workspace);
        $element     = $uid > 0 ? $this->recordFinder->findRecord('tt_content', $uid, $workspaceId) : null;
        $pageUid     = $this->intValue($element['pid'] ?? 0);
        $page        = $pageUid > 0 ? $this->recordFinder->findRecord('pages', $pageUid, $workspaceId) : null;

        if ($element === null || $page === null) {
            throw PresentationException::notAvailable();
        }

        $layouts = $this->access->layoutsFor($backendUser, $element, $page, $this->pageTsConfig($pageUid));
        if ($layouts === []) {
            throw PresentationException::notAvailable();
        }

        $languageId     = $this->intValue($element['sys_language_uid'] ?? 0);
        $pointerField   = Tca::transOrigPointerField('tt_content');
        $fileField      = PresentationAccess::FILE_FIELD_BY_CTYPE[$this->stringValue($element['CType'] ?? '')] ?? null;
        $fileFields     = $fileField !== null && Tca::column('tt_content', $fileField) !== null ? [$fileField] : [];
        $fileReferences = $this->recordFinder->findFileReferences('tt_content', $uid, $fileFields, $workspaceId);

        return new PresentationElement(
            uid: $uid,
            pageUid: $pageUid,
            languageId: $languageId,
            columnPosition: $this->intValue($element['colPos'] ?? 0),
            header: $this->stringValue($element['header'] ?? ''),
            bodytext: $this->stringValue($element['bodytext'] ?? ''),
            layouts: $layouts,
            copiedFields: $this->copiedFields($element, $backendUser),
            translationCount: $languageId === 0 && $pointerField !== null
                ? $this->recordFinder->countTranslations('tt_content', $pointerField, $uid, $workspaceId)
                : 0,
            connectedTranslation: $languageId > 0 && $pointerField !== null && $this->intValue($element[$pointerField] ?? 0) > 0,
            fileReferences: $fileReferences,
            layoutsTakingFiles: array_values(array_filter(
                $layouts,
                fn (Layout $layout): bool => $this->access->mayCopyFiles($backendUser, $layout),
            )),
        );
    }

    /**
     * The fields the new element takes over: the access fields always (the
     * access check required the right where they restrict anything), the
     * appearance fields where the editor may write them.
     *
     * @param array<string, mixed> $element
     *
     * @return array<string, scalar|null>
     */
    private function copiedFields(array $element, BackendUserAuthentication $backendUser): array
    {
        $fields = [];
        foreach (PresentationAccess::accessFields() as $field) {
            $fields[$field] = $this->scalarValue($element[$field] ?? null);
        }

        foreach (PresentationAccess::APPEARANCE_FIELDS as $field) {
            if (array_key_exists($field, $element) && $this->access->mayWrite($backendUser, 'tt_content', $field)) {
                $fields[$field] = $this->scalarValue($element[$field]);
            }
        }

        return $fields;
    }

    private function scalarValue(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) ? $value : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function pageTsConfig(int $pageUid): array
    {
        return BackendUtility::getPagesTSconfig($pageUid);
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
