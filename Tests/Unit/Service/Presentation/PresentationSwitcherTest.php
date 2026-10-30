<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Presentation;

use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\Presentation\PresentationException;
use Netresearch\T3Cowriter\Service\Presentation\PresentationSwitcher;
use Netresearch\T3Cowriter\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\AccessCheckResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * What the switcher reads, hands to DataHandler and how it judges the
 * outcome. The functional PresentationSwitchTest runs it against the real
 * DataHandler, live and in a workspace.
 */
#[CoversClass(PresentationSwitcher::class)]
#[CoversClass(PresentationException::class)]
final class PresentationSwitcherTest extends TestCase
{
    private const NEW = ['uid' => 20, 'pid' => 2, 'CType' => 'tab', 'hidden' => 1];

    private const ORIGINAL = ['uid' => 10, 'pid' => 2, 'CType' => 'text', 'hidden' => 0];

    private const PAGE = ['uid' => 2, 'pid' => 1];

    /** @var list<array<array-key, mixed>> */
    private array $started = [];

    /** @var array<string, array<string, mixed>> keyed "table:uid:workspace" */
    private array $records = [];

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $GLOBALS['TCA'] = ['tt_content' => [
            'ctrl'    => ['enablecolumns' => ['disabled' => 'hidden']],
            'columns' => ['hidden' => ['exclude' => true, 'config' => ['type' => 'check']]],
        ]];
        $this->logger  = new RecordingLogger();
        $this->records = ['tt_content:20:0' => self::NEW, 'tt_content:10:0' => self::ORIGINAL, 'pages:2:0' => self::PAGE];
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        unset($GLOBALS['TCA']);
        parent::tearDown();
    }

    /**
     * A DataHandler that records its datamap and, when it runs, stores the
     * hidden values as $stored says (or the requested ones) and reports $errors.
     *
     * @param array<int, int>|null $stored hidden value per uid after the run
     * @param list<string>         $errors
     */
    private function queueDataHandler(?array $stored = null, array $errors = []): void
    {
        $dataHandler = $this->createStub(DataHandler::class);
        $dataHandler->method('start')->willReturnCallback(function (mixed $data): void {
            $this->started[] = is_array($data) ? $data : [];
        });
        $dataHandler->method('process_datamap')->willReturnCallback(function () use ($dataHandler, $stored, $errors): void {
            $data = $this->started[array_key_last($this->started)]['tt_content'] ?? [];
            foreach (is_array($data) ? $data : [] as $uid => $fields) {
                $value = $stored[$uid] ?? (is_array($fields) ? ($fields['hidden'] ?? null) : null);
                foreach (array_keys($this->records) as $key) {
                    if (str_starts_with($key, 'tt_content:' . $uid . ':')) {
                        $this->records[$key]['hidden'] = $value;
                    }
                }
            }

            $dataHandler->errorLog = $errors;
        });
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);
    }

    private function switcher(): PresentationSwitcher
    {
        $finder = $this->createStub(RecordFinder::class);
        $finder->method('findRecord')->willReturnCallback(
            fn (string $table, int $uid, int $workspaceId): ?array => $this->records[$table . ':' . $uid . ':' . $workspaceId] ?? null,
        );

        return new PresentationSwitcher($finder, $this->logger);
    }

    private function editor(bool $allowed = true, int $workspace = 0): BackendUserAuthentication&Stub
    {
        $user = $this->createStub(BackendUserAuthentication::class);
        $user->method('check')->willReturn($allowed);
        $user->method('doesUserHaveAccess')->willReturn($allowed);
        $user->method('checkLanguageAccess')->willReturn($allowed);
        if (method_exists(BackendUserAuthentication::class, 'checkRecordEditAccess')) {
            $user->method('checkRecordEditAccess')->willReturn(new AccessCheckResult($allowed));
        } else {
            $user->method('recordEditAccessInternals')->willReturn($allowed);
        }

        $user->workspace = $workspace;

        return $user;
    }

    #[Test]
    public function theNewElementIsShownAndTheOriginalHiddenInOneRun(): void
    {
        $this->queueDataHandler();

        $this->switcher()->switch(20, 10, $this->editor());

        self::assertSame([['tt_content' => [20 => ['hidden' => 0], 10 => ['hidden' => 1]]]], $this->started);
        self::assertSame([], $this->logger->records);
    }

    #[Test]
    public function inAWorkspaceTheRecordsAreReadAsItShowsThem(): void
    {
        $this->records = ['tt_content:20:3' => self::NEW, 'tt_content:10:3' => self::ORIGINAL, 'pages:2:3' => self::PAGE];
        $this->queueDataHandler();

        $this->switcher()->switch(20, 10, $this->editor(true, 3));

        self::assertCount(1, $this->started);
    }

    /**
     * @return iterable<string, array{array<string, array<string, mixed>>}>
     */
    public static function unreadablePairs(): iterable
    {
        yield 'new element missing' => [['tt_content:10:0' => self::ORIGINAL, 'pages:2:0' => self::PAGE]];
        yield 'original missing' => [['tt_content:20:0' => self::NEW, 'pages:2:0' => self::PAGE]];
        yield 'page missing' => [['tt_content:20:0' => self::NEW, 'tt_content:10:0' => self::ORIGINAL]];
        yield 'on different pages' => [['tt_content:20:0' => self::NEW, 'tt_content:10:0' => ['pid' => 3] + self::ORIGINAL, 'pages:2:0' => self::PAGE]];
        yield 'on the root level' => [['tt_content:20:0' => ['pid' => 0] + self::NEW, 'tt_content:10:0' => ['pid' => 0] + self::ORIGINAL, 'pages:0:0' => self::PAGE]];
        yield 'the new element is no tab element' => [['tt_content:20:0' => ['CType' => 'text'] + self::NEW, 'tt_content:10:0' => self::ORIGINAL, 'pages:2:0' => self::PAGE]];
    }

    /**
     * @param array<string, array<string, mixed>> $records
     */
    #[Test]
    #[DataProvider('unreadablePairs')]
    public function aPairThatIsNotThereIsRefusedBeforeAnyWrite(array $records): void
    {
        $this->records = $records;

        try {
            $this->switcher()->switch(20, 10, $this->editor());
            self::fail('The switch must be refused');
        } catch (PresentationException $e) {
            self::assertSame('presentation.error.notAvailable', $e->getLabelKey());
        }

        self::assertSame([], $this->started);
    }

    #[Test]
    public function anEditorWithoutPermissionIsRefusedBeforeAnyWrite(): void
    {
        $this->expectExceptionObject(PresentationException::notAvailable());

        try {
            $this->switcher()->switch(20, 10, $this->editor(false));
        } finally {
            self::assertSame([], $this->started);
        }
    }

    #[Test]
    public function withoutHiddenFieldNothingIsWritten(): void
    {
        $GLOBALS['TCA'] = [];

        $this->expectExceptionObject(PresentationException::notAvailable());

        $this->switcher()->switch(20, 10, $this->editor());
    }

    /**
     * @return iterable<string, array{array<int, int>|null, list<string>}>
     */
    public static function failedSwitches(): iterable
    {
        yield 'DataHandler reported an error' => [null, ['[1.1]: refused']];
        yield 'the new element stayed hidden' => [[20 => 1], []];
        yield 'the original stayed visible' => [[10 => 0], []];
    }

    /**
     * @param array<int, int>|null $stored
     * @param list<string>         $errors
     */
    #[Test]
    #[DataProvider('failedSwitches')]
    public function aSwitchThatDidNotComeThroughIsReported(?array $stored, array $errors): void
    {
        $this->queueDataHandler($stored, $errors);

        try {
            $this->switcher()->switch(20, 10, $this->editor());
            self::fail('The switch must fail');
        } catch (PresentationException $e) {
            self::assertSame('presentation.error.switchFailed', $e->getLabelKey());
            self::assertSame(500, $e->getHttpStatus());
        }

        self::assertSame(['new' => 20, 'original' => 10, 'errors' => $errors], $this->logger->records[0]['context'] ?? null);
    }

    #[Test]
    public function aRecordGoneAfterTheRunIsAFailure(): void
    {
        $dataHandler = $this->createStub(DataHandler::class);
        $dataHandler->method('process_datamap')->willReturnCallback(function (): void {
            unset($this->records['tt_content:10:0']);
        });
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);

        $this->expectExceptionObject(PresentationException::switchFailed());

        $this->switcher()->switch(20, 10, $this->editor());
    }
}
