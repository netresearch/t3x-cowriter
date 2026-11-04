<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Overview;

use DateTimeInterface;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\ExtensionUsageTotals;
use Netresearch\NrLlm\Domain\ValueObject\OperationUsage;
use Netresearch\NrLlm\Domain\ValueObject\TelemetryCoverage;
use Netresearch\NrLlm\Service\Usage\ExtensionUsageReaderInterface;
use Netresearch\T3Cowriter\Service\Overview\UsageOperation;
use Netresearch\T3Cowriter\Service\Overview\UsageOverview;
use Netresearch\T3Cowriter\Service\Overview\UsageService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The figures come from nr-llm's reader, asked for this extension and, for an
 * editor, for the editor alone. The fake reader below filters its rows by the
 * arguments it receives, as nr-llm's reader filters its tables, so a row of
 * another extension or another user shows up only when the wrong arguments
 * are passed.
 */
#[CoversClass(UsageService::class)]
#[CoversClass(UsageOverview::class)]
#[CoversClass(UsageOperation::class)]
final class UsageServiceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const DAY = 86400;

    private string $timezone = 'UTC';

    #[Test]
    public function anEditorSeesOnlyTheirOwnCowriterFigures(): void
    {
        $usage = (new UsageService($this->reader()))->forViewer(7, false, 30, self::NOW);

        self::assertTrue($usage->ownOnly);
        self::assertSame(2, $usage->requests);
        self::assertSame(200, $usage->tokens);
        self::assertSame(0.2, $usage->estimatedCost);
        self::assertSame(['chat' => 2], $this->callsPerOperation($usage));
        self::assertSame(1, $usage->failedCount());
    }

    #[Test]
    public function anAdministratorSeesTheInstallationsCowriterFigures(): void
    {
        $usage = (new UsageService($this->reader()))->forViewer(1, true, 30, self::NOW);

        self::assertFalse($usage->ownOnly);
        self::assertSame(5, $usage->requests);
        self::assertSame(500, $usage->tokens);
        self::assertSame(['chat' => 2, 'translate' => 3], $this->callsPerOperation($usage));
        self::assertSame(5, $usage->callCount());
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->timezone = date_default_timezone_get();
    }

    /**
     * Seven days are today and the six before, from local midnight: half an
     * hour after midnight in Berlin it is still the previous day in UTC, so a
     * period computed in UTC would start a day early and an hour late.
     */
    #[Test]
    public function thePeriodEndsNowAndStartsAtLocalMidnightOfItsFirstDay(): void
    {
        date_default_timezone_set('Europe/Berlin');
        $now    = strtotime('2027-01-15 00:30');
        $reader = $this->reader();
        $usage  = (new UsageService($reader))->forViewer(7, false, 7, $now);

        self::assertSame('2027-01-09 00:00:00', date('Y-m-d H:i:s', $usage->from));
        self::assertSame($now, $usage->to);
        self::assertSame([$usage->from, $now], $reader->lastPeriod);
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function requestedPeriods(): iterable
    {
        yield 'seven days' => ['7', 7];
        yield 'ninety days' => [90, 90];
        yield 'nothing' => [null, 30];
        yield 'another number' => ['365', 30];
        yield 'text' => ['all', 30];
        yield 'fraction' => ['7.9', 30];
        yield 'exponent' => ['7e0', 30];
    }

    #[Test]
    #[DataProvider('requestedPeriods')]
    public function onlyTheOfferedPeriodsCanBeChosen(mixed $requested, int $expected): void
    {
        self::assertSame($expected, UsageService::period($requested));
    }

    #[Test]
    public function countsCoverAPeriodInsideTheRetention(): void
    {
        $reader         = $this->reader();
        $reader->oldest = self::NOW - 60 * self::DAY;

        self::assertNull((new UsageService($reader))->forViewer(1, true, 7, self::NOW)->completeFrom);
    }

    #[Test]
    public function countsAreCompleteFromTheRetentionStartWhenNothingOlderIsStored(): void
    {
        $reader         = $this->reader();
        $reader->oldest = self::NOW - 10 * self::DAY;

        $usage = (new UsageService($reader))->forViewer(1, true, 90, self::NOW);

        self::assertSame(self::NOW - 30 * self::DAY, $usage->completeFrom);
    }

    #[Test]
    public function countsAreCompleteFromTheOldestStoredCallWhenThePurgeHasNotCaughtUp(): void
    {
        $reader         = $this->reader();
        $reader->oldest = self::NOW - 45 * self::DAY;

        $usage = (new UsageService($reader))->forViewer(1, true, 90, self::NOW);

        self::assertSame(self::NOW - 45 * self::DAY, $usage->completeFrom);
    }

    #[Test]
    public function countsAreCompleteWhenTheOldestStoredCallPrecedesThePeriod(): void
    {
        $reader         = $this->reader();
        $reader->oldest = self::NOW - 100 * self::DAY;

        self::assertNull((new UsageService($reader))->forViewer(1, true, 90, self::NOW)->completeFrom);
    }

    #[Test]
    public function switchedOffTelemetryIsReported(): void
    {
        $reader          = $this->reader();
        $reader->enabled = false;

        self::assertFalse((new UsageService($reader))->forViewer(1, true, 30, self::NOW)->telemetryEnabled);
    }

    #[Test]
    public function operationsAreLabelledByNameTaskOrVerbatim(): void
    {
        $reader       = $this->reader();
        $reader->rows = [
            ['t3_cowriter', 1, 'altText', 1, 0],
            ['t3_cowriter', 1, 'improve', 1, 0],
            ['t3_cowriter', 1, 'somethingNew', 1, 0],
            ['t3_cowriter', 1, '', 1, 0],
        ];

        $task = new Task();
        $task->setName('Improve text');

        $tasks = $this->createStub(TaskRepository::class);
        $tasks->method('findOneByIdentifier')->willReturnCallback(static fn (string $identifier): ?Task => $identifier === 'improve' ? $task : null);

        $labels = array_map(
            static fn (UsageOperation $operation): array => [$operation->labelKey, $operation->labelArgument],
            (new UsageService($reader, $tasks))->forViewer(1, true, 30, self::NOW)->operations,
        );

        self::assertSame([['altText', ''], ['task', 'Improve text'], [null, 'somethingNew'], ['unnamed', '']], $labels);
    }

    /**
     * Every operation name the cowriter passes to nr-llm has a label. A new
     * call site with a new name fails here until the name is added.
     */
    #[Test]
    public function everyOperationTheCowriterNamesHasALabel(): void
    {
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../../../Classes'));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            preg_match_all("/(?:callMetadata|CallerSource::metadata)\\(\\s*'([A-Za-z]+)'/", $source, $metadata);
            preg_match_all("/withCallerSource\\(\\s*CallerSource::EXTENSION,\\s*'([A-Za-z]+)'/", $source, $options);
            preg_match_all("/const OPERATION\\s*=\\s*'([A-Za-z]+)'/", $source, $constants);
            preg_match_all('/function taskOperation\\(.*?\\n    \\}/s', $source, $taskOperation);
            $found = [...$found, ...$metadata[1], ...$options[1], ...$constants[1]];
            foreach ($taskOperation[0] as $body) {
                preg_match_all("/return '([A-Za-z]+)'|\\? '([A-Za-z]+)'|: '([A-Za-z]+)'/", $body, $literals);
                $found = [...$found, ...array_filter([...$literals[1], ...$literals[2], ...$literals[3]])];
            }
        }

        $found = array_values(array_unique($found));
        sort($found);

        self::assertGreaterThanOrEqual(14, count($found), 'the scan found the call sites: ' . implode(', ', $found));
        self::assertSame([], array_values(array_diff($found, UsageService::OPERATIONS)));
    }

    /**
     * @return array<string, int>
     */
    private function callsPerOperation(UsageOverview $usage): array
    {
        $calls = [];
        foreach ($usage->operations as $operation) {
            $calls[$operation->operation] = $operation->calls;
        }

        ksort($calls);

        return $calls;
    }

    private function reader(): FakeUsageReader
    {
        return new FakeUsageReader();
    }
}

