<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Presentation;

use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Shows the new tab or accordion element and hides its original in one
 * DataHandler run as the editor, so workspaces and permissions apply. Both
 * records are read as the editor's workspace shows them, checked, and read
 * back afterwards.
 *
 * @internal
 */
class PresentationSwitcher
{
    public function __construct(
        private readonly RecordFinder $recordFinder,
        private readonly LoggerInterface $logger,
        private readonly PresentationAccess $access = new PresentationAccess(),
    ) {}

    /**
     * @throws PresentationException when a record is not there, the editor
     *                               may not switch them, or the switch did
     *                               not come through
     */
    public function switch(int $newUid, int $originalUid, BackendUserAuthentication $backendUser): void
    {
        $workspaceId = max(0, $backendUser->workspace);
        $newElement  = $this->recordFinder->findRecord('tt_content', $newUid, $workspaceId);
        $original    = $this->recordFinder->findRecord('tt_content', $originalUid, $workspaceId);
        $pageUid     = $this->intValue($newElement['pid'] ?? 0);
        $page        = $pageUid > 0 ? $this->recordFinder->findRecord('pages', $pageUid, $workspaceId) : null;
        $hiddenField = PresentationAccess::hiddenField();

        // Both on one page: the new element was placed after its original.
        if ($newElement === null || $original === null || $page === null || $hiddenField === null
            || $this->intValue($original['pid'] ?? 0) !== $pageUid
            || !$this->access->maySwitch($backendUser, $newElement, $original, $page)
        ) {
            throw PresentationException::notAvailable();
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tt_content' => [
            $newUid      => [$hiddenField => 0],
            $originalUid => [$hiddenField => 1],
        ]], [], $backendUser);
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog === []
            && $this->isHidden($newUid, $hiddenField, $workspaceId) === false
            && $this->isHidden($originalUid, $hiddenField, $workspaceId) === true
        ) {
            return;
        }

        $this->logger->error('Alternative presentation: the elements could not be switched', [
            'new'      => $newUid,
            'original' => $originalUid,
            'errors'   => $dataHandler->errorLog,
        ]);

        throw PresentationException::switchFailed();
    }

    /**
     * The hidden state of a record as the workspace shows it, null when it is
     * not found.
     */
    private function isHidden(int $uid, string $hiddenField, int $workspaceId): ?bool
    {
        $row = $this->recordFinder->findRecord('tt_content', $uid, $workspaceId);

        return $row === null ? null : $this->intValue($row[$hiddenField] ?? 0) === 1;
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
