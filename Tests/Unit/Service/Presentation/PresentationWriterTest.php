<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Presentation;

use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use Netresearch\T3Cowriter\Service\Presentation\Layout;
use Netresearch\T3Cowriter\Service\Presentation\PresentationAccess;
use Netresearch\T3Cowriter\Service\Presentation\PresentationElement;
use Netresearch\T3Cowriter\Service\Presentation\PresentationException;
use Netresearch\T3Cowriter\Service\Presentation\PresentationWriter;
use Netresearch\T3Cowriter\Service\Presentation\SectionContent;
use Netresearch\T3Cowriter\Tests\Support\RecordingLogger;
use Netresearch\T3Cowriter\Tests\Unit\EventListener\RegisterPresentationControlListenerTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * What the writer hands to DataHandler and how it judges the outcome. The
 * functional test of the same name runs it against the real DataHandler.
 */
#[CoversClass(PresentationWriter::class)]
#[CoversClass(PresentationException::class)]
#[CoversClass(PresentationElement::class)]
#[CoversClass(PresentationAccess::class)]
#[CoversClass(Tca::class)]
final class PresentationWriterTest extends TestCase
{
    /** @var list<array{data: array<array-key, mixed>, commands: array<array-key, mixed>}> */
    private array $started = [];

    private RecordingLogger $logger;

    private int $expectedWorkspace = 0;

    protected function setUp(): void
    {
        $tca = RegisterPresentationControlListenerTest::tca(true, true);
        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        $tca['tt_content']['ctrl'] = ['languageField' => 'sys_language_uid'];
        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        $tca['tx_bootstrappackage_tab_item']['ctrl'] = ['languageField' => 'sys_language_uid'];
        $GLOBALS['TCA']                              = $tca;
        $this->logger                                = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        unset($GLOBALS['TCA']);
        parent::tearDown();
    }

    /**
     * Queues a DataHandler that records what it is started with and, when it
     * processes a datamap, reports $newIds and $errors.
     *
     * @param array<string, int> $newIds
     * @param list<string>       $errors
     */
    private function queueDataHandler(array $newIds = [], array $errors = [], bool $mock = false): DataHandler&Stub
    {
        $dataHandler = $mock ? $this->createMock(DataHandler::class) : $this->createStub(DataHandler::class);
        $dataHandler->method('start')->willReturnCallback(function (mixed $data, mixed $commands): void {
            $this->started[] = ['data' => is_array($data) ? $data : [], 'commands' => is_array($commands) ? $commands : []];
        });
        $dataHandler->method('process_datamap')->willReturnCallback(static function () use ($dataHandler, $newIds, $errors): void {
            $dataHandler->substNEWwithIDs = $newIds;
            $dataHandler->errorLog        = $errors;
        });
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);