/**
 * Rows of three sources: the cowriter for user 7, the cowriter for user 9 and
 * another extension for user 7. Filters them by the arguments it is given.
 */
final class FakeUsageReader implements ExtensionUsageReaderInterface
{
    /** @var list<array{string, int, string, int, int}> extension, user, operation, calls, failed */
    public array $rows = [
        ['t3_cowriter', 7, 'chat', 2, 1],
        ['t3_cowriter', 9, 'translate', 3, 0],
        ['nr_mcp_agent', 7, 'chat', 50, 5],
    ];

    public bool $enabled = true;

    public ?int $oldest = null;

    /** @var array{int, int}|null */
    public ?array $lastPeriod = null;

    public function totals(string $sourceExtension, DateTimeInterface $from, DateTimeInterface $to, ?int $beUserUid = null): ExtensionUsageTotals
    {
        $this->lastPeriod = [$from->getTimestamp(), $to->getTimestamp()];
        $requests         = 0;
        foreach ($this->matching($sourceExtension, $beUserUid) as $row) {
            $requests += $row[3];
        }

        return new ExtensionUsageTotals($requests / 10, $requests, $requests * 100);
    }

    public function operations(string $sourceExtension, DateTimeInterface $from, DateTimeInterface $to, ?int $beUserUid = null): array
    {
        $operations = [];
        foreach ($this->matching($sourceExtension, $beUserUid) as $row) {
            $operations[] = new OperationUsage($row[2], $row[3], $row[4], 1_700_000_000);
        }

        return $operations;
    }

    public function coverage(): TelemetryCoverage
    {
        return new TelemetryCoverage($this->enabled, 30, $this->oldest);
    }

    /**
     * @return list<array{string, int, string, int, int}>
     */
    private function matching(string $sourceExtension, ?int $beUserUid): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => $row[0] === $sourceExtension && ($beUserUid === null || $row[1] === $beUserUid),
        ));
    }
}
