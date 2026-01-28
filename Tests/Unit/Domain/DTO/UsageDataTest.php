<?php

/*
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Domain\DTO;

use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\T3Cowriter\Domain\DTO\UsageData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UsageData::class)]
final class UsageDataTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $usageData = new UsageData(
            promptTokens: 100,
            completionTokens: 200,
            totalTokens: 300,
            estimatedCost: 0.05,
        );

        $this->assertSame(100, $usageData->promptTokens);
        $this->assertSame(200, $usageData->completionTokens);
        $this->assertSame(300, $usageData->totalTokens);
        $this->assertSame(0.05, $usageData->estimatedCost);
    }

    #[Test]
    public function constructorAcceptsNullEstimatedCost(): void
    {
        $usageData = new UsageData(
            promptTokens: 50,
            completionTokens: 75,
            totalTokens: 125,
            estimatedCost: null,
        );

        $this->assertNull($usageData->estimatedCost);
    }

    #[Test]
    public function fromUsageStatisticsCreatesUsageData(): void
    {
        $statistics = new UsageStatistics(
            promptTokens: 100,
            completionTokens: 200,
            totalTokens: 300,
            estimatedCost: 0.025,
        );

        $usageData = UsageData::fromUsageStatistics($statistics);

        $this->assertSame(100, $usageData->promptTokens);
        $this->assertSame(200, $usageData->completionTokens);
        $this->assertSame(300, $usageData->totalTokens);
        $this->assertSame(0.025, $usageData->estimatedCost);
    }

    #[Test]
    public function fromUsageStatisticsHandlesNullCost(): void
    {
        $statistics = new UsageStatistics(
            promptTokens: 10,
            completionTokens: 20,
            totalTokens: 30,
            estimatedCost: null,
        );

        $usageData = UsageData::fromUsageStatistics($statistics);

        $this->assertNull($usageData->estimatedCost);
    }

    #[Test]
    public function fromUsageStatisticsHandlesZeroTokens(): void
    {
        $statistics = new UsageStatistics(
            promptTokens: 0,
            completionTokens: 0,
            totalTokens: 0,
            estimatedCost: 0.0,
        );

        $usageData = UsageData::fromUsageStatistics($statistics);

        $this->assertSame(0, $usageData->promptTokens);
        $this->assertSame(0, $usageData->completionTokens);
        $this->assertSame(0, $usageData->totalTokens);
        $this->assertSame(0.0, $usageData->estimatedCost);
    }

    #[Test]
    public function jsonSerializeReturnsCorrectStructure(): void
    {
        $usageData = new UsageData(
            promptTokens: 100,
            completionTokens: 200,
            totalTokens: 300,
            estimatedCost: 0.05,
        );

        $json = $usageData->jsonSerialize();

        $this->assertSame([
            'promptTokens'     => 100,
            'completionTokens' => 200,
            'totalTokens'      => 300,
            'estimatedCost'    => 0.05,
        ], $json);
    }

    #[Test]
    public function jsonSerializeIncludesNullCost(): void
    {
        $usageData = new UsageData(
            promptTokens: 10,
            completionTokens: 20,
            totalTokens: 30,
            estimatedCost: null,
        );

        $json = $usageData->jsonSerialize();

        $this->assertArrayHasKey('estimatedCost', $json);
        $this->assertNull($json['estimatedCost']);
    }

    #[Test]
    #[DataProvider('largeTokenValuesProvider')]
    public function fromUsageStatisticsHandlesLargeValues(int $promptTokens, int $completionTokens): void
    {
        $totalTokens = $promptTokens + $completionTokens;
        $statistics  = new UsageStatistics(
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            estimatedCost: null,
        );

        $usageData = UsageData::fromUsageStatistics($statistics);

        $this->assertSame($promptTokens, $usageData->promptTokens);
        $this->assertSame($completionTokens, $usageData->completionTokens);
        $this->assertSame($totalTokens, $usageData->totalTokens);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function largeTokenValuesProvider(): iterable
    {
        yield 'typical usage' => [1000, 2000];
        yield 'large context' => [128000, 4096];
        yield 'max completion' => [100, 100000];
    }
}
