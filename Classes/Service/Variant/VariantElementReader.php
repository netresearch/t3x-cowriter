<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Variant;

use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use Netresearch\T3Cowriter\Service\Presentation\PresentationAccess;
use Netresearch\T3Cowriter\Service\RecordEditAccess;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Reads a text element in the user's workspace and checks that the user may
 * create an audience variant of it: copy it on its page, write the header,
 * the text and the hidden field of the copy. Every decision is taken on the
 * stored rows; the request only names the uid.
 *
 * DataHandler leaves out an excluded field the editor may not edit without
 * reporting it, so the fields the variant is written with are checked here,
 * before anything is written.
 *
 * @internal
 */
class VariantElementReader
{
    /**
     * CTypes whose header and text can be rewritten for an audience: the
     * text elements the tabs and accordion presentation starts from.
     */
    public const SOURCE_CTYPES = PresentationAccess::SOURCE_CTYPES;

    public function __construct(
        private readonly RecordFinder $recordFinder,
        private readonly PresentationAccess $access = new PresentationAccess(),
        private readonly RecordEditAccess $recordEditAccess = new RecordEditAccess(),
    ) {}

    /**
     * @throws VariantException when the element does not exist in the user's
     *                          workspace, is no text element or the user may
     *                          not create its variant
     */
    public function read(int $uid, BackendUserAuthentication $backendUser): VariantElement
    {
        $workspaceId = max(0, $backendUser->workspace);
        $element     = $uid > 0 ? $this->recordFinder->findRecord('tt_content', $uid, $workspaceId) : null;
        $pageUid     = $this->intValue($element['pid'] ?? 0);
        $page        = $pageUid > 0 ? $this->recordFinder->findRecord('pages', $pageUid, $workspaceId) : null;

        // In a workspace the form names an element that has a version there by
        // its version uid; the copy and the context settings hang on the live
        // uid, so such an element gets no variant (as with the presentation).
        if ($element === null || $page === null || $this->intValue($element['uid'] ?? 0) !== $uid
            || !$this->mayCreate($backendUser, $element, $page)
        ) {
            throw VariantException::notAvailable();
        }

        return new VariantElement(
            uid: $uid,
            pageUid: $pageUid,
            languageId: $this->intValue($element['sys_language_uid'] ?? 0),
            header: $this->stringValue($element['header'] ?? ''),
            bodytext: $this->stringValue($element['bodytext'] ?? ''),
            row: $element,
        );
    }

    /**
     * A copy of a default-language element with translations takes the
     * translations with it, still in the old wording, and a connected
     * translation would get a second translation of the same element. Both
     * are refused with their reason.
     *
     * @throws VariantException
     */
    public function refuseForTranslations(VariantElement $element, BackendUserAuthentication $backendUser): void
    {
        $pointerField = Tca::transOrigPointerField('tt_content');
        if ($pointerField === null) {
            return;
        }

        if ($element->languageId > 0) {
            if ($this->intValue($element->row[$pointerField] ?? 0) > 0) {
                throw VariantException::connectedTranslation();
            }

            return;
        }

        if ($this->recordFinder->countTranslations('tt_content', $pointerField, $element->uid, max(0, $backendUser->workspace)) > 0) {
            throw VariantException::hasTranslations();
        }
    }

    /**
     * @param array<string, mixed> $element
     * @param array<string, mixed> $page
     */
    private function mayCreate(BackendUserAuthentication $backendUser, array $element, array $page): bool
    {
        $hiddenField = PresentationAccess::hiddenField();
        if ($hiddenField === null
            || !in_array($element['CType'] ?? null, self::SOURCE_CTYPES, true)
            || !$backendUser->check('tables_modify', 'tt_content')
            || !$backendUser->doesUserHaveAccess($page, Permission::CONTENT_EDIT)
            || !$this->recordEditAccess->mayEdit($backendUser, 'tt_content', $element)
            || !$backendUser->checkLanguageAccess($this->intValue($element['sys_language_uid'] ?? 0))
        ) {
            return false;
        }

        // The copy is created hidden: without the right on the hidden field it
        // would go live at once. Header and text are what the variant changes.
        foreach ([$hiddenField, 'header', 'bodytext'] as $field) {
            if (!$this->access->mayWrite($backendUser, 'tt_content', $field)) {
                return false;
            }
        }

        return true;
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
