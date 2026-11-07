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
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Writes an audience variant through DataHandler as the editor, so page
 * permissions, excluded fields, the workspace and the rich text
 * transformation of the text apply as when the editor works in the backend.
 *
 * create: copies the element directly after itself (a copy to the negative
 * uid, as the clipboard does: every field and image of the element comes
 * along), and in the same run gives the copy the reviewed header and text,
 * hides it, and, when a context was chosen, makes it visible in that context
 * only. The original is not touched.
 *
 * show: makes the copy visible and, when the editor asks for it, hides the
 * original in the copy's context.
 *
 * DataHandler is not transactional and leaves out what the editor may not
 * write without an error. So every result is read back; a copy that is not
 * stored as intended is deleted again (as the editor) and the creation fails.
 *
 * @internal
 */
class VariantWriter
{
    public function __construct(
        private readonly RecordFinder $recordFinder,
        private readonly AudienceContexts $contexts,
        private readonly LoggerInterface $logger,
        private readonly RecordEditAccess $recordEditAccess = new RecordEditAccess(),
        private readonly PresentationAccess $access = new PresentationAccess(),
    ) {}

    /**
     * @param int $contextUid 0 for none
     *
     * @return int uid of the variant
     *
     * @throws VariantException when the variant could not be created as intended
     */
    public function create(VariantElement $element, string $header, string $bodytext, int $contextUid, BackendUserAuthentication $backendUser): int
    {
        $hiddenField = PresentationAccess::hiddenField();
        if ($hiddenField === null) {
            throw VariantException::createFailed();
        }

        $update = ['header' => $header, 'bodytext' => $bodytext, $hiddenField => 1];
        if ($contextUid > 0) {
            $update[AudienceContexts::SETTINGS_FIELD] = $this->contexts->onlyIn($contextUid);
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['tt_content' => [$element->uid => ['copy' => [
            'action' => 'paste',
            'target' => -$element->uid,
            'update' => $update,
        ]]]], $backendUser);
        $dataHandler->process_cmdmap();

        $copies     = $dataHandler->copyMappingArray_merged['tt_content'] ?? null;
        $copied     = is_array($copies) ? ($copies[$element->uid] ?? 0) : 0;
        $variantUid = is_numeric($copied) ? (int) $copied : 0;

        if ($variantUid > 0 && $dataHandler->errorLog === []
            && $this->isStoredAsIntended($variantUid, $element, $header, $hiddenField, $contextUid, $backendUser)
        ) {
            return $variantUid;
        }

        $this->logger->error('Audience variant: the copy could not be created as intended', [
            'element' => $element->uid,
            'variant' => $variantUid,
            'errors'  => $dataHandler->errorLog,
        ]);

        if ($variantUid > 0 && $this->delete($variantUid, $backendUser) !== []) {
            throw VariantException::createIncomplete();
        }

        throw VariantException::createFailed();
    }

