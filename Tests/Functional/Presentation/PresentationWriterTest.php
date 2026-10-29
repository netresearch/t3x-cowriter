<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Presentation;

use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\Presentation\BlockParser;
use Netresearch\T3Cowriter\Service\Presentation\Layout;
use Netresearch\T3Cowriter\Service\Presentation\PresentationAccess;
use Netresearch\T3Cowriter\Service\Presentation\PresentationElement;
use Netresearch\T3Cowriter\Service\Presentation\PresentationElementReader;
use Netresearch\T3Cowriter\Service\Presentation\PresentationException;
use Netresearch\T3Cowriter\Service\Presentation\PresentationWriter;
use Netresearch\T3Cowriter\Service\Presentation\SectionPlanner;
use Netresearch\T3Cowriter\Service\RecordEditAccess;
use Netresearch\T3Cowriter\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The new tab or accordion element written through DataHandler as the editor,
 * with bootstrap_package's real TCA: placement, items, the rich text
 * transformation of the item bodytext, workspaces, and every refusal that
 * DataHandler itself would only have answered by dropping a value.
 *
 * Fixture: element 10 on page 2 (colPos 1) has an intro and two h2 sections;
 * element 11 follows it.
 */
#[CoversClass(PresentationElementReader::class)]
#[CoversClass(PresentationAccess::class)]
#[CoversClass(PresentationWriter::class)]
#[CoversClass(PresentationElement::class)]
#[CoversClass(RecordEditAccess::class)]
final class PresentationWriterTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor', 'seo', 'workspaces', 'extensionmanager', 'install'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
        'bk2k/bootstrap-package',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/elements.csv');
    }

    private function reader(): PresentationElementReader
    {
        return new PresentationElementReader($this->finder());
    }

    private function finder(): RecordFinder
    {
        return new RecordFinder(GeneralUtility::makeInstance(ConnectionPool::class));
    }

    private function editor(int $uid, int $workspace = 0): BackendUserAuthentication
    {
        $backendUser = $this->setUpBackendUser($uid);
        if ($workspace > 0) {
            $backendUser->setWorkspace($workspace);
            self::assertSame($workspace, $backendUser->workspace, 'the editor works in the workspace');
        }

        return $backendUser;
    }

    private function createFor(int $uid, Layout $layout, BackendUserAuthentication $backendUser): int
    {
        $element  = $this->reader()->read($uid, $backendUser);
        $blocks   = (new BlockParser())->parse($element->bodytext);
        $planner  = new SectionPlanner();
        $sections = $planner->contents($blocks, $planner->fromHeadings($blocks, 'Introduction'));

        $logger = new RecordingLogger();

        try {
            return (new PresentationWriter($this->finder(), $logger))->create($element, $layout, $sections, $backendUser);
        } catch (PresentationException $e) {
            // DataHandler's own words, when it refused.
            self::fail($e->getMessage() . ' ' . json_encode($logger->records, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, string $field, int $value): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder->select('*')->from($table)
            ->where($queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($value, Connection::PARAM_INT)))
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function liveContentOf(string $ctype): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder->select('*')->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter($ctype)),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    #[Test]
    public function anEditorGetsBothLayoutsForATextElement(): void
    {
        $element = $this->reader()->read(10, $this->editor(2));

        self::assertSame([Layout::Tabs, Layout::Accordion], $element->layouts);
        self::assertSame(2, $element->pageUid);
        self::assertSame('Office chairs', $element->header);
    }

    #[Test]
    public function theTabElementIsCreatedDirectlyAfterTheOriginalWithOneItemPerSection(): void
    {
        $original = $this->rows('tt_content', 'uid', 10)[0];

        $uid = $this->createFor(10, Layout::Tabs, $this->editor(2));

        $element = $this->rows('tt_content', 'uid', $uid)[0];
        self::assertSame('tab', $element['CType']);
        self::assertSame(2, (int) $element['pid']);
        self::assertSame(1, (int) $element['colPos'], 'colPos of the original');
        self::assertSame(0, (int) $element['sys_language_uid']);
        self::assertSame('Office chairs', $element['header']);
        self::assertGreaterThan((int) $original['sorting'], (int) $element['sorting']);
        self::assertLessThan(512, (int) $element['sorting'], 'before the next element');
        self::assertSame(3, (int) $element['tx_bootstrappackage_tab_item']);

        $items = $this->rows('tx_bootstrappackage_tab_item', 'tt_content', $uid);
        self::assertSame(['Introduction', 'Seat', 'Warranty'], array_column($items, 'header'));
        self::assertSame([2, 2, 2], array_map(intval(...), array_column($items, 'pid')));
        self::assertSame('<p>Our chairs fit every office.</p>', $items[0]['bodytext']);
        // The rich text transformation stores line breaks as CRLF.
        self::assertSame("<p>Adjustable seat height.</p>\r\n<ul><li>Lumbar support</li></ul>", $items[1]['bodytext']);
        self::assertStringContainsString('<p>Five years on every frame.</p>', (string) $items[2]['bodytext']);

        // The original element is not touched.
        self::assertSame($original, $this->rows('tt_content', 'uid', 10)[0]);
    }

    #[Test]
    public function theItemBodytextPassesTheRichTextTransformation(): void
    {
        $uid = $this->createFor(10, Layout::Tabs, $this->editor(2));

        $warranty = (string) $this->rows('tx_bootstrappackage_tab_item', 'tt_content', $uid)[2]['bodytext'];
        self::assertStringContainsString('Ask us.', $warranty);
        self::assertStringNotContainsString('<script', $warranty);
        self::assertStringNotContainsString('onclick', $warranty);
        self::assertStringNotContainsString('steal()', $warranty);
    }

    #[Test]
    public function theAccordionElementGetsAccordionItems(): void
    {
        $uid = $this->createFor(10, Layout::Accordion, $this->editor(2));

        self::assertSame('accordion', $this->rows('tt_content', 'uid', $uid)[0]['CType']);
        self::assertSame(
            ['Introduction', 'Seat', 'Warranty'],
            array_column($this->rows('tx_bootstrappackage_accordion_item', 'tt_content', $uid), 'header'),
        );
        self::assertSame([], $this->rows('tx_bootstrappackage_tab_item', 'tt_content', $uid));
    }

    #[Test]
    public function anElementInAnotherLanguageGetsItsLanguageOnTheNewRecords(): void
    {
        $uid = $this->createFor(16, Layout::Tabs, $this->editor(2));

        self::assertSame(1, (int) $this->rows('tt_content', 'uid', $uid)[0]['sys_language_uid']);
        $items = $this->rows('tx_bootstrappackage_tab_item', 'tt_content', $uid);
        self::assertSame(['Eins', 'Zwei'], array_column($items, 'header'));
        self::assertSame([1, 1], array_map(intval(...), array_column($items, 'sys_language_uid')));
    }

    #[Test]
    public function aTranslatedElementNeedsTheRightOnTheItemLanguage(): void
    {
        // Editor 3 may not edit the language field of the item tables: TYPO3
        // 13.4 would store the items in the default language.
        $backendUser = $this->editor(3);
        self::assertSame([Layout::Accordion], $this->reader()->read(10, $backendUser)->layouts, 'default language');

        $this->expectExceptionObject(PresentationException::notAvailable());
        $this->reader()->read(16, $backendUser);
    }

    #[Test]
    public function inAWorkspaceTheNewRecordsAreDraftsOfThatWorkspace(): void
    {
        $uid = $this->createFor(10, Layout::Tabs, $this->editor(2, 1));

        $element = $this->rows('tt_content', 'uid', $uid)[0];
        self::assertSame('tab', $element['CType']);
        self::assertSame(1, (int) $element['t3ver_wsid']);
        self::assertSame(1, (int) $element['t3ver_state'], 'a new record of the workspace');
        self::assertSame(1, (int) $element['colPos']);

        $items = $this->rows('tx_bootstrappackage_tab_item', 'tt_content', $uid);
        self::assertSame(['Introduction', 'Seat', 'Warranty'], array_column($items, 'header'));
        self::assertSame([1, 1, 1], array_map(intval(...), array_column($items, 't3ver_wsid')));

        // Live sees nothing new.
        self::assertNull($this->finder()->findRecord('tt_content', $uid, 0));
        self::assertNotNull($this->finder()->findRecord('tt_content', $uid, 1));
    }

    #[Test]
    public function anEditorWithoutTheTabCTypeGetsOnlyTheAccordion(): void
    {
        self::assertSame([Layout::Accordion], $this->reader()->read(10, $this->editor(3))->layouts);
    }

    #[Test]
    public function anEditorWhoMayNotWriteTheItemHeadersIsRefused(): void
    {
        $this->expectExceptionObject(PresentationException::notAvailable());

        $this->reader()->read(10, $this->editor(4));
    }

    #[Test]
    public function anEditorWhoMayNotModifyTheItemTablesIsRefused(): void
    {
        $this->expectExceptionObject(PresentationException::notAvailable());

        $this->reader()->read(10, $this->editor(5));
    }

    #[Test]
    public function anEditorWithoutContentEditOnThePageIsRefused(): void
    {
        $this->expectExceptionObject(PresentationException::notAvailable());

        $this->reader()->read(15, $this->editor(2));
    }

    #[Test]
    public function anEditorWithoutAccessToTheLanguageIsRefused(): void
    {
        self::assertSame([Layout::Tabs, Layout::Accordion], $this->reader()->read(10, $this->editor(6))->layouts);

        $this->expectExceptionObject(PresentationException::notAvailable());
        $this->reader()->read(16, $this->editor(6));
    }

    #[Test]
    public function pageTsConfigRemoveItemsAndKeepItemsAreRespected(): void
    {
        $backendUser = $this->editor(2);

        self::assertSame([Layout::Accordion], $this->reader()->read(13, $backendUser)->layouts);

        $this->expectExceptionObject(PresentationException::notAvailable());
        $this->reader()->read(14, $backendUser);
    }

    #[Test]
    public function anElementThatIsNoTextElementIsRefused(): void
    {
        $this->expectExceptionObject(PresentationException::notAvailable());

        $this->reader()->read(12, $this->editor(1));
    }

    #[Test]
    public function anUnknownElementIsRefused(): void
    {
        $this->expectExceptionObject(PresentationException::notAvailable());

        $this->reader()->read(999, $this->editor(1));
    }

    #[Test]
    public function aCTypeDataHandlerWouldDropLeavesNoRecordBehind(): void
    {
        $backendUser = $this->editor(3);
        // Bypasses the reader: element 10 as an editor without the tab CType.
        $element = new PresentationElement(10, 2, 0, 1, 'Office chairs', '', [Layout::Tabs]);
        $planner = new SectionPlanner();
        $blocks  = (new BlockParser())->parse('<h2>One</h2><p>A</p><h2>Two</h2><p>B</p>');

        try {
            (new PresentationWriter($this->finder(), new NullLogger()))->create(
                $element,
                Layout::Tabs,
                $planner->contents($blocks, $planner->fromHeadings($blocks, 'Intro')),
                $backendUser,
            );
            self::fail('The creation must fail');
        } catch (PresentationException $e) {
            self::assertSame('presentation.error.createFailed', $e->getLabelKey());
        }

        self::assertSame([], $this->liveContentOf('tab'));
        // Elements 10, 11, 13, 14 and 15: no element of the default type was left behind.
        self::assertCount(5, $this->liveContentOf('text'));
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_bootstrappackage_tab_item');
        $queryBuilder->getRestrictions()->removeAll();
        self::assertSame(0, (int) $queryBuilder->count('uid')->from('tx_bootstrappackage_tab_item')
            ->where($queryBuilder->expr()->eq('deleted', 0))->executeQuery()->fetchOne());
    }
}
