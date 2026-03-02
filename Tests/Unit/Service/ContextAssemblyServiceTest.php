<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use Netresearch\T3Cowriter\Service\ContextAssemblyService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[CoversClass(ContextAssemblyService::class)]
final class ContextAssemblyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set up a mock admin backend user for page access checks
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(true);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function getContextSummaryReturnsWordCountForElement(): void
    {
        $connectionPool = $this->createConnectionPoolMock([
            ['uid' => 123, 'pid' => 5, 'header' => 'Test Header', 'bodytext' => '<p>One two three four five</p>', 'subheader' => ''],
        ]);

        $service = new ContextAssemblyService($connectionPool);
        $result  = $service->getContextSummary('tt_content', 123, 'bodytext', 'element');

        self::assertArrayHasKey('summary', $result);
        self::assertArrayHasKey('wordCount', $result);
        self::assertGreaterThan(0, $result['wordCount']);
        self::assertStringContainsString('element', $result['summary']);
    }

    #[Test]
    public function getContextSummaryReturnsWordCountForPage(): void
    {
        $connectionPool = $this->createConnectionPoolMock([
            ['uid' => 123, 'pid' => 5, 'header' => 'Element 1', 'bodytext' => '<p>First element</p>', 'subheader' => ''],
            ['uid' => 124, 'pid' => 5, 'header' => 'Element 2', 'bodytext' => '<p>Second element</p>', 'subheader' => ''],
        ]);

        $service = new ContextAssemblyService($connectionPool);
        $result  = $service->getContextSummary('tt_content', 123, 'bodytext', 'page');

        self::assertArrayHasKey('wordCount', $result);
        self::assertStringContainsString('2 elements', $result['summary']);
    }

    #[Test]
    public function assembleContextReturnsFormattedTextForElement(): void
    {
        $connectionPool = $this->createConnectionPoolMock([
            ['uid' => 123, 'pid' => 5, 'header' => 'Test Header', 'bodytext' => '<p>Body text here</p>', 'subheader' => 'Sub'],
        ]);

        $service = new ContextAssemblyService($connectionPool);
        $result  = $service->assembleContext('tt_content', 123, 'bodytext', 'element');

        self::assertStringContainsString('Test Header', $result);
        self::assertStringContainsString('Body text here', $result);
        self::assertStringContainsString('Sub', $result);
    }

    #[Test]
    public function assembleContextIncludesReferencePagesWhenProvided(): void
    {
        $connectionPool = $this->createConnectionPoolMock([
            ['uid' => 123, 'pid' => 5, 'header' => 'Main', 'bodytext' => '<p>Main content</p>', 'subheader' => ''],
        ]);

        $service = new ContextAssemblyService($connectionPool);
        $result  = $service->assembleContext(
            'tt_content',
            123,
            'bodytext',
            'element',
            [['pid' => 10, 'relation' => 'style guide']],
        );

        self::assertStringContainsString('Main', $result);
        self::assertStringContainsString('Reference page', $result);
        self::assertStringContainsString('style guide', $result);
    }

    #[Test]
    public function assembleContextReturnsEmptyForDefaultScope(): void
    {
        $connectionPool = $this->createConnectionPoolMock([]);

        $service = new ContextAssemblyService($connectionPool);
        $result  = $service->assembleContext('tt_content', 123, 'bodytext', 'selection');

        self::assertSame('', $result);
    }

    #[Test]
    public function countWordsStripsHtmlTags(): void
    {
        $service = new ContextAssemblyService($this->createConnectionPoolMock([]));

        $method = new ReflectionMethod($service, 'countWords');
        $count  = $method->invoke($service, '<p>One <strong>two</strong> three &amp; four</p>');

        self::assertSame(5, $count);
    }

    #[Test]
    public function formatSingleRecordSkipsEmptyFields(): void
    {
        $service = new ContextAssemblyService($this->createConnectionPoolMock([]));

        $method = new ReflectionMethod($service, 'formatSingleRecord');
        $result = $method->invoke($service, ['header' => 'Title', 'subheader' => '', 'bodytext' => 'Content']);

        self::assertStringContainsString('Title', $result);
        self::assertStringContainsString('Content', $result);
        self::assertStringNotContainsString('Subheader:', $result);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function createConnectionPoolMock(array $rows): ConnectionPool
    {
        $expressionBuilder = $this->createStub(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');
        $expressionBuilder->method('in')->willReturn('1=1');

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('select')->willReturn($queryBuilder);
        $queryBuilder->method('from')->willReturn($queryBuilder);
        $queryBuilder->method('where')->willReturn($queryBuilder);
        $queryBuilder->method('andWhere')->willReturn($queryBuilder);
        $queryBuilder->method('orderBy')->willReturn($queryBuilder);
        $queryBuilder->method('setMaxResults')->willReturn($queryBuilder);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturnCallback(
            fn ($value) => "'" . $value . "'",
        );
        $resultStub = $this->createStub(Result::class);
        $index      = 0;
        $resultStub->method('fetchAssociative')->willReturnCallback(
            function () use ($rows, &$index): array|false {
                return $rows[$index++] ?? false;
            },
        );
        $resultStub->method('fetchAllAssociative')->willReturn($rows);
        $queryBuilder->method('executeQuery')->willReturn($resultStub);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        return $connectionPool;
    }
}
