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
    public function fetchSingleRecordRejectsDisallowedTable(): void
    {
        $connectionPool = $this->createConnectionPoolMock([]);

        $service = new ContextAssemblyService($connectionPool);
        $result  = $service->assembleContext('pages', 1, 'title', 'element');

        self::assertSame('', $result);
    }

    #[Test]
    public function missingBackendUserReturnsEmptyForElement(): void
    {
        // Remove BE_USER to simulate missing authentication
        unset($GLOBALS['BE_USER']);

        $connectionPool = $this->createConnectionPoolMock([
            ['uid' => 123, 'pid' => 5, 'header' => 'Secret', 'bodytext' => 'Confidential', 'subheader' => ''],
        ]);

        $service = new ContextAssemblyService($connectionPool);
        $result  = $service->assembleContext('tt_content', 123, 'bodytext', 'element');

        // Page access check should deny access when no BE_USER is set
        self::assertSame('', $result);
    }

    #[Test]
    public function missingBackendUserReturnsEmptyForPage(): void
    {
        // Remove BE_USER to simulate missing authentication
        unset($GLOBALS['BE_USER']);

        $connectionPool = $this->createConnectionPoolMock([
            ['uid' => 123, 'pid' => 5, 'header' => 'Secret', 'bodytext' => 'Confidential', 'subheader' => ''],
        ]);

        $service = new ContextAssemblyService($connectionPool);
        $result  = $service->getContextSummary('tt_content', 123, 'bodytext', 'page');

        self::assertSame(0, $result['wordCount']);
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

    #[Test]
    public function getContextSummaryReturnsTextScopeLabel(): void
    {
        // Kills ArrayItemRemoval #93: removing 'text' key from scopeLabels
        $connectionPool = $this->createConnectionPoolMock([
            ['uid' => 123, 'pid' => 5, 'header' => 'Test', 'bodytext' => '<p>One two three</p>', 'subheader' => ''],
        ]);

        $service = new ContextAssemblyService($connectionPool);
        $result  = $service->getContextSummary('tt_content', 123, 'bodytext', 'text');

        self::assertStringContainsString('current text field', $result['summary']);
    }

    #[Test]
    public function getContextSummaryReturnsAncestors1ScopeLabelPlural(): void
    {
        // Kills Concat/ConcatOperandRemoval #94-103 on ancestors_1 label
        // The mock returns same rows for all queries, so we get duplicates for ancestor page
        $connectionPool = $this->createConnectionPoolMock([
            ['uid' => 1, 'pid' => 5, 'header' => 'A', 'bodytext' => '<p>Word1</p>', 'subheader' => ''],
            ['uid' => 2, 'pid' => 5, 'header' => 'B', 'bodytext' => '<p>Word2</p>', 'subheader' => ''],
            ['uid' => 3, 'pid' => 5, 'header' => 'C', 'bodytext' => '<p>Word3</p>', 'subheader' => ''],
        ]);

        $service = new ContextAssemblyService($connectionPool);
        $result  = $service->getContextSummary('tt_content', 1, 'bodytext', 'ancestors_1');

        // Must contain " elements (+1 ancestor level)" — plural 's' and correct suffix
        self::assertStringContainsString(' elements', $result['summary']);
        self::assertStringContainsString('(+1 ancestor level)', $result['summary']);
        // Full format check
        self::assertMatchesRegularExpression('/\d+ elements \(\+1 ancestor level\), ~\d+ words/', $result['summary']);
    }

    #[Test]
    public function getContextSummaryReturnsAncestors1ScopeLabelSingular(): void
    {
        // Kills NotIdentical #98, Ternary #99 on ancestors_1: checks singular 'element' (count=1)
        $connectionPool = $this->createConnectionPoolMock([
            ['uid' => 1, 'pid' => 5, 'header' => 'Only', 'bodytext' => '<p>Single element</p>', 'subheader' => ''],
        ]);

        $service = new ContextAssemblyService($connectionPool);
        $result  = $service->getContextSummary('tt_content', 1, 'bodytext', 'ancestors_1');

        // Must contain "1 element (+1 ancestor level)" — no 's' for singular
        self::assertStringContainsString('1 element', $result['summary']);
        self::assertStringNotContainsString('1 elements', $result['summary']);
        self::assertStringContainsString('(+1 ancestor level)', $result['summary']);
    }

    #[Test]
    public function getContextSummaryReturnsAncestors2ScopeLabelPlural(): void
    {
        // Kills Concat/ConcatOperandRemoval #104-113 on ancestors_2 label
        $connectionPool = $this->createConnectionPoolMock([
            ['uid' => 1, 'pid' => 5, 'header' => 'A', 'bodytext' => '<p>Word1</p>', 'subheader' => ''],
            ['uid' => 2, 'pid' => 5, 'header' => 'B', 'bodytext' => '<p>Word2</p>', 'subheader' => ''],
        ]);

        $service = new ContextAssemblyService($connectionPool);
        $result  = $service->getContextSummary('tt_content', 1, 'bodytext', 'ancestors_2');

        // Must contain " elements (+2 ancestor levels)" — plural 's' and correct suffix
        self::assertStringContainsString(' elements', $result['summary']);
        self::assertStringContainsString('(+2 ancestor levels)', $result['summary']);
        self::assertMatchesRegularExpression('/\d+ elements \(\+2 ancestor levels\), ~\d+ words/', $result['summary']);
    }

    #[Test]
    public function getContextSummaryReturnsAncestors2ScopeLabelSingular(): void
    {
        // Kills NotIdentical #108, Ternary #109 on ancestors_2: checks singular with count=1
        $connectionPool = $this->createConnectionPoolMock([
            ['uid' => 1, 'pid' => 5, 'header' => 'Only', 'bodytext' => '<p>Content</p>', 'subheader' => ''],
        ]);

        $service = new ContextAssemblyService($connectionPool);
        $result  = $service->getContextSummary('tt_content', 1, 'bodytext', 'ancestors_2');

        // Must contain "1 element (+2 ancestor levels)" — no 's' for singular
        self::assertStringContainsString('1 element', $result['summary']);
        self::assertStringNotContainsString('1 elements', $result['summary']);
        self::assertStringContainsString('(+2 ancestor levels)', $result['summary']);
    }

    #[Test]
    public function assembleContextIncludesRelationPrefixForReferencePages(): void
    {
        // Kills Concat #114, ConcatOperandRemoval #115 on reference page relation
        $connectionPool = $this->createConnectionPoolMock([
            ['uid' => 123, 'pid' => 5, 'header' => 'Main', 'bodytext' => '<p>Content</p>', 'subheader' => ''],
        ]);

        $service = new ContextAssemblyService($connectionPool);
        $result  = $service->assembleContext(
            'tt_content',
            123,
            'bodytext',
            'element',
            [['pid' => 10, 'relation' => 'style guide']],
        );

        // The relation must appear as " — Relation: style guide" in the output
        self::assertStringContainsString(' — Relation: style guide', $result);
        // The reference page header should also appear
        self::assertStringContainsString('Reference page', $result);
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
