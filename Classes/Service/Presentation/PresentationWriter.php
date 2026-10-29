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
 * one item per section, through DataHandler as the editor: page permissions,
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

        // A negative pid places the record after that one on the same page.
        // Column and language are set as the form sends them: DataHandler
        // refuses a new record whose data lacks the language field, before it
        // would copy it from the original.
        $newElement = [
            'pid'                 => -$element->uid,
            'CType'               => $layout->value,
            'colPos'              => $element->columnPosition,
            'header'              => $element->header,
            $layout->itemColumn() => implode(',', $itemIds),
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

        // An element that was not created (uid 0) is not found by the read-back.
        if ($dataHandler->errorLog === []
            && !in_array(0, $itemUids, true)
            && $this->isStoredAsIntended($elementUid, $layout, count($sections), $backendUser)
        ) {
            return $elementUid;
        }

        $this->logger->error('Alternative presentation: the new element could not be created as intended', [
            'element' => $element->uid,
            'layout'  => $layout->value,
            'errors'  => $dataHandler->errorLog,
        ]);
        $this->deleteCreated($elementUid, $relation['table'], $itemUids, $backendUser);

        throw PresentationException::createFailed();
    }

    /**
     * The new element as the user's workspace shows it has the layout's CType
     * and all its items. A second line of defence: the checks before the write
     * and DataHandler's own error log catch the cases known so far.
     */
    private function isStoredAsIntended(int $uid, Layout $layout, int $itemCount, BackendUserAuthentication $backendUser): bool
    {
        $row = $this->recordFinder->findRecord('tt_content', $uid, max(0, $backendUser->workspace));
        if ($row === null || ($row['CType'] ?? null) !== $layout->value) {
            return false;
        }

        $stored = $row[$layout->itemColumn()] ?? 0;

        return is_numeric($stored) && (int) $stored === $itemCount;
    }

    private function newUid(DataHandler $dataHandler, string $id): int
    {
        $uid = $dataHandler->substNEWwithIDs[$id] ?? 0;

        return is_numeric($uid) ? (int) $uid : 0;
    }

    /**
     * @param list<int> $itemUids
     */
    private function deleteCreated(int $elementUid, string $itemTable, array $itemUids, BackendUserAuthentication $backendUser): void
    {
        /** @var array<string, array<int, array{delete: int}>> $commands */
        $commands = [];
        foreach ([['tt_content', [$elementUid]], [$itemTable, $itemUids]] as [$table, $uids]) {
            foreach ($uids as $uid) {
                if ($uid > 0) {
                    $commands[$table][$uid] = ['delete' => 1];
                }
            }
        }

        if ($commands === []) {
            return;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $commands, $backendUser);
        $dataHandler->process_cmdmap();
    }
}
