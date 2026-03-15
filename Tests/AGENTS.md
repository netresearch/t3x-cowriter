# AGENTS.md - Tests

**Scope:** Unit and functional tests for t3_cowriter
**Parent:** [../AGENTS.md](../AGENTS.md)

## Coverage Targets

- **Codecov patch:** 80% (new code in PRs)
- **Codecov project:** auto with 2% threshold
- **Mutation testing (Infection):** Covered Code MSI >= 85%
- **`#[CoversClass]` is mandatory:** PHPUnit only attributes coverage to classes listed in this attribute. If a test exercises DTO classes indirectly (e.g., DiagnosticServiceTest creates DiagnosticCheck instances), add `#[CoversClass]` for ALL exercised classes.

## TDD Workflow

1. Write test (red)
2. Implement code
3. Run test (green)
4. Refactor

## Required Test Cases (15+ minimum)

### AjaxControllerTest

#### Chat Action Tests
| # | Test Name | Purpose |
|---|-----------|---------|
| 1 | chatActionReturnsJsonResponse | Happy path |
| 2 | chatActionReturnsErrorForInvalidJson | JSON parsing |
| 3 | chatActionHandlesProviderException | Provider errors |
| 4 | chatActionEscapesHtmlInResponse | XSS prevention |

#### Complete Action Tests
| # | Test Name | Purpose |
|---|-----------|---------|
| 5 | completeActionReturnsSuccessForValidPrompt | Happy path |
| 6 | completeActionReturnsErrorWhenNoPromptProvided | Validation |
| 7 | completeActionReturnsErrorWhenNoConfigurationAvailable | No default config |
| 8 | completeActionReturns404WhenConfigurationIdentifierNotFound | Named config missing |
| 9 | completeActionAppliesModelOverride | #cw:model parsing |
| 10 | completeActionEscapesHtmlInResponse | XSS prevention |
| 11 | completeActionHandlesProviderException | Provider errors |
| 12 | completeActionUsesConfigurationFromIdentifier | Named config |
| 13 | completeActionIncludesUsageStatistics | Token tracking |
| 14 | completeActionRejectsInvalidPrompts | DataProvider tests |

#### GetConfigurations Action Tests
| # | Test Name | Purpose |
|---|-----------|---------|
| 15 | getConfigurationsActionReturnsAvailableConfigs | List configurations |
| 16 | getConfigurationsActionReturnsEmptyArray | No configs available |

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
│   │   ├── AjaxControllerTest.php
│   │   ├── TranslationControllerTest.php
│   │   ├── VisionControllerTest.php
│   │   ├── TemplateControllerTest.php
│   │   └── ToolControllerTest.php
│   ├── Domain/DTO/
│   │   ├── CompleteRequestTest.php
│   │   ├── CompleteResponseTest.php
│   │   ├── ExecuteTaskRequestTest.php
│   │   ├── TranslationRequestTest.php
│   │   ├── ToolRequestTest.php
│   │   └── UsageDataTest.php
│   ├── EventListener/
│   │   └── InjectAjaxUrlsListenerTest.php
│   └── Service/
│       ├── ContextAssemblyServiceTest.php
│       ├── DiagnosticServiceTest.php
│       ├── RateLimiterServiceTest.php
│       └── RateLimitResultTest.php
├── Integration/
│   ├── AbstractIntegrationTestCase.php
│   └── Controller/
│       ├── AjaxControllerIntegrationTest.php
│       ├── Backend/
│       │   └── StatusControllerIntegrationTest.php
│       ├── TranslationControllerIntegrationTest.php
│       └── VisionControllerIntegrationTest.php
├── E2E/
│   ├── AbstractE2ETestCase.php
│   ├── CowriterWorkflowTest.php
│   └── NewFeatureWorkflowTest.php
├── JavaScript/                           # Vitest tests for frontend
│   ├── AIService.test.js
│   ├── cowriter.test.js
│   ├── CowriterDialog.test.js
│   └── UrlLoader.test.js
└── Support/
    └── TestQueryResult.php
```

## TYPO3 Final Class Workarounds

`ModuleTemplateFactory` and `ModuleTemplate` are `final` — cannot be mocked/stubbed. For controllers depending on them:

```php
// Use ReflectionClass to create instance without constructor
$factory = (new \ReflectionClass(ModuleTemplateFactory::class))
    ->newInstanceWithoutConstructor();

// Test private methods via reflection
$method = new ReflectionMethod(StatusController::class, 'buildFixUrls');
$result = $method->invoke($controller, $checks);
```

## BackendUriBuilder Mocks

`buildUriFromRoute()` returns `UriInterface`, not `string`. Always return a Uri object:

```php
$mock->method('buildUriFromRoute')
    ->willReturn(new \TYPO3\CMS\Core\Http\Uri('/typo3/module/path'));
```

## Constructor Change Checklist

When adding parameters to a controller constructor, update ALL occurrences:
```bash
grep -rn "new AjaxController(" Tests/
grep -rn "new TranslationController(" Tests/
```
This includes Unit/, Integration/, AND E2E/ tests.

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