    /**
     * Makes the variant visible, and hides the original in $contextUid when
     * $hideOriginal is set. The original keeps every other context setting
     * it has.
     *
     * @throws VariantException when a record is not there, not a pair this
     *                          writer created, or the change did not come
     *                          through
     */
    public function show(int $variantUid, int $originalUid, int $contextUid, bool $hideOriginal, BackendUserAuthentication $backendUser): void
    {
        $workspaceId = max(0, $backendUser->workspace);
        $variant     = $this->recordFinder->findRecord('tt_content', $variantUid, $workspaceId);
        $original    = $this->recordFinder->findRecord('tt_content', $originalUid, $workspaceId);
        $hiddenField = PresentationAccess::hiddenField();

        // The pair as create() left it: on one page, the variant visible only
        // in its context. Both records are checked before the one write, so
        // the variant is not shown while DataHandler refuses the original
        // (an edit lock, a language the editor lost), and the original is not
        // hidden while DataHandler silently drops the variant's hidden field
        // (the editor lost the right on it since the creation).
        if ($variant === null || $original === null || $hiddenField === null || $contextUid < 1
            || $this->intValue($variant['pid'] ?? 0) !== $this->intValue($original['pid'] ?? 0)
            || !$this->contexts->isEnabledIn($variant, $contextUid)
            || !$this->access->mayWrite($backendUser, 'tt_content', $hiddenField)
            || !$this->recordEditAccess->mayEdit($backendUser, 'tt_content', $variant)
            || ($hideOriginal && !$this->recordEditAccess->mayEdit($backendUser, 'tt_content', $original))
        ) {
            throw VariantException::notAvailable();
        }

        // A translation added to the original since the creation would be
        // hidden in the context with it, while the variant has none.
        $pointerField = Tca::transOrigPointerField('tt_content');
        if ($hideOriginal && $pointerField !== null
            && $this->recordFinder->countTranslations('tt_content', $pointerField, $originalUid, $workspaceId) > 0
        ) {
            throw VariantException::originalTranslated();
        }

        $data = [$variantUid => [$hiddenField => 0]];
        if ($hideOriginal) {
            $data[$originalUid] = [AudienceContexts::SETTINGS_FIELD => $this->contexts->hiddenIn($original, $contextUid)];
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tt_content' => $data], [], $backendUser);
        $dataHandler->process_datamap();

        $variantAfter  = $this->recordFinder->findRecord('tt_content', $variantUid, $workspaceId);
        $originalAfter = $this->recordFinder->findRecord('tt_content', $originalUid, $workspaceId);
        if ($dataHandler->errorLog === [] && $variantAfter !== null && $originalAfter !== null
            && $this->intValue($variantAfter[$hiddenField] ?? 1) === 0
            && (!$hideOriginal || $this->keepsOtherSettings($original, $originalAfter, $contextUid))
        ) {
            return;
        }

        $this->logger->error('Audience variant: the variant could not be shown as intended', [
            'variant'  => $variantUid,
            'original' => $originalUid,
            'errors'   => $dataHandler->errorLog,
        ]);

        throw VariantException::showFailed();
    }

    /**
     * The copy as the user's workspace shows it is hidden, carries the
     * header, has a text, is placed on the original's page, keeps the
     * original's access restrictions, and is visible in the chosen context
     * only.
     */
    private function isStoredAsIntended(int $uid, VariantElement $element, string $header, string $hiddenField, int $contextUid, BackendUserAuthentication $backendUser): bool
    {
        $row = $this->recordFinder->findRecord('tt_content', $uid, max(0, $backendUser->workspace));
        if ($row === null
            || $this->intValue($row[$hiddenField] ?? null) !== 1
            || $this->intValue($row['pid'] ?? null) !== $element->pageUid
            || $this->stringValue($row['header'] ?? null) !== $header
            || trim($this->stringValue($row['bodytext'] ?? null)) === ''
        ) {
            return false;
        }

        // The original's access restrictions came along; the reader's check
        // is the first line of defence.
        foreach (PresentationAccess::accessFields() as $field) {
            if (array_key_exists($field, $element->row)
                && $this->restriction($row[$field] ?? null) !== $this->restriction($element->row[$field])
            ) {
                return false;
            }
        }

        return $contextUid === 0
            || ($this->contexts->isEnabledIn($row, $contextUid) && $this->contexts->currentSettings($row) === $this->contexts->onlyIn($contextUid));
    }

    /**
     * The original is hidden in $contextUid now and every other context
     * setting it had is still there.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function keepsOtherSettings(array $before, array $after, int $contextUid): bool
    {
        if (!$this->contexts->isDisabledIn($after, $contextUid)) {
            return false;
        }

        $expected = $this->contexts->hiddenIn($before, $contextUid);
        $actual   = $this->contexts->currentSettings($after);
        ksort($expected);
        ksort($actual);

        return $expected === $actual;
    }

    /**
     * Deletes the failed copy, as the editor; returns the DataHandler errors
     * of that run.
     *
     * @return array<array-key, mixed>
     */
    private function delete(int $uid, BackendUserAuthentication $backendUser): array
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['tt_content' => [$uid => ['delete' => 1]]], $backendUser);
        $dataHandler->process_cmdmap();

        return $dataHandler->errorLog;
    }

    /**
     * An access field's value, with every "no restriction" ('', '0', 0,
     * null: the column default differs per field and database) as ''.
     */
    private function restriction(mixed $value): string
    {
        return in_array($value, [null, '', 0, '0'], true) ? '' : $this->stringValue($value);
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
