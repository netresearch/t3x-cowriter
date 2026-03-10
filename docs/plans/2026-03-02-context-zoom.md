# Context Zoom Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the cowriter dialog's two radio buttons with a context zoom range slider that progressively widens scope from selected text to ancestor pages, plus an optional reference page picker.

**Architecture:** Frontend extracts record context (table/uid/field) from the CKEditor DOM, passes it through the dialog and AJAX calls. Backend resolves context server-side using TYPO3's QueryBuilder and page tree APIs, respecting BE_USER permissions. A lightweight preview endpoint returns word counts before full execution.

**Tech Stack:** PHP 8.2+ (TYPO3 v13/v14), CKEditor 5 plugin (ES modules), Vitest (JS tests), PHPUnit (PHP tests)

---

### Task 1: ContextRequest DTO

**Files:**
- Create: `Classes/Domain/DTO/ContextRequest.php`
- Create: `Tests/Unit/Domain/DTO/ContextRequestTest.php`

**Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Domain\DTO;

use Netresearch\T3Cowriter\Domain\DTO\ContextRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

#[CoversClass(ContextRequest::class)]
final class ContextRequestTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $dto = new ContextRequest(
            table: 'tt_content',
            uid: 42,
            field: 'bodytext',
            scope: 'page',
        );

        self::assertSame('tt_content', $dto->table);
        self::assertSame(42, $dto->uid);
        self::assertSame('bodytext', $dto->field);
        self::assertSame('page', $dto->scope);
    }

    #[Test]
    public function fromQueryParamsCreatesFromGetRequest(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'table' => 'tt_content',
            'uid'   => '123',
            'field' => 'bodytext',
            'scope' => 'element',
        ]);

        $dto = ContextRequest::fromQueryParams($request);

        self::assertSame('tt_content', $dto->table);
        self::assertSame(123, $dto->uid);
        self::assertSame('bodytext', $dto->field);
        self::assertSame('element', $dto->scope);
    }

    #[Test]
    public function fromQueryParamsHandlesEmptyParams(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $dto = ContextRequest::fromQueryParams($request);

        self::assertSame('', $dto->table);
        self::assertSame(0, $dto->uid);
        self::assertSame('', $dto->field);
        self::assertSame('', $dto->scope);
    }

    #[Test]
    public function fromQueryParamsHandlesNonNumericUid(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'table' => 'tt_content',
            'uid'   => 'not-a-number',
            'field' => 'bodytext',
            'scope' => 'page',
        ]);

        $dto = ContextRequest::fromQueryParams($request);
        self::assertSame(0, $dto->uid);
    }

    #[Test]
    public function isValidReturnsTrueForValidRequest(): void
    {
        $dto = new ContextRequest('tt_content', 1, 'bodytext', 'page');
        self::assertTrue($dto->isValid());
    }

    #[Test]
    public function isValidAcceptsAllScopes(): void
    {
        foreach (['selection', 'text', 'element', 'page', 'ancestors_1', 'ancestors_2'] as $scope) {
            $dto = new ContextRequest('tt_content', 1, 'bodytext', $scope);
            self::assertTrue($dto->isValid(), "Scope '$scope' should be valid");
        }
    }

    /**
     * @return array<string, array{ContextRequest}>
     */
    public static function invalidRequestProvider(): array
    {
        return [
            'empty table'       => [new ContextRequest('', 1, 'bodytext', 'page')],
            'disallowed table'  => [new ContextRequest('be_users', 1, 'bodytext', 'page')],
            'zero uid'          => [new ContextRequest('tt_content', 0, 'bodytext', 'page')],
            'negative uid'      => [new ContextRequest('tt_content', -1, 'bodytext', 'page')],
            'empty field'       => [new ContextRequest('tt_content', 1, '', 'page')],
            'empty scope'       => [new ContextRequest('tt_content', 1, 'bodytext', '')],
            'invalid scope'     => [new ContextRequest('tt_content', 1, 'bodytext', 'invalid')],
        ];
    }

    #[Test]
    #[DataProvider('invalidRequestProvider')]
    public function isValidReturnsFalseForInvalidRequest(ContextRequest $dto): void
    {
        self::assertFalse($dto->isValid());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && composer ci:test:php:unit -- --filter ContextRequestTest`
Expected: FAIL — class `ContextRequest` not found

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Request DTO for context preview AJAX endpoint.
 *
 * @internal
 */
final readonly class ContextRequest
{
    private const ALLOWED_TABLES = ['tt_content', 'pages'];

    private const ALLOWED_SCOPES = [
        'selection',
        'text',
        'element',
        'page',
        'ancestors_1',
        'ancestors_2',
    ];

    public function __construct(
        public string $table,
        public int $uid,
        public string $field,
        public string $scope,
    ) {}

    public static function fromQueryParams(ServerRequestInterface $request): self
    {
        $params = $request->getQueryParams();

        return new self(
            table: is_string($params['table'] ?? null) ? $params['table'] : '',
            uid: is_numeric($params['uid'] ?? null) ? (int) $params['uid'] : 0,
            field: is_string($params['field'] ?? null) ? $params['field'] : '',
            scope: is_string($params['scope'] ?? null) ? $params['scope'] : '',
        );
    }

    public function isValid(): bool
    {
        if (!in_array($this->table, self::ALLOWED_TABLES, true)) {
            return false;
        }

        if ($this->uid <= 0) {
            return false;
        }

        if ($this->field === '') {
            return false;
        }

        return in_array($this->scope, self::ALLOWED_SCOPES, true);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && composer ci:test:php:unit -- --filter ContextRequestTest`
Expected: All tests PASS

**Step 5: Commit**

```bash
git add Classes/Domain/DTO/ContextRequest.php Tests/Unit/Domain/DTO/ContextRequestTest.php
git commit -S --signoff -m "feat(context-zoom): add ContextRequest DTO with validation"
```

---

### Task 2: Extend ExecuteTaskRequest with context zoom fields

**Files:**
- Modify: `Classes/Domain/DTO/ExecuteTaskRequest.php`
- Modify: `Tests/Unit/Domain/DTO/ExecuteTaskRequestTest.php`

**Step 1: Write the failing tests**

Add to `ExecuteTaskRequestTest.php` (after the editorCapabilities section):

```php
// =========================================================================
// contextScope
// =========================================================================

#[Test]
public function fromRequestParsesContextScope(): void
{
    $request = $this->createJsonRequest([
        'taskUid'      => 1,
        'context'      => 'text',
        'contextType'  => 'selection',
        'contextScope' => 'page',
    ]);

    $dto = ExecuteTaskRequest::fromRequest($request);
    self::assertSame('page', $dto->contextScope);
}

#[Test]
public function fromRequestDefaultsContextScopeToEmpty(): void
{
    $request = $this->createJsonRequest([
        'taskUid'     => 1,
        'context'     => 'text',
        'contextType' => 'selection',
    ]);

    $dto = ExecuteTaskRequest::fromRequest($request);
    self::assertSame('', $dto->contextScope);
}

#[Test]
public function isValidAcceptsAllContextScopes(): void
{
    foreach (['', 'selection', 'text', 'element', 'page', 'ancestors_1', 'ancestors_2'] as $scope) {
        $dto = new ExecuteTaskRequest(1, 'text', 'selection', '', null, '', $scope);
        self::assertTrue($dto->isValid(), "contextScope '$scope' should be valid");
    }
}

#[Test]
public function isValidRejectsInvalidContextScope(): void
{
    $dto = new ExecuteTaskRequest(1, 'text', 'selection', '', null, '', 'invalid_scope');
    self::assertFalse($dto->isValid());
}

// =========================================================================
// recordContext
// =========================================================================

#[Test]
public function fromRequestParsesRecordContext(): void
{
    $request = $this->createJsonRequest([
        'taskUid'       => 1,
        'context'       => 'text',
        'contextType'   => 'selection',
        'recordContext' => ['table' => 'tt_content', 'uid' => 42, 'field' => 'bodytext'],
    ]);

    $dto = ExecuteTaskRequest::fromRequest($request);
    self::assertSame(['table' => 'tt_content', 'uid' => 42, 'field' => 'bodytext'], $dto->recordContext);
}

#[Test]
public function fromRequestDefaultsRecordContextToNull(): void
{
    $request = $this->createJsonRequest([
        'taskUid'     => 1,
        'context'     => 'text',
        'contextType' => 'selection',
    ]);

    $dto = ExecuteTaskRequest::fromRequest($request);
    self::assertNull($dto->recordContext);
}

#[Test]
public function isValidAcceptsValidRecordContext(): void
{
    $dto = new ExecuteTaskRequest(
        1, 'text', 'selection', '', null, '', 'page',
        ['table' => 'tt_content', 'uid' => 42, 'field' => 'bodytext'],
    );
    self::assertTrue($dto->isValid());
}

#[Test]
public function isValidAcceptsNullRecordContext(): void
{
    $dto = new ExecuteTaskRequest(1, 'text', 'selection', '', null, '', '', null);
    self::assertTrue($dto->isValid());
}

#[Test]
public function isValidRejectsRecordContextWithDisallowedTable(): void
{
    $dto = new ExecuteTaskRequest(
        1, 'text', 'selection', '', null, '', 'page',
        ['table' => 'be_users', 'uid' => 1, 'field' => 'username'],
    );
    self::assertFalse($dto->isValid());
}

#[Test]
public function isValidRequiresRecordContextWhenScopeIsElement(): void
{
    $dto = new ExecuteTaskRequest(1, 'text', 'selection', '', null, '', 'element', null);
    self::assertFalse($dto->isValid());
}

#[Test]
public function isValidRequiresRecordContextWhenScopeIsPage(): void
{
    $dto = new ExecuteTaskRequest(1, 'text', 'selection', '', null, '', 'page', null);
    self::assertFalse($dto->isValid());
}

#[Test]
public function isValidRequiresRecordContextWhenScopeIsAncestors(): void
{
    $dto = new ExecuteTaskRequest(1, 'text', 'selection', '', null, '', 'ancestors_1', null);
    self::assertFalse($dto->isValid());
}

// =========================================================================
// referencePages
// =========================================================================

#[Test]
public function fromRequestParsesReferencePages(): void
{
    $request = $this->createJsonRequest([
        'taskUid'        => 1,
        'context'        => 'text',
        'contextType'    => 'selection',
        'referencePages' => [
            ['pid' => 5, 'relation' => 'reference material'],
            ['pid' => 12, 'relation' => 'style guide'],
        ],
    ]);

    $dto = ExecuteTaskRequest::fromRequest($request);
    self::assertCount(2, $dto->referencePages);
    self::assertSame(5, $dto->referencePages[0]['pid']);
    self::assertSame('style guide', $dto->referencePages[1]['relation']);
}

#[Test]
public function fromRequestDefaultsReferencePagesToEmpty(): void
{
    $request = $this->createJsonRequest([
        'taskUid'     => 1,
        'context'     => 'text',
        'contextType' => 'selection',
    ]);

    $dto = ExecuteTaskRequest::fromRequest($request);
    self::assertSame([], $dto->referencePages);
}

#[Test]
public function isValidRejectsTooManyReferencePages(): void
{
    $pages = array_fill(0, 11, ['pid' => 1, 'relation' => 'test']);
    $dto = new ExecuteTaskRequest(1, 'text', 'selection', '', null, '', '', null, $pages);
    self::assertFalse($dto->isValid());
}

#[Test]
public function isValidAcceptsTenReferencePages(): void
{
    $pages = array_fill(0, 10, ['pid' => 1, 'relation' => 'test']);
    $dto = new ExecuteTaskRequest(1, 'text', 'selection', '', null, '', '', null, $pages);
    self::assertTrue($dto->isValid());
}
```

**Step 2: Run test to verify it fails**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && composer ci:test:php:unit -- --filter ExecuteTaskRequestTest`
Expected: FAIL — missing properties

**Step 3: Update the implementation**

Modify `Classes/Domain/DTO/ExecuteTaskRequest.php`:

Add to class constants:
```php
private const MAX_REFERENCE_PAGES = 10;

private const ALLOWED_CONTEXT_SCOPES = [
    '',
    'selection',
    'text',
    'element',
    'page',
    'ancestors_1',
    'ancestors_2',
];

private const SCOPES_REQUIRING_RECORD_CONTEXT = [
    'element',
    'page',
    'ancestors_1',
    'ancestors_2',
];

private const ALLOWED_RECORD_TABLES = ['tt_content', 'pages'];
```

Add to constructor:
```php
public function __construct(
    public int $taskUid,
    public string $context,
    public string $contextType,
    public string $instruction,
    public ?string $configuration,
    public string $editorCapabilities = '',
    public string $contextScope = '',
    public ?array $recordContext = null,
    public array $referencePages = [],
) {}
```

Add to `fromRequest()`:
```php
contextScope: self::extractString($data, 'contextScope'),
recordContext: self::extractRecordContext($data),
referencePages: self::extractReferencePages($data),
```

Add new extraction methods:
```php
/**
 * @param array<string, mixed> $data
 * @return array{table: string, uid: int, field: string}|null
 */
private static function extractRecordContext(array $data): ?array
{
    $rc = $data['recordContext'] ?? null;
    if (!is_array($rc)) {
        return null;
    }

    $table = is_string($rc['table'] ?? null) ? $rc['table'] : '';
    $uid   = is_numeric($rc['uid'] ?? null) ? (int) $rc['uid'] : 0;
    $field = is_string($rc['field'] ?? null) ? $rc['field'] : '';

    if ($table === '' || $uid <= 0 || $field === '') {
        return null;
    }

    return ['table' => $table, 'uid' => $uid, 'field' => $field];
}

/**
 * @param array<string, mixed> $data
 * @return list<array{pid: int, relation: string}>
 */
private static function extractReferencePages(array $data): array
{
    $pages = $data['referencePages'] ?? [];
    if (!is_array($pages)) {
        return [];
    }

    $result = [];
    foreach ($pages as $page) {
        if (!is_array($page)) {
            continue;
        }
        $pid      = is_numeric($page['pid'] ?? null) ? (int) $page['pid'] : 0;
        $relation = is_string($page['relation'] ?? null) ? $page['relation'] : '';
        if ($pid > 0) {
            $result[] = ['pid' => $pid, 'relation' => $relation];
        }
    }

    return $result;
}
```

Add to `isValid()` (before the final `return true`):
```php
if (!in_array($this->contextScope, self::ALLOWED_CONTEXT_SCOPES, true)) {
    return false;
}

if (in_array($this->contextScope, self::SCOPES_REQUIRING_RECORD_CONTEXT, true) && $this->recordContext === null) {
    return false;
}

if ($this->recordContext !== null) {
    if (!in_array($this->recordContext['table'] ?? '', self::ALLOWED_RECORD_TABLES, true)) {
        return false;
    }
}

if (count($this->referencePages) > self::MAX_REFERENCE_PAGES) {
    return false;
}
```

**Step 4: Run test to verify it passes**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && composer ci:test:php:unit -- --filter ExecuteTaskRequestTest`
Expected: All tests PASS

**Step 5: Commit**

```bash
git add Classes/Domain/DTO/ExecuteTaskRequest.php Tests/Unit/Domain/DTO/ExecuteTaskRequestTest.php
git commit -S --signoff -m "feat(context-zoom): extend ExecuteTaskRequest with contextScope, recordContext, referencePages"
```

---

### Task 3: ContextAssemblyService

**Files:**
- Create: `Classes/Service/ContextAssemblyService.php`
- Create: `Tests/Unit/Service/ContextAssemblyServiceTest.php`

**Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service;

use Netresearch\T3Cowriter\Service\ContextAssemblyService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[CoversClass(ContextAssemblyService::class)]
final class ContextAssemblyServiceTest extends TestCase
{
    #[Test]
    public function getContextSummaryReturnsWordCountForElement(): void
    {
        $connectionPool = $this->createConnectionPoolMock([
            ['uid' => 123, 'header' => 'Test Header', 'bodytext' => '<p>One two three four five</p>', 'subheader' => ''],
        ]);

        $service = new ContextAssemblyService($connectionPool);
        $result = $service->getContextSummary('tt_content', 123, 'bodytext', 'element');

        self::assertArrayHasKey('summary', $result);
        self::assertArrayHasKey('wordCount', $result);
        self::assertGreaterThan(0, $result['wordCount']);
        self::assertStringContainsString('element', $result['summary']);
    }

    #[Test]
    public function getContextSummaryReturnsWordCountForPage(): void
    {
        $connectionPool = $this->createConnectionPoolMock([
            ['uid' => 123, 'header' => 'Element 1', 'bodytext' => '<p>First element</p>', 'subheader' => '', 'pid' => 5],
            ['uid' => 124, 'header' => 'Element 2', 'bodytext' => '<p>Second element</p>', 'subheader' => '', 'pid' => 5],
        ]);

        $service = new ContextAssemblyService($connectionPool);
        $result = $service->getContextSummary('tt_content', 123, 'bodytext', 'page');

        self::assertArrayHasKey('wordCount', $result);
        self::assertStringContainsString('2 elements', $result['summary']);
    }

    #[Test]
    public function assembleContextReturnsFormattedTextForElement(): void
    {
        $connectionPool = $this->createConnectionPoolMock([
            ['uid' => 123, 'header' => 'Test Header', 'bodytext' => '<p>Body text here</p>', 'subheader' => 'Sub'],
        ]);

        $service = new ContextAssemblyService($connectionPool);
        $result = $service->assembleContext('tt_content', 123, 'bodytext', 'element');

        self::assertStringContainsString('Test Header', $result);
        self::assertStringContainsString('Body text here', $result);
        self::assertStringContainsString('Sub', $result);
    }

    #[Test]
    public function assembleContextIncludesReferencePagesWhenProvided(): void
    {
        $connectionPool = $this->createConnectionPoolMock([
            ['uid' => 123, 'header' => 'Main', 'bodytext' => '<p>Main content</p>', 'subheader' => '', 'pid' => 5],
        ]);

        $service = new ContextAssemblyService($connectionPool);
        $result = $service->assembleContext(
            'tt_content', 123, 'bodytext', 'element',
            [['pid' => 10, 'relation' => 'style guide']],
        );

        self::assertStringContainsString('Main', $result);
        // Reference page section header should be present
        self::assertStringContainsString('Reference page', $result);
        self::assertStringContainsString('style guide', $result);
    }

    #[Test]
    public function countWordsStripsHtmlTags(): void
    {
        $service = new ContextAssemblyService($this->createConnectionPoolMock([]));

        $method = new \ReflectionMethod($service, 'countWords');
        $count = $method->invoke($service, '<p>One <strong>two</strong> three &amp; four</p>');

        self::assertSame(4, $count);
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
        $queryBuilder->method('executeQuery')->willReturn(
            new class($rows) {
                private int $index = 0;
                public function __construct(private readonly array $rows) {}
                public function fetchAssociative(): array|false
                {
                    return $this->rows[$this->index++] ?? false;
                }
                public function fetchAllAssociative(): array
                {
                    return $this->rows;
                }
            },
        );

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        return $connectionPool;
    }
}
```

**Step 2: Run test to verify it fails**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && composer ci:test:php:unit -- --filter ContextAssemblyServiceTest`
Expected: FAIL — class not found

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Service for assembling context from TYPO3 content records.
 *
 * Fetches and formats content from tt_content records based on the requested
 * scope (element, page, ancestor pages) for use as LLM context.
 */
final readonly class ContextAssemblyService
{
    /**
     * Text fields to extract from tt_content records.
     */
    private const TEXT_FIELDS = ['header', 'subheader', 'bodytext'];

    /**
     * Maximum number of content elements to include per page.
     */
    private const MAX_ELEMENTS_PER_PAGE = 50;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Get a lightweight context summary (word count, element count).
     *
     * @return array{summary: string, wordCount: int}
     */
    public function getContextSummary(string $table, int $uid, string $field, string $scope): array
    {
        $records = $this->fetchRecords($table, $uid, $scope);
        $text = $this->formatRecords($records, $scope, $uid);
        $wordCount = $this->countWords($text);
        $elementCount = count($records);

        $scopeLabels = [
            'text'        => 'current text field',
            'element'     => '1 element',
            'page'        => $elementCount . ' element' . ($elementCount !== 1 ? 's' : ''),
            'ancestors_1' => $elementCount . ' element' . ($elementCount !== 1 ? 's' : '') . ' (+1 ancestor level)',
            'ancestors_2' => $elementCount . ' element' . ($elementCount !== 1 ? 's' : '') . ' (+2 ancestor levels)',
        ];

        $label = $scopeLabels[$scope] ?? $scope;

        return [
            'summary'   => "$label, ~$wordCount words",
            'wordCount' => $wordCount,
        ];
    }

    /**
     * Assemble the full context text for LLM consumption.
     *
     * @param list<array{pid: int, relation: string}> $referencePages
     */
    public function assembleContext(
        string $table,
        int $uid,
        string $field,
        string $scope,
        array $referencePages = [],
    ): string {
        $records = $this->fetchRecords($table, $uid, $scope);
        $text = $this->formatRecords($records, $scope, $uid);

        // Append reference page content
        foreach ($referencePages as $refPage) {
            $refRecords = $this->fetchContentForPage($refPage['pid']);
            if ($refRecords !== []) {
                $relation = $refPage['relation'] !== '' ? " \u2014 Relation: {$refPage['relation']}" : '';
                $text .= "\n\n=== Reference page (pid={$refPage['pid']}){$relation} ===\n";
                foreach ($refRecords as $record) {
                    $text .= $this->formatSingleRecord($record);
                }
            }
        }

        return $text;
    }

    /**
     * Fetch records based on scope.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchRecords(string $table, int $uid, string $scope): array
    {
        return match ($scope) {
            'element' => $this->fetchSingleRecord($table, $uid),
            'page' => $this->fetchPageContent($table, $uid),
            'ancestors_1' => $this->fetchWithAncestors($table, $uid, 1),
            'ancestors_2' => $this->fetchWithAncestors($table, $uid, 2),
            default => [],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchSingleRecord(string $table, int $uid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $result = $qb
            ->select('uid', 'pid', 'header', 'subheader', 'bodytext')
            ->from($table)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
            ->executeQuery();

        $row = $result->fetchAssociative();

        return $row !== false ? [$row] : [];
    }

    /**
     * Fetch all content on the same page as the given record.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchPageContent(string $table, int $uid): array
    {
        // First get the record's PID
        $record = $this->fetchSingleRecord($table, $uid);
        if ($record === []) {
            return [];
        }

        $pid = (int) ($record[0]['pid'] ?? 0);
        if ($pid <= 0) {
            return $record;
        }

        return $this->fetchContentForPage($pid);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchContentForPage(int $pid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $result = $qb
            ->select('uid', 'pid', 'header', 'subheader', 'bodytext')
            ->from('tt_content')
            ->where($qb->expr()->eq('pid', $qb->createNamedParameter($pid)))
            ->orderBy('sorting')
            ->setMaxResults(self::MAX_ELEMENTS_PER_PAGE)
            ->executeQuery();

        return $result->fetchAllAssociative();
    }

    /**
     * Fetch page content plus ancestor page content.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchWithAncestors(string $table, int $uid, int $levels): array
    {
        $records = $this->fetchPageContent($table, $uid);
        if ($records === []) {
            return [];
        }

        $pid = (int) ($records[0]['pid'] ?? 0);

        // Walk up the page tree
        for ($i = 0; $i < $levels && $pid > 0; $i++) {
            $parentPid = $this->getParentPageId($pid);
            if ($parentPid <= 0) {
                break;
            }

            $parentRecords = $this->fetchContentForPage($parentPid);
            $records = array_merge($records, $parentRecords);
            $pid = $parentPid;
        }

        return $records;
    }

    private function getParentPageId(int $pageUid): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $result = $qb
            ->select('pid')
            ->from('pages')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($pageUid)))
            ->executeQuery();

        $row = $result->fetchAssociative();

        return $row !== false ? (int) ($row['pid'] ?? 0) : 0;
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function formatRecords(array $records, string $scope, int $currentUid): string
    {
        $parts = [];

        foreach ($records as $record) {
            $uid = (int) ($record['uid'] ?? 0);
            $isCurrent = $uid === $currentUid;
            $prefix = $isCurrent ? '=== Current content element' : '--- Content element';
            $suffix = $isCurrent ? ' ===' : ' ---';
            $parts[] = "$prefix (tt_content #$uid)$suffix";
            $parts[] = $this->formatSingleRecord($record);
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function formatSingleRecord(array $record): string
    {
        $lines = [];
        foreach (self::TEXT_FIELDS as $field) {
            $value = trim((string) ($record[$field] ?? ''));
            if ($value !== '') {
                $label = ucfirst($field);
                $lines[] = "$label: $value";
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Count words in text, stripping HTML tags first.
     */
    private function countWords(string $text): int
    {
        $stripped = strip_tags($text);
        $decoded = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $words = preg_split('/\s+/', trim($decoded), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? count($words) : 0;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && composer ci:test:php:unit -- --filter ContextAssemblyServiceTest`
Expected: All tests PASS

**Step 5: Commit**

```bash
git add Classes/Service/ContextAssemblyService.php Tests/Unit/Service/ContextAssemblyServiceTest.php
git commit -S --signoff -m "feat(context-zoom): add ContextAssemblyService for server-side content assembly"
```

---

### Task 4: Register AJAX route and inject URL

**Files:**
- Modify: `Configuration/Backend/AjaxRoutes.php` (line 46)
- Modify: `Classes/EventListener/InjectAjaxUrlsListener.php` (lines 73-88)

**Step 1: Add the route**

Add to `AjaxRoutes.php` after `tx_cowriter_task_execute`:
```php
'tx_cowriter_context' => [
    'path'   => '/cowriter/context',
    'target' => AjaxController::class . '::getContextAction',
],
```

**Step 2: Add URL injection**

In `InjectAjaxUrlsListener.php` `buildJsonData()`, add after `tx_cowriter_task_execute`:
```php
'tx_cowriter_context' => (string) $this->backendUriBuilder
    ->buildUriFromRoute('ajax_tx_cowriter_context'),
```

**Step 3: Run existing tests to verify no breakage**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && composer ci:test:php:unit -- --filter InjectAjaxUrlsListenerTest`
Expected: PASS (existing tests still work — the new route will only be tested via integration)

**Step 4: Commit**

```bash
git add Configuration/Backend/AjaxRoutes.php Classes/EventListener/InjectAjaxUrlsListener.php
git commit -S --signoff -m "feat(context-zoom): register context preview AJAX route and inject URL"
```

---

### Task 5: AjaxController getContextAction

**Files:**
- Modify: `Classes/Controller/AjaxController.php` (add import + method + constructor param)
- Modify: `Tests/Unit/Controller/AjaxControllerTest.php` (add tests)

**Step 1: Write the failing tests**

Add to `AjaxControllerTest.php`. First, add the ContextAssemblyService mock to `setUp()`:

```php
// In setUp(), add:
private ContextAssemblyService&MockObject $contextAssemblyMock;

// In setUp() body:
$this->contextAssemblyMock = $this->createMock(ContextAssemblyService::class);

// Update constructor call:
$this->subject = new AjaxController(
    $this->llmServiceManagerMock,
    $this->configRepositoryMock,
    $this->taskRepositoryMock,
    $this->rateLimiterMock,
    $this->contextMock,
    $this->loggerMock,
    $this->contextAssemblyMock,
);
```

Add test methods:

```php
// =========================================================================
// getContextAction
// =========================================================================

#[Test]
public function getContextActionReturnsSummaryForValidRequest(): void
{
    $this->contextAssemblyMock
        ->method('getContextSummary')
        ->with('tt_content', 123, 'bodytext', 'page')
        ->willReturn(['summary' => '3 elements, ~42 words', 'wordCount' => 42]);

    $request = $this->createStub(ServerRequestInterface::class);
    $request->method('getQueryParams')->willReturn([
        'table' => 'tt_content',
        'uid'   => '123',
        'field' => 'bodytext',
        'scope' => 'page',
    ]);

    $response = $this->subject->getContextAction($request);
    $body = json_decode((string) $response->getBody(), true);

    self::assertSame(200, $response->getStatusCode());
    self::assertTrue($body['success']);
    self::assertSame('3 elements, ~42 words', $body['summary']);
    self::assertSame(42, $body['wordCount']);
}

#[Test]
public function getContextActionReturns400ForInvalidRequest(): void
{
    $request = $this->createStub(ServerRequestInterface::class);
    $request->method('getQueryParams')->willReturn([
        'table' => 'be_users',
        'uid'   => '1',
        'field' => 'username',
        'scope' => 'page',
    ]);

    $response = $this->subject->getContextAction($request);

    self::assertSame(400, $response->getStatusCode());
}
```

**Step 2: Run test to verify it fails**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && composer ci:test:php:unit -- --filter AjaxControllerTest::getContextAction`
Expected: FAIL — method doesn't exist

**Step 3: Update the AjaxController**

Add import at top of `AjaxController.php`:
```php
use Netresearch\T3Cowriter\Domain\DTO\ContextRequest;
use Netresearch\T3Cowriter\Service\ContextAssemblyService;
```

Add to constructor:
```php
public function __construct(
    private LlmServiceManagerInterface $llmServiceManager,
    private LlmConfigurationRepository $configurationRepository,
    private TaskRepository $taskRepository,
    private RateLimiterInterface $rateLimiter,
    private Context $context,
    private LoggerInterface $logger,
    private ContextAssemblyService $contextAssemblyService,
) {}
```

Add new method after `getTasksAction()`:
```php
/**
 * Get a lightweight context preview (word count, summary).
 *
 * Returns summary information about the content that would be assembled
 * for the given scope, without building the full context text.
 */
public function getContextAction(ServerRequestInterface $request): ResponseInterface
{
    $dto = ContextRequest::fromQueryParams($request);

    if (!$dto->isValid()) {
        return new JsonResponse(
            ['success' => false, 'error' => 'Invalid context request.'],
            400,
        );
    }

    try {
        $result = $this->contextAssemblyService->getContextSummary(
            $dto->table,
            $dto->uid,
            $dto->field,
            $dto->scope,
        );

        return new JsonResponse([
            'success'   => true,
            'summary'   => $result['summary'],
            'wordCount' => $result['wordCount'],
        ]);
    } catch (Throwable $e) {
        $this->logger->error('Context preview error', [
            'exception' => $e->getMessage(),
        ]);

        return new JsonResponse(
            ['success' => false, 'error' => 'Failed to fetch context preview.'],
            500,
        );
    }
}
```

**Step 4: Run test to verify it passes**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && composer ci:test:php:unit -- --filter AjaxControllerTest`
Expected: All tests PASS (including existing tests — update setUp if needed for new constructor param)

**Step 5: Commit**

```bash
git add Classes/Controller/AjaxController.php Tests/Unit/Controller/AjaxControllerTest.php
git commit -S --signoff -m "feat(context-zoom): add getContextAction endpoint for context preview"
```

---

### Task 6: Extend executeTaskAction with server-side context assembly

**Files:**
- Modify: `Classes/Controller/AjaxController.php` (`executeTaskAction` method)
- Modify: `Tests/Unit/Controller/AjaxControllerTest.php`

**Step 1: Write the failing test**

Add to `AjaxControllerTest.php`:

```php
#[Test]
public function executeTaskActionUsesAssembledContextForPageScope(): void
{
    $this->contextAssemblyMock
        ->method('assembleContext')
        ->with('tt_content', 42, 'bodytext', 'page', [])
        ->willReturn("=== Content element (tt_content #42) ===\nHeader: Test\nBodytext: Full page content\n");

    $task = $this->createStub(Task::class);
    $task->method('isActive')->willReturn(true);
    $task->method('getUid')->willReturn(1);
    $task->method('getConfiguration')->willReturn(null);
    $task->method('buildPrompt')->willReturnCallback(
        fn (array $vars) => 'Improve: ' . $vars['input'],
    );
    $this->taskRepositoryMock->method('findByUid')->willReturn($task);

    $config = $this->createStub(LlmConfiguration::class);
    $this->configRepositoryMock->method('findDefault')->willReturn($config);

    $completionResponse = new CompletionResponse(
        content: 'Improved content',
        model: 'test-model',
    );
    $this->llmServiceManagerMock
        ->method('chatWithConfiguration')
        ->willReturn($completionResponse);

    $request = $this->createJsonRequest([
        'taskUid'       => 1,
        'context'       => 'original text',
        'contextType'   => 'selection',
        'contextScope'  => 'page',
        'recordContext' => ['table' => 'tt_content', 'uid' => 42, 'field' => 'bodytext'],
    ]);

    $response = $this->subject->executeTaskAction($request);
    $body = json_decode((string) $response->getBody(), true);

    self::assertSame(200, $response->getStatusCode());
    self::assertTrue($body['success']);

    // Verify that chatWithConfiguration was called with assembled context
    $calls = $this->llmServiceManagerMock->getInvocations();
    // The user message should contain the assembled context, not just 'original text'
    $messages = $calls[0]->getParameters()[0];
    $userMessage = array_values(array_filter($messages, fn ($m) => $m['role'] === 'user'))[0] ?? null;
    self::assertNotNull($userMessage);
    self::assertStringContainsString('Full page content', $userMessage['content']);
}
```

**Step 2: Run test to verify it fails**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && composer ci:test:php:unit -- --filter AjaxControllerTest::executeTaskActionUsesAssembledContextForPageScope`
Expected: FAIL — context not assembled server-side yet

**Step 3: Update executeTaskAction**

In `executeTaskAction()`, after loading and validating the task but before building the prompt, add context resolution:

```php
// Resolve context: for extended scopes, assemble from DB
$context = $dto->context;
if ($dto->contextScope !== '' && $dto->contextScope !== 'selection' && $dto->contextScope !== 'text'
    && $dto->recordContext !== null
) {
    try {
        $context = $this->contextAssemblyService->assembleContext(
            $dto->recordContext['table'],
            $dto->recordContext['uid'],
            $dto->recordContext['field'],
            $dto->contextScope,
            $dto->referencePages,
        );
    } catch (Throwable $e) {
        $this->logger->error('Context assembly error', [
            'exception' => $e->getMessage(),
        ]);
        return $this->jsonResponseWithRateLimitHeaders(
            CompleteResponse::error('Failed to assemble context.')->jsonSerialize(),
            $rateLimitResult,
            500,
        );
    }
}

// Build prompt from task template — use assembled context
$prompt = $task->buildPrompt(['input' => $context]);
```

**Step 4: Run test to verify it passes**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && composer ci:test:php:unit -- --filter AjaxControllerTest`
Expected: All tests PASS

**Step 5: Commit**

```bash
git add Classes/Controller/AjaxController.php Tests/Unit/Controller/AjaxControllerTest.php
git commit -S --signoff -m "feat(context-zoom): extend executeTaskAction with server-side context assembly"
```

---

### Task 7: AIService — add getContext and extend executeTask

**Files:**
- Modify: `Resources/Public/JavaScript/Ckeditor/AIService.js`
- Modify: `Tests/JavaScript/AIService.test.js`

**Step 1: Write the failing tests**

Add to `AIService.test.js` in the `constructor` describe block, update route expectations:
```javascript
it('should initialize context route from TYPO3.settings.ajaxUrls', async () => {
    globalThis.TYPO3.settings.ajaxUrls.tx_cowriter_context = '/typo3/ajax/tx_cowriter_context';
    vi.resetModules();
    const module = await import('../../Resources/Public/JavaScript/Ckeditor/AIService.js');
    const ServiceClass = module.AIService;
    const service = new ServiceClass();
    expect(service._routes.context).toBe('/typo3/ajax/tx_cowriter_context');
});
```

Add new describe block for `getContext`:
```javascript
describe('getContext', () => {
    beforeEach(() => {
        globalThis.TYPO3.settings.ajaxUrls.tx_cowriter_context = '/typo3/ajax/tx_cowriter_context';
    });

    it('should send GET request with query params', async () => {
        vi.resetModules();
        const module = await import('../../Resources/Public/JavaScript/Ckeditor/AIService.js');
        const ServiceClass = module.AIService;
        const service = new ServiceClass();

        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({
                success: true,
                summary: '3 elements, ~42 words',
                wordCount: 42,
            }),
        });

        const result = await service.getContext('tt_content', 123, 'bodytext', 'page');

        expect(globalThis.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/typo3/ajax/tx_cowriter_context'),
            expect.objectContaining({ method: 'GET' }),
        );
        expect(result.summary).toBe('3 elements, ~42 words');
        expect(result.wordCount).toBe(42);
    });

    it('should throw when context route is not configured', async () => {
        const service = new AIService();
        await expect(service.getContext('tt_content', 1, 'bodytext', 'page'))
            .rejects.toThrow('TYPO3 AJAX routes not configured');
    });
});
```

Add tests for extended `executeTask` signature:
```javascript
describe('executeTask extended params', () => {
    beforeEach(() => {
        globalThis.TYPO3.settings.ajaxUrls.tx_cowriter_task_execute = '/typo3/ajax/tx_cowriter_task_execute';
    });

    it('should include contextScope and recordContext in request body', async () => {
        vi.resetModules();
        const module = await import('../../Resources/Public/JavaScript/Ckeditor/AIService.js');
        const ServiceClass = module.AIService;
        const service = new ServiceClass();

        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ success: true, content: 'result' }),
        });

        await service.executeTask(1, 'text', 'selection', '', '', 'page',
            { table: 'tt_content', uid: 42, field: 'bodytext' },
            [{ pid: 5, relation: 'reference' }],
        );

        const body = JSON.parse(globalThis.fetch.mock.calls[0][1].body);
        expect(body.contextScope).toBe('page');
        expect(body.recordContext).toEqual({ table: 'tt_content', uid: 42, field: 'bodytext' });
        expect(body.referencePages).toEqual([{ pid: 5, relation: 'reference' }]);
    });

    it('should default contextScope to empty and referencePages to empty array', async () => {
        vi.resetModules();
        const module = await import('../../Resources/Public/JavaScript/Ckeditor/AIService.js');
        const ServiceClass = module.AIService;
        const service = new ServiceClass();

        globalThis.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ success: true, content: 'result' }),
        });

        await service.executeTask(1, 'text', 'selection');

        const body = JSON.parse(globalThis.fetch.mock.calls[0][1].body);
        expect(body.contextScope).toBe('');
        expect(body.referencePages).toEqual([]);
    });
});
```

**Step 2: Run test to verify it fails**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && npx vitest run Tests/JavaScript/AIService.test.js`
Expected: FAIL — getContext doesn't exist, executeTask signature missing new params

**Step 3: Update AIService.js**

Add `context` to `_routes`:
```javascript
_routes = {
    chat: null,
    complete: null,
    stream: null,
    tasks: null,
    taskExecute: null,
    context: null,
};
```

In constructor, add:
```javascript
this._routes.context = TYPO3.settings.ajaxUrls.tx_cowriter_context || null;
```

Add `getContext` method:
```javascript
/**
 * Fetch a lightweight context preview (summary + word count).
 *
 * @param {string} table - Record table name
 * @param {number} uid - Record UID
 * @param {string} field - Record field name
 * @param {string} scope - Context scope
 * @returns {Promise<{success: boolean, summary: string, wordCount: number}>}
 */
async getContext(table, uid, field, scope) {
    if (!this._routes.context) {
        throw new Error(
            'TYPO3 AJAX routes not configured. Ensure the cowriter extension is properly installed.'
        );
    }

    const params = new URLSearchParams({ table, uid: String(uid), field, scope });
    const url = `${this._routes.context}&${params.toString()}`;

    const response = await fetch(url, {
        method: 'GET',
        headers: { 'Content-Type': 'application/json' },
    });

    if (!response.ok) {
        const error = await response.json().catch(() => ({ error: 'Unknown error' }));
        throw new Error(error.error || `HTTP ${response.status}`);
    }

    return response.json();
}
```

Update `executeTask` signature:
```javascript
/**
 * Execute a cowriter task with context.
 *
 * @param {number} taskUid
 * @param {string} context
 * @param {string} contextType
 * @param {string} [instruction='']
 * @param {string} [editorCapabilities='']
 * @param {string} [contextScope='']
 * @param {{table: string, uid: number, field: string}|null} [recordContext=null]
 * @param {Array<{pid: number, relation: string}>} [referencePages=[]]
 * @returns {Promise<CompleteResponse>}
 */
async executeTask(
    taskUid, context, contextType,
    instruction = '', editorCapabilities = '',
    contextScope = '', recordContext = null, referencePages = [],
) {
    // ... existing validation ...

    const response = await fetch(this._routes.taskExecute, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            taskUid, context, contextType, instruction, editorCapabilities,
            contextScope, recordContext, referencePages,
        }),
    });

    // ... rest unchanged ...
}
```

**Step 4: Run test to verify it passes**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && npx vitest run Tests/JavaScript/AIService.test.js`
Expected: All tests PASS

**Step 5: Commit**

```bash
git add Resources/Public/JavaScript/Ckeditor/AIService.js Tests/JavaScript/AIService.test.js
git commit -S --signoff -m "feat(context-zoom): add getContext and extend executeTask in AIService"
```

---

### Task 8: Extract record context from CKEditor DOM

**Files:**
- Modify: `Resources/Public/JavaScript/Ckeditor/cowriter.js`
- Modify: `Tests/JavaScript/cowriter.test.js`

**Step 1: Write the failing tests**

Add to `cowriter.test.js`, new describe block:

```javascript
describe('_getRecordContext', () => {
    it('should parse record context from textarea name attribute', () => {
        const plugin = new Cowriter();

        // Create a mock source element with proper name attribute
        const textarea = document.createElement('textarea');
        textarea.name = 'data[tt_content][123][bodytext]';
        const wrapper = document.createElement('div');
        wrapper.appendChild(textarea);

        plugin.editor = {
            sourceElement: wrapper,
        };

        const context = plugin._getRecordContext();
        expect(context).toEqual({
            table: 'tt_content',
            uid: 123,
            field: 'bodytext',
        });
    });

    it('should return null when no textarea found', () => {
        const plugin = new Cowriter();
        plugin.editor = {
            sourceElement: document.createElement('div'),
        };

        const context = plugin._getRecordContext();
        expect(context).toBeNull();
    });

    it('should return null when name pattern does not match', () => {
        const plugin = new Cowriter();
        const textarea = document.createElement('textarea');
        textarea.name = 'some-other-format';
        const wrapper = document.createElement('div');
        wrapper.appendChild(textarea);

        plugin.editor = { sourceElement: wrapper };

        const context = plugin._getRecordContext();
        expect(context).toBeNull();
    });

    it('should handle sourceElement being the textarea itself', () => {
        const plugin = new Cowriter();
        const textarea = document.createElement('textarea');
        textarea.name = 'data[pages][5][description]';

        plugin.editor = { sourceElement: textarea };

        const context = plugin._getRecordContext();
        expect(context).toEqual({
            table: 'pages',
            uid: 5,
            field: 'description',
        });
    });
});
```

Update the button execute tests to verify `recordContext` is passed to dialog:

In the `beforeEach` for `button execute handler`, update mockDialogShow to accept 4 params:
```javascript
mockDialogShow = vi.fn().mockResolvedValue({ content: 'AI response' });
```

Add test:
```javascript
it('should pass record context to dialog', async () => {
    // Create a textarea to extract context from
    const textarea = document.createElement('textarea');
    textarea.name = 'data[tt_content][99][bodytext]';
    const wrapper = document.createElement('div');
    wrapper.appendChild(textarea);
    mockEditor.sourceElement = wrapper;

    const mockRange = {
        getItems: () => [{ data: 'selected' }],
    };
    mockEditor.model.document.selection.getRanges.mockReturnValue([mockRange]);

    await capturedButton.fire('execute');

    expect(mockDialogShow).toHaveBeenCalledWith(
        'selected',
        '<p>Full editor content</p>',
        '',
        { table: 'tt_content', uid: 99, field: 'bodytext' },
    );
});
```

**Step 2: Run test to verify it fails**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && npx vitest run Tests/JavaScript/cowriter.test.js`
Expected: FAIL — `_getRecordContext` doesn't exist

**Step 3: Write the implementation**

Add method to `cowriter.js`:
```javascript
/**
 * Extract record context (table, uid, field) from the CKEditor source element's DOM.
 *
 * TYPO3 FormEngine names textareas as: data[table][uid][field]
 * e.g. data[tt_content][123][bodytext]
 *
 * @returns {{table: string, uid: number, field: string}|null}
 * @private
 */
_getRecordContext() {
    const el = this.editor.sourceElement;
    if (!el) return null;

    // The source element may be the textarea itself or a wrapper containing it
    const textarea = el.tagName === 'TEXTAREA' ? el : el.querySelector('textarea');
    if (!textarea?.name) return null;

    const match = textarea.name.match(/^data\[(\w+)]\[(\d+)]\[(\w+)]$/);
    if (!match) return null;

    return {
        table: match[1],
        uid: parseInt(match[2], 10),
        field: match[3],
    };
}
```

Update the button execute handler to extract and pass record context:
```javascript
// After: const caps = this._getEditorCapabilities();
const recordContext = this._getRecordContext();

// Update dialog.show call:
const result = await dialog.show(selectedText || '', fullContent, caps, recordContext);
```

**Step 4: Run test to verify it passes**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && npx vitest run Tests/JavaScript/cowriter.test.js`
Expected: All tests PASS

**Step 5: Commit**

```bash
git add Resources/Public/JavaScript/Ckeditor/cowriter.js Tests/JavaScript/cowriter.test.js
git commit -S --signoff -m "feat(context-zoom): extract record context from CKEditor DOM"
```

---

### Task 9: CowriterDialog — context zoom slider UI

**Files:**
- Modify: `Resources/Public/JavaScript/Ckeditor/CowriterDialog.js`
- Modify: `Tests/JavaScript/CowriterDialog.test.js`

This is the largest task. The slider replaces the radio buttons. Summary of changes:

1. `show()` signature: add `recordContext` parameter
2. `_showModal()`: pass `recordContext` into `executeTrigger` closure
3. `_buildDialogContent()`: replace radio buttons with range slider + context summary label
4. Add slider change handler: fetch context preview via `service.getContext()`
5. `executeTrigger`: use slider value as `contextScope`, pass `recordContext` and `referencePages`
6. Add reference page picker section (below slider)

**Step 1: Write the failing tests**

Replace the context radio button tests in `CowriterDialog.test.js`:

```javascript
describe('context zoom slider', () => {
    it('should render range slider instead of radio buttons', async () => {
        const dialog = new CowriterDialog(mockService);
        const showPromise = dialog.show('selected', 'full', '', null);

        await vi.waitFor(() => {
            expect(document.querySelector('[data-role="context-slider"]')).not.toBeNull();
        });

        const slider = document.querySelector('[data-role="context-slider"]');
        expect(slider.tagName).toBe('INPUT');
        expect(slider.type).toBe('range');
        expect(slider.min).toBe('0');
        expect(slider.max).toBe('5');

        // Radio buttons should NOT exist
        expect(document.querySelector('input[name="cowriter-context"]')).toBeNull();

        document.querySelector('[data-name="cancel"]').click();
        await showPromise.catch(() => {});
    });

    it('should disable stop 0 (selection) when no text selected', async () => {
        const dialog = new CowriterDialog(mockService);
        const showPromise = dialog.show('', 'full', '', null);

        await vi.waitFor(() => {
            const slider = document.querySelector('[data-role="context-slider"]');
            expect(slider).not.toBeNull();
            // Minimum should be 1 when no selection
            expect(slider.min).toBe('1');
            expect(slider.value).toBe('1');
        });

        document.querySelector('[data-name="cancel"]').click();
        await showPromise.catch(() => {});
    });

    it('should start at stop 0 (selection) when text is selected', async () => {
        const dialog = new CowriterDialog(mockService);
        const showPromise = dialog.show('selected text', 'full', '', null);

        await vi.waitFor(() => {
            const slider = document.querySelector('[data-role="context-slider"]');
            expect(slider).not.toBeNull();
            expect(slider.min).toBe('0');
            expect(slider.value).toBe('0');
        });

        document.querySelector('[data-name="cancel"]').click();
        await showPromise.catch(() => {});
    });

    it('should show scope label below slider', async () => {
        const dialog = new CowriterDialog(mockService);
        const showPromise = dialog.show('selected', 'full', '', null);

        await vi.waitFor(() => {
            const label = document.querySelector('[data-role="scope-label"]');
            expect(label).not.toBeNull();
            expect(label.textContent).toContain('Selection');
        });

        document.querySelector('[data-name="cancel"]').click();
        await showPromise.catch(() => {});
    });

    it('should update scope label when slider changes', async () => {
        const dialog = new CowriterDialog(mockService);
        const showPromise = dialog.show('selected', 'full', '',
            { table: 'tt_content', uid: 42, field: 'bodytext' });

        await vi.waitFor(() => {
            expect(document.querySelector('[data-role="context-slider"]')).not.toBeNull();
        });

        // Mock getContext for the preview
        mockService.getContext = vi.fn().mockResolvedValue({
            success: true,
            summary: '3 elements, ~42 words',
            wordCount: 42,
        });

        const slider = document.querySelector('[data-role="context-slider"]');
        slider.value = '3'; // page
        slider.dispatchEvent(new Event('input'));

        await vi.waitFor(() => {
            const label = document.querySelector('[data-role="scope-label"]');
            expect(label.textContent).toContain('Page');
        });

        document.querySelector('[data-name="cancel"]').click();
        await showPromise.catch(() => {});
    });

    it('should pass contextScope and recordContext to executeTask', async () => {
        const dialog = new CowriterDialog(mockService);
        const recordContext = { table: 'tt_content', uid: 42, field: 'bodytext' };
        const showPromise = dialog.show('text', 'full', '', recordContext);

        await vi.waitFor(() => {
            expect(document.querySelector('[data-name="execute"]')).not.toBeNull();
        });

        // Move slider to "page" (stop 3)
        const slider = document.querySelector('[data-role="context-slider"]');
        slider.value = '3';
        slider.dispatchEvent(new Event('input'));

        document.querySelector('[data-name="execute"]').click();

        await vi.waitFor(() => {
            expect(mockService.executeTask).toHaveBeenCalledWith(
                1, 'text', 'selection', '', '',
                'page', recordContext, [],
            );
        });

        document.querySelector('[data-name="execute"]').click();
        await showPromise;
    });

    it('should map slider stops to correct scope values', async () => {
        const dialog = new CowriterDialog(mockService);
        const rc = { table: 'tt_content', uid: 1, field: 'bodytext' };
        const showPromise = dialog.show('text', 'full', '', rc);

        await vi.waitFor(() => {
            expect(document.querySelector('[data-role="context-slider"]')).not.toBeNull();
        });

        const slider = document.querySelector('[data-role="context-slider"]');
        const expectedScopes = ['selection', 'text', 'element', 'page', 'ancestors_1', 'ancestors_2'];

        for (let i = 0; i <= 5; i++) {
            slider.value = String(i);
            slider.dispatchEvent(new Event('input'));

            const label = document.querySelector('[data-role="scope-label"]');
            // Just verify it updates without error
            expect(label).not.toBeNull();
        }

        // Execute at stop 4 (ancestors_1)
        slider.value = '4';
        slider.dispatchEvent(new Event('input'));
        document.querySelector('[data-name="execute"]').click();

        await vi.waitFor(() => {
            expect(mockService.executeTask).toHaveBeenCalledWith(
                1, 'text', 'selection', '', '',
                'ancestors_1', rc, [],
            );
        });

        document.querySelector('[data-name="execute"]').click();
        await showPromise;
    });
});
```

**Step 2: Run test to verify it fails**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && npx vitest run Tests/JavaScript/CowriterDialog.test.js`
Expected: FAIL — slider doesn't exist, show() has wrong arity

**Step 3: Implement the slider UI**

Update `CowriterDialog.js`:

1. Update `show()` signature:
```javascript
async show(selectedText, fullContent, editorCapabilities = '', recordContext = null) {
    // ...
    return this._showModal(tasks, selectedText, fullContent, editorCapabilities, recordContext);
}
```

2. Update `_showModal()` signature and replace the context handling:
```javascript
_showModal(tasks, selectedText, fullContent, editorCapabilities, recordContext) {
    const container = this._buildDialogContent(tasks, selectedText, fullContent, recordContext);
    // ... same state variables ...

    // In executeTrigger, read slider value instead of radio:
    const executeTrigger = async () => {
        // ... loading/result state checks unchanged ...

        const taskUid = parseInt(
            container.querySelector('[data-role="task-select"]').value, 10,
        );
        const slider = container.querySelector('[data-role="context-slider"]');
        const scopeIndex = parseInt(slider.value, 10);
        const scopes = ['selection', 'text', 'element', 'page', 'ancestors_1', 'ancestors_2'];
        const contextScope = scopes[scopeIndex] || 'selection';
        const contextType = scopeIndex === 0 ? 'selection' : 'content_element';
        const context = contextType === 'selection' ? selectedText : fullContent;
        const instruction = container.querySelector('[data-role="instruction"]').value.trim();

        const result = await this._service.executeTask(
            taskUid, context, contextType, instruction, editorCapabilities,
            contextScope, recordContext, [],
        );
        // ... rest of result handling unchanged ...
    };

    // Reset listeners: add slider to reset triggers
    container.querySelector('[data-role="context-slider"]')?.addEventListener('input', resetResult);
    // ... rest unchanged ...
}
```

3. Update `_buildDialogContent()` — replace radio buttons with slider:
```javascript
_buildDialogContent(tasks, selectedText, fullContent, recordContext) {
    // ... task selector unchanged ...

    // Context zoom slider (replaces radio buttons)
    const contextGroup = this._createFormGroup('Context scope');
    const hasSelection = Boolean(selectedText && selectedText.trim().length > 0);

    const slider = document.createElement('input');
    slider.type = 'range';
    slider.className = 'form-range';
    slider.dataset.role = 'context-slider';
    slider.min = hasSelection ? '0' : '1';
    slider.max = '5';
    slider.value = hasSelection ? '0' : '1';
    slider.step = '1';
    contextGroup.appendChild(slider);

    // Tick labels
    const tickLabels = document.createElement('div');
    tickLabels.className = 'd-flex justify-content-between';
    tickLabels.style.fontSize = '0.75rem';
    const labels = ['Selection', 'Text', 'Element', 'Page', '+1 level', '+2 levels'];
    for (const label of labels) {
        const span = document.createElement('span');
        span.textContent = label;
        span.style.flex = '1';
        span.style.textAlign = 'center';
        tickLabels.appendChild(span);
    }
    contextGroup.appendChild(tickLabels);

    // Scope summary label
    const scopeLabel = document.createElement('div');
    scopeLabel.className = 'form-text text-body-secondary mt-1';
    scopeLabel.dataset.role = 'scope-label';
    scopeLabel.textContent = hasSelection ? 'Selection' : 'Text';
    contextGroup.appendChild(scopeLabel);

    // Slider change handler
    slider.addEventListener('input', () => {
        const index = parseInt(slider.value, 10);
        const scopeNames = ['Selection', 'Text', 'Element', 'Page', '+1 ancestor level', '+2 ancestor levels'];
        scopeLabel.textContent = scopeNames[index] || '';

        // Fetch preview for scopes that need DB lookup
        const scopes = ['selection', 'text', 'element', 'page', 'ancestors_1', 'ancestors_2'];
        if (index >= 2 && recordContext && this._service.getContext) {
            this._service.getContext(
                recordContext.table, recordContext.uid, recordContext.field, scopes[index],
            ).then((result) => {
                if (result.success) {
                    scopeLabel.textContent = `${scopeNames[index]} (${result.summary})`;
                }
            }).catch(() => {
                // Silently ignore preview errors — the scope label still shows
            });
        }
    });

    container.appendChild(contextGroup);

    // ... ad-hoc rules and result preview unchanged ...
}
```

**Step 4: Run test to verify it passes**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && npx vitest run Tests/JavaScript/CowriterDialog.test.js`
Expected: All tests PASS

**Step 5: Update existing tests that reference radio buttons**

Some existing tests reference `input[name="cowriter-context"]` or `input[value="selection"]`. These need updating:
- `'should pre-select "Selected text"'` → verify `slider.value === '0'` and `slider.min === '0'`
- `'should pre-select "Whole content"'` → verify `slider.value === '1'` and `slider.min === '1'`
- `'should call executeTask with correct parameters'` → verify new params in call
- `'should use full content when content_element'` → update for slider

**Step 6: Run all tests**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && npx vitest run`
Expected: All JS tests PASS

**Step 7: Commit**

```bash
git add Resources/Public/JavaScript/Ckeditor/CowriterDialog.js Tests/JavaScript/CowriterDialog.test.js
git commit -S --signoff -m "feat(context-zoom): replace radio buttons with context zoom slider in dialog"
```

---

### Task 10: Reference page picker UI

**Files:**
- Modify: `Resources/Public/JavaScript/Ckeditor/CowriterDialog.js`
- Modify: `Tests/JavaScript/CowriterDialog.test.js`

**Step 1: Write the failing tests**

```javascript
describe('reference page picker', () => {
    it('should render "Add reference page" button', async () => {
        const dialog = new CowriterDialog(mockService);
        const showPromise = dialog.show('text', 'full', '', null);

        await vi.waitFor(() => {
            const btn = document.querySelector('[data-role="add-reference"]');
            expect(btn).not.toBeNull();
            expect(btn.textContent).toContain('Add reference page');
        });

        document.querySelector('[data-name="cancel"]').click();
        await showPromise.catch(() => {});
    });

    it('should add reference page row when button clicked', async () => {
        const dialog = new CowriterDialog(mockService);
        const showPromise = dialog.show('text', 'full', '', null);

        await vi.waitFor(() => {
            expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull();
        });

        document.querySelector('[data-role="add-reference"]').click();

        const rows = document.querySelectorAll('[data-role="reference-row"]');
        expect(rows.length).toBe(1);

        // Should have a page ID input and relation input
        expect(rows[0].querySelector('[data-role="ref-pid"]')).not.toBeNull();
        expect(rows[0].querySelector('[data-role="ref-relation"]')).not.toBeNull();

        document.querySelector('[data-name="cancel"]').click();
        await showPromise.catch(() => {});
    });

    it('should remove reference page row when remove button clicked', async () => {
        const dialog = new CowriterDialog(mockService);
        const showPromise = dialog.show('text', 'full', '', null);

        await vi.waitFor(() => {
            expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull();
        });

        // Add two rows
        document.querySelector('[data-role="add-reference"]').click();
        document.querySelector('[data-role="add-reference"]').click();
        expect(document.querySelectorAll('[data-role="reference-row"]').length).toBe(2);

        // Remove first
        document.querySelector('[data-role="remove-reference"]').click();
        expect(document.querySelectorAll('[data-role="reference-row"]').length).toBe(1);

        document.querySelector('[data-name="cancel"]').click();
        await showPromise.catch(() => {});
    });

    it('should include reference pages in executeTask call', async () => {
        const dialog = new CowriterDialog(mockService);
        const rc = { table: 'tt_content', uid: 1, field: 'bodytext' };
        const showPromise = dialog.show('text', 'full', '', rc);

        await vi.waitFor(() => {
            expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull();
        });

        // Add a reference page
        document.querySelector('[data-role="add-reference"]').click();
        const row = document.querySelector('[data-role="reference-row"]');
        row.querySelector('[data-role="ref-pid"]').value = '5';
        row.querySelector('[data-role="ref-relation"]').value = 'style guide';

        document.querySelector('[data-name="execute"]').click();

        await vi.waitFor(() => {
            expect(mockService.executeTask).toHaveBeenCalledWith(
                1, 'text', 'selection', '', '',
                'selection', rc,
                [{ pid: 5, relation: 'style guide' }],
            );
        });

        document.querySelector('[data-name="execute"]').click();
        await showPromise;
    });
});
```

**Step 2: Run test to verify it fails**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && npx vitest run Tests/JavaScript/CowriterDialog.test.js`
Expected: FAIL — no `add-reference` button

**Step 3: Add reference page picker to dialog**

In `_buildDialogContent()`, add after the context slider group:

```javascript
// Reference pages section
const refGroup = this._createFormGroup('Reference pages (optional)');

const refContainer = document.createElement('div');
refContainer.dataset.role = 'reference-list';
refGroup.appendChild(refContainer);

const addRefBtn = document.createElement('button');
addRefBtn.type = 'button';
addRefBtn.className = 'btn btn-sm btn-outline-secondary mt-1';
addRefBtn.dataset.role = 'add-reference';
addRefBtn.textContent = '+ Add reference page';
addRefBtn.addEventListener('click', () => {
    refContainer.appendChild(this._createReferenceRow());
});
refGroup.appendChild(addRefBtn);

container.appendChild(refGroup);
```

Add helper method `_createReferenceRow()`:
```javascript
/**
 * Create a reference page row with page ID, relation, and remove button.
 * @returns {HTMLElement}
 * @private
 */
_createReferenceRow() {
    const row = document.createElement('div');
    row.className = 'd-flex gap-2 mb-1 align-items-center';
    row.dataset.role = 'reference-row';

    const pidInput = document.createElement('input');
    pidInput.type = 'number';
    pidInput.className = 'form-control form-control-sm';
    pidInput.dataset.role = 'ref-pid';
    pidInput.placeholder = 'Page ID';
    pidInput.style.width = '100px';
    row.appendChild(pidInput);

    const relationInput = document.createElement('input');
    relationInput.type = 'text';
    relationInput.className = 'form-control form-control-sm';
    relationInput.dataset.role = 'ref-relation';
    relationInput.placeholder = 'Relation (e.g., style guide, reference)';
    relationInput.setAttribute('list', 'cowriter-relation-presets');
    row.appendChild(relationInput);

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn btn-sm btn-outline-danger';
    removeBtn.dataset.role = 'remove-reference';
    removeBtn.textContent = '\u2715';
    removeBtn.addEventListener('click', () => row.remove());
    row.appendChild(removeBtn);

    return row;
}
```

Add datalist for relation presets (add to `_buildDialogContent` once):
```javascript
// Relation presets datalist
if (!document.getElementById('cowriter-relation-presets')) {
    const datalist = document.createElement('datalist');
    datalist.id = 'cowriter-relation-presets';
    for (const preset of ['reference material', 'parent topic', 'style guide', 'similar content']) {
        const option = document.createElement('option');
        option.value = preset;
        datalist.appendChild(option);
    }
    container.appendChild(datalist);
}
```

Update `executeTrigger` to collect reference pages:
```javascript
// Collect reference pages
const refRows = container.querySelectorAll('[data-role="reference-row"]');
const referencePages = [];
for (const row of refRows) {
    const pid = parseInt(row.querySelector('[data-role="ref-pid"]')?.value, 10);
    const relation = row.querySelector('[data-role="ref-relation"]')?.value?.trim() || '';
    if (pid > 0) {
        referencePages.push({ pid, relation });
    }
}

const result = await this._service.executeTask(
    taskUid, context, contextType, instruction, editorCapabilities,
    contextScope, recordContext, referencePages,
);
```

**Step 4: Run test to verify it passes**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && npx vitest run Tests/JavaScript/CowriterDialog.test.js`
Expected: All tests PASS

**Step 5: Run all tests**

Run: `cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14 && npx vitest run && composer ci:test:php:unit`
Expected: All JS and PHP tests PASS

**Step 6: Commit**

```bash
git add Resources/Public/JavaScript/Ckeditor/CowriterDialog.js Tests/JavaScript/CowriterDialog.test.js
git commit -S --signoff -m "feat(context-zoom): add reference page picker to cowriter dialog"
```

---

### Task 11: Final verification and cleanup

**Step 1: Run full test suite**

```bash
cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14
npx vitest run
composer ci:test:php:unit
```

Expected: All tests PASS

**Step 2: Manual browser test checklist**

After `make up` (ddev start + install):

1. Open a content element with CKEditor (tt_content bodytext)
2. Click the Cowriter toolbar button
3. Verify the dialog shows a **range slider** (not radio buttons) under "Context scope"
4. With text selected: slider starts at "Selection" (stop 0)
5. With no selection: slider starts at "Text" (stop 1), stop 0 is disabled
6. Move slider to "Page" — scope label updates with preview info
7. Verify "Add reference page" button exists below slider
8. Click it — verify a row with Page ID input, Relation input, and remove button appears
9. Fill in a page ID and select a relation preset
10. Click Execute — verify the task runs and preview shows rich HTML
11. Click Insert — verify formatted content is inserted into CKEditor

**Step 3: Final commit (if any cleanup needed)**

```bash
git add -A
git commit -S --signoff -m "chore(context-zoom): final cleanup after integration testing"
```
