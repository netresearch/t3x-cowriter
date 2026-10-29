<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Presentation;

use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
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

        return new PresentationElement(
            uid: $uid,
            pageUid: $pageUid,
            languageId: $this->intValue($element['sys_language_uid'] ?? 0),
            columnPosition: $this->intValue($element['colPos'] ?? 0),
            header: $this->stringValue($element['header'] ?? ''),
            bodytext: $this->stringValue($element['bodytext'] ?? ''),
            layouts: $layouts,
        );
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
