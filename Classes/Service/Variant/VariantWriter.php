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
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
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
 * The copy leaves the element's translations behind: copied, they would
 * carry the old wording. The header and text reach the copy as the update
 * DataHandler runs after the copy command and its processCmdmap_postProcess
 * hooks, so the copy is saved like an edited element: an extension that
 * translates a saved element and pauses during a copy (EXT:autotranslate
 * 3.6 does both) gets that save with the new text.
 *
 * show: makes the copy and its translations visible and, when the editor
 * asks for it, hides the original in the copy's context.
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
        private readonly ?SiteFinder $siteFinder = null,
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
        // The paste action unwraps 'target' once, and the copy command reads
        // 'target' and 'ignoreLocalization' from what is left (TYPO3 13.4 and
        // 14.3). ignoreLocalization also skips copyRecord()'s edit check, whose
        // only part the reader does not repeat is the access to every language
        // of the translations; with none copied, there is nothing it protects.
        $dataHandler->start([], ['tt_content' => [$element->uid => ['copy' => [
            'action' => 'paste',
            'target' => ['target' => -$element->uid, 'ignoreLocalization' => true],
            'update' => $update,
        ]]]], $backendUser);
        $dataHandler->process_cmdmap();

        $copies     = $dataHandler->copyMappingArray_merged['tt_content'] ?? null;
        $copied     = is_array($copies) ? ($copies[$element->uid] ?? 0) : 0;
        $variantUid = is_numeric($copied) ? (int) $copied : 0;

        if ($variantUid > 0 && $dataHandler->errorLog === []
            && !$this->tookTranslationsAlong($element, is_array($copies) ? $copies : [], $backendUser)
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
     * Makes the variant and its translations visible, and hides the original
     * in $contextUid when $hideOriginal is set. The original keeps every
     * other context setting it has.
     *
     * @throws VariantException when a record is not there, not a pair this
     *                          writer created, or the change did not come
     *                          through
     */
    public function show(int $variantUid, int $originalUid, int $contextUid, bool $hideOriginal, BackendUserAuthentication $backendUser): void
    {
        $workspaceId  = max(0, $backendUser->workspace);
        $variant      = $this->recordFinder->findRecord('tt_content', $variantUid, $workspaceId);
        $original     = $this->recordFinder->findRecord('tt_content', $originalUid, $workspaceId);
        $hiddenField  = PresentationAccess::hiddenField();
        $pointerField = Tca::transOrigPointerField('tt_content');
        // A translation made from the hidden variant (by an extension that
        // translates a saved element, or by the editor) is hidden with it. A
        // hidden translation is not overlaid, so a translated page would show
        // the visible variant in the default language: they are shown with it.
        $translations = $pointerField !== null
            ? $this->recordFinder->findTranslations('tt_content', $pointerField, $variantUid, $workspaceId)
            : [];

        // The pair as create() left it: on one page, the variant visible only
        // in its context. All records are checked before the one write, so
        // the variant is not shown while DataHandler refuses the original or a
        // translation (an edit lock, a language the editor lost), and the
        // original is not hidden while DataHandler silently drops the
        // variant's hidden field (the editor lost the right on it since the
        // creation).
        if ($variant === null || $original === null || $hiddenField === null || $contextUid < 1
            || $this->intValue($variant['pid'] ?? 0) !== $this->intValue($original['pid'] ?? 0)
            || !$this->contexts->isEnabledIn($variant, $contextUid)
            || !$this->access->mayWrite($backendUser, 'tt_content', $hiddenField)
            || !$this->recordEditAccess->mayEdit($backendUser, 'tt_content', $variant)
            || ($hideOriginal && !$this->recordEditAccess->mayEdit($backendUser, 'tt_content', $original))
            || !$this->mayEditAll($backendUser, $translations)
        ) {
            throw VariantException::notAvailable();
        }

        $originalTranslations = $hideOriginal && $pointerField !== null
            ? $this->recordFinder->findTranslations('tt_content', $pointerField, $originalUid, $workspaceId)
            : [];
        $this->refuseForLanguages($this->intValue($variant['pid'] ?? 0), $contextUid, $translations, $originalTranslations);

        $data = [$variantUid => [$hiddenField => 0]];
        foreach ($translations as $translation) {
            $data[$this->intValue($translation['uid'] ?? 0)] = [$hiddenField => 0];
        }

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
            && $this->allShown($translations, $hiddenField, $workspaceId)
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
     * Whether a translation of the element was copied with it, in the old
     * wording: when a DataHandler version no longer honours the flag, the
     * copy is deleted (its translations with it) instead of kept.
     *
     * @param array<array-key, mixed> $copies copyMappingArray_merged of tt_content
     */
    private function tookTranslationsAlong(VariantElement $element, array $copies, BackendUserAuthentication $backendUser): bool
    {
        $pointerField = Tca::transOrigPointerField('tt_content');
        if ($pointerField === null) {
            return false;
        }

        foreach ($this->recordFinder->findTranslations('tt_content', $pointerField, $element->uid, max(0, $backendUser->workspace)) as $translation) {
            if (array_key_exists($this->intValue($translation['uid'] ?? 0), $copies)) {
                return true;
            }
        }

        return false;
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
     * A language in free mode selects its records on their own columns, not
     * as overlays of the default language: the context of the variant and
     * the original does not reach their translations there. A translation of
     * the variant that is not restricted to exactly that context itself would
     * be shown to every visitor (or in another context), and the original's translation would stay next to it.
     *
     * Hiding the original in the context hides its translations there too;
     * each needs a translation of the variant in its language that is
     * published at least whenever the original's translation is.
     *
     * @param list<array<string, mixed>> $translations         of the variant
     * @param list<array<string, mixed>> $originalTranslations of the original, none when it is not hidden
     *
     * @throws VariantException
     */
    private function refuseForLanguages(int $pageUid, int $contextUid, array $translations, array $originalTranslations): void
    {
        $freeLanguages = $this->freeLanguages($pageUid);
        foreach ($translations as $translation) {
            // Restricted to exactly this context: a translation enabled in a
            // second context would show the audience text there too.
            if (in_array($this->language($translation), $freeLanguages, true)
                && (!$this->contexts->isEnabledIn($translation, $contextUid) || $this->contexts->currentSettings($translation) !== $this->contexts->onlyIn($contextUid))
            ) {
                throw VariantException::freeLanguage();
            }
        }

        foreach ($originalTranslations as $originalTranslation) {
            if (in_array($this->language($originalTranslation), $freeLanguages, true)) {
                throw VariantException::freeLanguage();
            }

            if (!$this->isCovered($originalTranslation, $translations)) {
                throw VariantException::originalTranslated();
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function mayEditAll(BackendUserAuthentication $backendUser, array $rows): bool
    {
        foreach ($rows as $row) {
            if (!$this->recordEditAccess->mayEdit($backendUser, 'tt_content', $row)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function language(array $row): int
    {
        return $this->intValue($row[Tca::languageField('tt_content') ?? 'sys_language_uid'] ?? 0);
    }

    /**
     * The ids of the languages the site of $pageUid shows in free mode
     * (fallbackType free); none for a page outside every site.
     *
     * @return list<int>
     */
    private function freeLanguages(int $pageUid): array
    {
        try {
            $site = ($this->siteFinder ?? GeneralUtility::makeInstance(SiteFinder::class))->getSiteByPageId($pageUid);
        } catch (SiteNotFoundException) {
            return [];
        }

        $free = [];
        foreach ($site->getAllLanguages() as $siteLanguage) {
            if ($siteLanguage->getFallbackType() === 'free') {
                $free[] = $siteLanguage->getLanguageId();
            }
        }

        return $free;
    }

    /**
     * Whether a translation of the variant in the language of
     * $originalTranslation is published at least whenever that translation
     * is: it starts no later and ends no earlier (0 means no limit).
     *
     * @param array<string, mixed>       $originalTranslation
     * @param list<array<string, mixed>> $variantTranslations
     */
    private function isCovered(array $originalTranslation, array $variantTranslations): bool
    {
        $startField = Tca::enableColumn('tt_content', 'starttime');
        $endField   = Tca::enableColumn('tt_content', 'endtime');
        $start      = $startField === null ? 0 : $this->intValue($originalTranslation[$startField] ?? 0);
        $end        = $endField === null ? 0 : $this->intValue($originalTranslation[$endField] ?? 0);

        foreach ($variantTranslations as $variantTranslation) {
            if ($this->language($variantTranslation) !== $this->language($originalTranslation)) {
                continue;
            }

            $variantStart = $startField === null ? 0 : $this->intValue($variantTranslation[$startField] ?? 0);
            $variantEnd   = $endField === null ? 0 : $this->intValue($variantTranslation[$endField] ?? 0);
            if ($variantStart <= $start && ($variantEnd === 0 || ($end !== 0 && $variantEnd >= $end))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every one of the given translations is visible now.
     *
     * @param list<array<string, mixed>> $translations
     */
    private function allShown(array $translations, string $hiddenField, int $workspaceId): bool
    {
        foreach ($translations as $translation) {
            $after = $this->recordFinder->findRecord('tt_content', $this->intValue($translation['uid'] ?? 0), $workspaceId);
            if ($after === null || $this->intValue($after[$hiddenField] ?? 1) !== 0) {
                return false;
            }
        }

        return true;
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
