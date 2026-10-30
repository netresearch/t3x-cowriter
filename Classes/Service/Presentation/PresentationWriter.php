<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Presentation;

use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Creates the new tab or accordion element directly after the original, with
 * one item per section, hidden until the editor switches the two
 * (PresentationSwitcher), through DataHandler as the editor: page permissions,
 * the workspace, excluded fields and the rich text transformation of the item
 * bodytext apply as when the editor saves the form. The original element is
 * not touched.
 *
 * DataHandler is not transactional: a refused item table is reported, but the
 * element written before it stays. So an error, a missing record or an element
 * that is not stored as intended undoes the creation: what was created is
 * deleted again (as the editor) and the creation fails.
 *
 * @internal
 */
class PresentationWriter
{
    /**
     * Fields of a file reference the copy takes over.
     */
    private const FILE_REFERENCE_FIELDS = ['uid_local', 'title', 'alternative', 'description', 'link', 'crop', 'hidden'];

    public function __construct(
        private readonly RecordFinder $recordFinder,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param list<SectionContent> $sections
     *
     * @return int uid of the new element
     *
     * @throws PresentationException when the records could not be created
     */
    public function create(
        PresentationElement $element,
        Layout $layout,
        array $sections,
        BackendUserAuthentication $backendUser,
    ): int {
        $relation = PresentationAccess::itemRelation($layout);
        if ($relation === null || $sections === []) {
            throw PresentationException::createFailed();
        }

        $languageField = Tca::languageField($relation['table']);
        $hiddenField   = PresentationAccess::hiddenField();
        if ($hiddenField === null) {
            throw PresentationException::createFailed();
        }

        // NEW ids without an underscore: DataHandler reads "table_NEW…" in an
        // inline value as a reference into another table.
        $itemIds = [];
        $data    = [];
        foreach ($sections as $index => $section) {
            $itemId = 'NEWcowriterItem' . $index;
            $item   = ['pid' => $element->pageUid, 'header' => $section->title, 'bodytext' => $section->html];
            // DataHandler refuses a new record whose data lacks the language field.
            if ($languageField !== null) {
                $item[$languageField] = $element->languageId;
            }

            $data[$relation['table']][$itemId] = $item;
            $itemIds[]                         = $itemId;
        }

        // The images of the original go into the first item.
        $fileIds = $element->takesFiles($layout) ? $this->addFileReferences($data, $element) : [];
        if ($fileIds !== []) {
            $data[$relation['table']][$itemIds[0]]['media'] = implode(',', $fileIds);
        }

        // A negative pid places the record after that one on the same page.
        // Column and language are set as the form sends them: DataHandler
        // refuses a new record whose data lacks the language field, before it
        // would copy it from the original. Access restrictions and appearance
        // come from the original; it is hidden until the editor switches it
        // with the original, and the items stay visible inside it.
        $newElement = $element->copiedFields + [
            'pid'                 => -$element->uid,
            'CType'               => $layout->value,
            'colPos'              => $element->columnPosition,
            'header'              => $element->header,
            $layout->itemColumn() => implode(',', $itemIds),
            $hiddenField          => 1,
        ];
        $elementLanguageField = Tca::languageField('tt_content');
        if ($elementLanguageField !== null) {
            $newElement[$elementLanguageField] = $element->languageId;
        }

        $data['tt_content'] = ['NEWcowriterElement' => $newElement];

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, [], $backendUser);
        $dataHandler->process_datamap();

        $elementUid = $this->newUid($dataHandler, 'NEWcowriterElement');
        $itemUids   = array_map(fn (string $itemId): int => $this->newUid($dataHandler, $itemId), $itemIds);
        $fileUids   = array_map(fn (string $fileId): int => $this->newUid($dataHandler, $fileId), $fileIds);

        // An element that was not created (uid 0) is not found by the read-back.
        if ($dataHandler->errorLog === []
            && !in_array(0, $itemUids, true)
            && !in_array(0, $fileUids, true)
            && $this->isStoredAsIntended($elementUid, $element, $layout, count($sections), $backendUser)
            && $this->firstItemHasFiles($relation['table'], $itemUids[0] ?? 0, count($fileIds), $backendUser)
        ) {
            return $elementUid;
        }

        $this->logger->error('Alternative presentation: the new element could not be created as intended', [
            'element' => $element->uid,
            'layout'  => $layout->value,
            'errors'  => $dataHandler->errorLog,
        ]);

        $cleanUpErrors = $this->deleteCreated(['tt_content' => [$elementUid], $relation['table'] => $itemUids, 'sys_file_reference' => $fileUids], $backendUser);
        if ($cleanUpErrors !== []) {
            $this->logger->error('Alternative presentation: what was created could not be removed', [
                'element' => $elementUid,
                'items'   => $itemUids,
                'errors'  => $cleanUpErrors,
            ]);

            throw PresentationException::createIncomplete();
        }

        throw PresentationException::createFailed();
    }

