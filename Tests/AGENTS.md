# AGENTS.md - Tests

**Scope:** Unit and functional tests for t3_cowriter
**Parent:** [../AGENTS.md](../AGENTS.md)

## Coverage Target: >80%

## TDD Workflow

1. Write test (red)
2. Implement code
3. Run test (green)
4. Refactor

## Required Test Cases (15+ minimum)

### CowriterAjaxControllerTest

| # | Test Name | Purpose |
|---|-----------|---------|
| 1 | completeActionReturnsSuccessForValidPrompt | Happy path |
| 2 | completeActionReturnsErrorWhenNoPromptProvided | Validation |
| 3 | completeActionReturnsErrorWhenNoConfigurationAvailable | No default config |
| 4 | completeActionParsesModelOverridePrefix | #cw:model parsing |
| 5 | completeActionEscapesHtmlInResponse | XSS prevention |
| 6 | completeActionHandlesProviderException | Provider errors |
| 7 | completeActionHandlesRateLimitException | Rate limit 429 |
| 8 | completeActionUsesConfigurationFromIdentifier | Named config |
| 9 | completeActionIncludesUsageStatistics | Token tracking |
| 10 | completeActionRejectsInvalidPrompts | DataProvider tests |

### CompleteRequestTest

| # | Test Name | Purpose |
|---|-----------|---------|
| 11 | fromRequestExtractsPromptCorrectly | Request parsing |
| 12 | fromRequestParsesModelOverridePrefix | #cw:model extraction |
| 13 | isValidReturnsFalseForEmptyPrompt | Validation |
| 14 | isValidReturnsTrueForValidPrompt | Validation |

### CompleteResponseTest

| # | Test Name | Purpose |
|---|-----------|---------|
| 15 | successEscapesHtmlInContent | XSS prevention |
| 16 | rateLimitedIncludesRetryAfter | Rate limit response |
| 17 | jsonSerializeFormatsCorrectly | JSON output |

## Test Execution Commands

```bash
# Unit tests (via make -> composer -> runTests.sh)
make test-unit

# With coverage
make test-coverage

# Mutation testing
make test-mutation

# Underlying execution (runTests.sh)
Build/Scripts/runTests.sh -s unit
Build/Scripts/runTests.sh -s unit -c  # with coverage
Build/Scripts/runTests.sh -s mutation
```

## Test Structure

```
Tests/
├── Unit/
│   ├── Controller/
│   │   └── CowriterAjaxControllerTest.php
│   └── Domain/
│       └── DTO/
│           ├── CompleteRequestTest.php
│           └── CompleteResponseTest.php
└── Functional/
    └── Controller/
        └── CowriterAjaxControllerTest.php
```

## PHPUnit Attributes

Use PHPUnit 12 attribute syntax:

```php
#[CoversClass(CowriterAjaxController::class)]
final class CowriterAjaxControllerTest extends TestCase
{
    #[Test]
    public function completeActionReturnsSuccessForValidPrompt(): void
    {
        // Arrange
        // Act
        // Assert
    }

    #[Test]
    #[DataProvider('invalidPromptProvider')]
    public function completeActionRejectsInvalidPrompts(mixed $prompt): void
    {
        // Test with various invalid inputs
    }

    public static function invalidPromptProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'whitespace only' => ['   '];
        yield 'null' => [null];
        yield 'array' => [['nested']];
    }
}
```

## Mocking nr-llm

```php
private LlmServiceManagerInterface&MockObject $llmManager;

protected function setUp(): void
{
    $this->llmManager = $this->createMock(LlmServiceManagerInterface::class);
}

#[Test]
public function testWithMockedResponse(): void
{
    $response = new CompletionResponse(
        content: 'AI response',
        model: 'gpt-4o',
        usage: UsageStatistics::fromTokens(10, 20),
        finishReason: 'stop',
        provider: 'openai',
    );

    $this->llmManager
        ->expects(self::once())
        ->method('chat')
        ->willReturn($response);
}
```

## PR/Commit Checklist

1. **Coverage > 80%:** Check with `make test-coverage`
2. **All tests pass:** `make test-unit`
3. **PHPUnit attributes:** Use `#[Test]`, `#[CoversClass]`
4. **DataProviders:** For multiple input scenarios
5. **Edge cases:** Empty, null, invalid inputs
6. **Security tests:** HTML escaping verified

## Related

- **[../Classes/AGENTS.md](../Classes/AGENTS.md)** - Implementation details
- **[../Build/phpunit/](../Build/phpunit/)** - PHPUnit configurations