        return $dataHandler;
    }

    /**
     * @param array<string, mixed>|null $stored the new element as read back
     */
    private function writer(?array $stored): PresentationWriter
    {
        $finder = $this->createStub(RecordFinder::class);
        $finder->method('findRecord')->willReturnCallback(
            fn (string $table, int $uid, int $workspaceId): ?array => $table === 'tt_content' && $uid === 50 && $workspaceId === $this->expectedWorkspace ? $stored : null,
        );

        return new PresentationWriter($finder, $this->logger);
    }

    private function element(int $languageId = 0): PresentationElement
    {
        return new PresentationElement(10, 2, $languageId, 3, 'Office chairs', '', [Layout::Tabs]);
    }

    /**
     * @return list<SectionContent>
     */
    private function sections(): array
    {
        return [new SectionContent('Seat', '<p>Height</p>', 'Height'), new SectionContent('Warranty', '<p>Five</p>', 'Five')];
    }

    private const CREATED = ['NEWcowriterElement' => 50, 'NEWcowriterItem0' => 61, 'NEWcowriterItem1' => 62];

    private const STORED = ['uid' => 50, 'CType' => 'tab', 'tx_bootstrappackage_tab_item' => 2];

    #[Test]
    public function theNewElementGoesAfterTheOriginalWithItsItemsInOrder(): void
    {
        $this->queueDataHandler(self::CREATED);
        $user = $this->createStub(BackendUserAuthentication::class);

        $uid = $this->writer(self::STORED)->create($this->element(1), Layout::Tabs, $this->sections(), $user);

        self::assertSame(50, $uid);
        self::assertCount(1, $this->started, 'no clean-up run');
        self::assertSame([
            'tx_bootstrappackage_tab_item' => [
                'NEWcowriterItem0' => ['pid' => 2, 'header' => 'Seat', 'bodytext' => '<p>Height</p>', 'sys_language_uid' => 1],
                'NEWcowriterItem1' => ['pid' => 2, 'header' => 'Warranty', 'bodytext' => '<p>Five</p>', 'sys_language_uid' => 1],
            ],
            'tt_content' => [
                'NEWcowriterElement' => [
                    'pid'                          => -10,
                    'CType'                        => 'tab',
                    'colPos'                       => 3,
                    'header'                       => 'Office chairs',
                    'tx_bootstrappackage_tab_item' => 'NEWcowriterItem0,NEWcowriterItem1',
                    'sys_language_uid'             => 1,
                ],
            ],
        ], $this->started[0]['data']);
        self::assertSame([], $this->started[0]['commands']);
        self::assertSame([], $this->logger->records);
    }

    #[Test]
    public function tablesWithoutLanguageFieldGetNone(): void
    {
        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        unset($GLOBALS['TCA']['tt_content']['ctrl'], $GLOBALS['TCA']['tx_bootstrappackage_tab_item']['ctrl']);
        $this->queueDataHandler(self::CREATED);

        $this->writer(self::STORED)->create($this->element(), Layout::Tabs, $this->sections(), $this->createStub(BackendUserAuthentication::class));

        $data = $this->started[0]['data'];
        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        self::assertArrayNotHasKey('sys_language_uid', $data['tt_content']['NEWcowriterElement']);
        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        self::assertArrayNotHasKey('sys_language_uid', $data['tx_bootstrappackage_tab_item']['NEWcowriterItem0']);
    }

    /**
     * @return iterable<string, array{array<string, int>, list<string>, array<string, mixed>|null, array<string, array<int, array{delete: int}>>}>
     */
    public static function failedCreations(): iterable
    {
        yield 'DataHandler reported an error' => [
            self::CREATED, ['[1.1]: refused'], self::STORED,
            ['tt_content' => [50 => ['delete' => 1]], 'tx_bootstrappackage_tab_item' => [61 => ['delete' => 1], 62 => ['delete' => 1]]],
        ];
        yield 'the element was not created' => [
            ['NEWcowriterItem0' => 61, 'NEWcowriterItem1' => 62], [], self::STORED,
            ['tx_bootstrappackage_tab_item' => [61 => ['delete' => 1], 62 => ['delete' => 1]]],
        ];
        yield 'an item was not created' => [
            ['NEWcowriterElement' => 50, 'NEWcowriterItem0' => 61], [], self::STORED,
            ['tt_content' => [50 => ['delete' => 1]], 'tx_bootstrappackage_tab_item' => [61 => ['delete' => 1]]],
        ];
        yield 'the CType was dropped' => [
            self::CREATED, [], ['CType' => 'text'] + self::STORED,
            ['tt_content' => [50 => ['delete' => 1]], 'tx_bootstrappackage_tab_item' => [61 => ['delete' => 1], 62 => ['delete' => 1]]],
        ];
        yield 'the items were not linked' => [
            self::CREATED, [], ['tx_bootstrappackage_tab_item' => 0] + self::STORED,
            ['tt_content' => [50 => ['delete' => 1]], 'tx_bootstrappackage_tab_item' => [61 => ['delete' => 1], 62 => ['delete' => 1]]],
        ];
        yield 'the element cannot be read back' => [
            self::CREATED, [], null,
            ['tt_content' => [50 => ['delete' => 1]], 'tx_bootstrappackage_tab_item' => [61 => ['delete' => 1], 62 => ['delete' => 1]]],
        ];
    }

    /**
     * @param array<string, int>                            $newIds
     * @param list<string>                                  $errors
     * @param array<string, mixed>|null                     $stored
     * @param array<string, array<int, array{delete: int}>> $expectedDeletes
     */
    #[Test]
    #[DataProvider('failedCreations')]
    public function aCreationThatDidNotCompleteIsUndone(array $newIds, array $errors, ?array $stored, array $expectedDeletes): void
    {
        $this->queueDataHandler($newIds, $errors);
        $cleanUp = $this->queueDataHandler([], [], true);
        self::assertInstanceOf(MockObject::class, $cleanUp);
        $cleanUp->expects(self::once())->method('process_cmdmap');

        try {
            $this->writer($stored)->create($this->element(), Layout::Tabs, $this->sections(), $this->createStub(BackendUserAuthentication::class));
            self::fail('The creation must fail');
        } catch (PresentationException $e) {
            self::assertSame('presentation.error.createFailed', $e->getLabelKey());
        }

        self::assertCount(2, $this->started);
        self::assertSame([], $this->started[1]['data']);
        self::assertSame($expectedDeletes, $this->started[1]['commands']);
        self::assertCount(1, $this->logger->records);
        self::assertSame(['element' => 10, 'layout' => 'tab', 'errors' => $errors], $this->logger->records[0]['context']);
    }

    #[Test]
    public function nothingCreatedMeansNothingToUndo(): void
    {
        $this->queueDataHandler([], ['[1.1]: refused']);

        try {
            $this->writer(null)->create($this->element(), Layout::Tabs, $this->sections(), $this->createStub(BackendUserAuthentication::class));
            self::fail('The creation must fail');
        } catch (PresentationException) {
        }

        self::assertCount(1, $this->started);
    }

    #[Test]
    public function withoutSectionsOrItemTableNothingIsStarted(): void
    {
        $user = $this->createStub(BackendUserAuthentication::class);

        try {
            $this->writer(null)->create($this->element(), Layout::Tabs, [], $user);
            self::fail('The creation must fail');
        } catch (PresentationException) {
        }

        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        unset($GLOBALS['TCA']['tt_content']['columns']['tx_bootstrappackage_accordion_item']);

        try {
            $this->writer(null)->create($this->element(), Layout::Accordion, $this->sections(), $user);
            self::fail('The creation must fail');
        } catch (PresentationException) {
        }

        self::assertSame([], $this->started);
    }

    #[Test]
    public function theResultIsReadBackInTheEditorsWorkspace(): void
    {
        $this->queueDataHandler(self::CREATED);
        $user            = $this->createStub(BackendUserAuthentication::class);
        $user->workspace = 4;

        $this->expectedWorkspace = 4;

        self::assertSame(50, $this->writer(self::STORED)->create($this->element(), Layout::Tabs, $this->sections(), $user));

        $this->queueDataHandler(self::CREATED);
        $user->workspace         = -1;
        $this->expectedWorkspace = 0;

        self::assertSame(50, $this->writer(self::STORED)->create($this->element(), Layout::Tabs, $this->sections(), $user));
    }

    #[Test]
    public function numbersFromTheDatabaseMayComeAsStrings(): void
    {
        $this->queueDataHandler(['NEWcowriterElement' => '50', 'NEWcowriterItem0' => '61', 'NEWcowriterItem1' => '62']);

        $uid = $this->writer(['tx_bootstrappackage_tab_item' => '2'] + self::STORED)
            ->create($this->element(), Layout::Tabs, $this->sections(), $this->createStub(BackendUserAuthentication::class));

        self::assertSame(50, $uid);
    }

    #[Test]
    public function anElementWithoutItemCountIsNotTakenForOneWithOneItem(): void
    {
        $this->queueDataHandler(['NEWcowriterElement' => 50, 'NEWcowriterItem0' => 61]);
        $this->queueDataHandler();
        $stored = self::STORED;
        unset($stored['tx_bootstrappackage_tab_item']);

        $this->expectExceptionObject(PresentationException::createFailed());

        $this->writer($stored)->create($this->element(), Layout::Tabs, [$this->sections()[0]], $this->createStub(BackendUserAuthentication::class));
    }
}