    /**
     * Adds a new file reference per image of the original to $data and
     * returns their NEW ids. Each keeps the file and its own texts and crop.
     *
     * @param array<string, array<string, array<string, mixed>>> $data
     *
     * @return list<string>
     */
    private function addFileReferences(array &$data, PresentationElement $element): array
    {
        $languageField = Tca::languageField('sys_file_reference');
        $ids           = [];
        foreach ($element->fileReferences as $index => $reference) {
            $id   = 'NEWcowriterFile' . $index;
            $copy = ['pid' => $element->pageUid];
            foreach (self::FILE_REFERENCE_FIELDS as $field) {
                if (array_key_exists($field, $reference) && Tca::column('sys_file_reference', $field) !== null) {
                    $copy[$field] = $reference[$field];
                }
            }

            if ($languageField !== null) {
                $copy[$languageField] = $element->languageId;
            }

            $data['sys_file_reference'][$id] = $copy;
            $ids[]                           = $id;
        }

        return $ids;
    }

    /**
     * The new element as the user's workspace shows it has the layout's CType,
     * all its items, is hidden and has the original's access restrictions. A
     * second line of defence: the checks before the write and DataHandler's
     * own error log catch the cases known so far.
     */
    private function isStoredAsIntended(int $uid, PresentationElement $element, Layout $layout, int $itemCount, BackendUserAuthentication $backendUser): bool
    {
        $row = $this->recordFinder->findRecord('tt_content', $uid, max(0, $backendUser->workspace));
        if ($row === null || ($row['CType'] ?? null) !== $layout->value || $this->intValue($row[PresentationAccess::hiddenField() ?? 'hidden'] ?? null) !== 1) {
            return false;
        }

        foreach (PresentationAccess::accessFields() as $field) {
            if ($this->scalarString($row[$field] ?? null) !== $this->scalarString($element->copiedFields[$field] ?? null)) {
                return false;
            }
        }

        return $this->intValue($row[$layout->itemColumn()] ?? null) === $itemCount;
    }

    /**
     * The first item carries the expected number of file references.
     */
    private function firstItemHasFiles(string $itemTable, int $itemUid, int $fileCount, BackendUserAuthentication $backendUser): bool
    {
        if ($fileCount === 0) {
            return true;
        }

        $row = $this->recordFinder->findRecord($itemTable, $itemUid, max(0, $backendUser->workspace));

        return $row !== null && $this->intValue($row['media'] ?? null) === $fileCount;
    }

    /**
     * Empty and 0 are the same "no restriction" for the comparison.
     */
    private function scalarString(mixed $value): string
    {
        $string = is_scalar($value) ? (string) $value : '';

        return $string === '0' ? '' : $string;
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function newUid(DataHandler $dataHandler, string $id): int
    {
        $uid = $dataHandler->substNEWwithIDs[$id] ?? 0;

        return is_numeric($uid) ? (int) $uid : 0;
    }

    /**
     * Deletes what the failed creation made, as the editor; returns the
     * DataHandler errors of that run.
     *
     * @param array<string, list<int>> $created
     *
     * @return array<array-key, mixed>
     */
    private function deleteCreated(array $created, BackendUserAuthentication $backendUser): array
    {
        /** @var array<string, array<int, array{delete: int}>> $commands */
        $commands = [];
        foreach ($created as $table => $uids) {
            foreach ($uids as $uid) {
                if ($uid > 0) {
                    $commands[$table][$uid] = ['delete' => 1];
                }
            }
        }

        if ($commands === []) {
            return [];
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $commands, $backendUser);
        $dataHandler->process_cmdmap();

        return $dataHandler->errorLog;
    }
}
