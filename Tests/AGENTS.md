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

### AjaxControllerTest

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

**CI is authoritative** - always verify fixes pass in GitHub Actions CI before merging.
Run tests locally via composer (same commands as CI).

```bash
# Unit tests
composer ci:test:php:unit

# Integration tests
composer ci:test:php:integration

# E2E tests
composer ci:test:php:e2e

# All tests
composer ci:test:all

# Full CI suite (lint + static analysis + tests)
composer ci:test && composer ci:test:all
```

## Test Structure

```
Tests/
├── Unit/
│   ├── Controller/
│   │   └── AjaxControllerTest.php
│   ├── Domain/
│   │   └── DTO/
│   │       ├── CompleteRequestTest.php
│   │       ├── CompleteResponseTest.php
│   │       └── UsageDataTest.php
│   └── EventListener/
│       └── InjectAjaxUrlsListenerTest.php
├── Integration/
│   └── Controller/
│       └── AjaxControllerIntegrationTest.php
└── E2E/
    └── CowriterWorkflowTest.php
```

## PHPUnit Attributes

Use PHPUnit 12 attribute syntax:

```php
#[CoversClass(AjaxController::class)]
final class AjaxControllerTest extends TestCase
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

1. **CI passes:** Push and verify GitHub Actions CI passes
2. **Coverage > 80%:** CI reports coverage to Codecov
3. **PHPUnit attributes:** Use `#[Test]`, `#[CoversClass]`
4. **DataProviders:** For multiple input scenarios
5. **Edge cases:** Empty, null, invalid inputs
6. **Security tests:** HTML escaping verified

## Related

- **[../Classes/AGENTS.md](../Classes/AGENTS.md)** - Implementation details
- **[../Build/phpunit/](../Build/phpunit/)** - PHPUnit configurations
