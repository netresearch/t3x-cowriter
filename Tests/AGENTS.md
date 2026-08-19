<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-08-19 -->

# AGENTS.md - Tests

**Scope:** Test suites for t3_cowriter
**Parent:** [../AGENTS.md](../AGENTS.md)

## Overview

Multi-layer test suite: PHP unit, functional, integration, and E2E tests (PHPUnit via `../Build/Scripts/runTests.sh`, configs in `../Build/phpunit/`), JavaScript unit tests (Vitest, `JavaScript/`), and browser E2E specs (Playwright, `E2E/*.spec.ts`). CI runs the PHP suites across the PHP 8.2–8.5 × TYPO3 ^13.4/^14.3 matrix.

## Setup

```bash
composer install     # PHP test stack (.Build/vendor, .Build/bin)
npm install          # Vitest + Playwright
```

PHP suites run in Docker containers via `../Build/Scripts/runTests.sh` — Docker or Podman must be running.

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

## Commands

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

# JavaScript
npm test             # Vitest (JavaScript/)
npm run test:e2e     # Playwright (E2E/*.spec.ts)
```

## Test Structure

```
Tests/
├── Unit/
│   ├── Controller/     # Ajax, Template, Tool, Translation, Vision controller tests
│   ├── Domain/DTO/     # CompleteRequest (+ fuzz), CompleteResponse, ContextRequest,
│   │                   # ExecuteTaskRequest, ToolRequest, TranslationRequest, UsageData, VisionRequest
│   ├── EventListener/  # InjectAjaxUrlsListenerTest.php
│   └── Service/        # ContextAssemblyService, DiagnosticService, LlmErrorClassifier,
│                       # RateLimiterService, RateLimitResult tests
├── Functional/         # Placeholder (.gitkeep) — suite wired in CI, no tests yet
├── Integration/
│   ├── AbstractIntegrationTestCase.php
│   └── Controller/     # Ajax, Template, Translation, Vision + Backend/Status integration tests
├── E2E/
│   ├── AbstractE2ETestCase.php, CowriterWorkflowTest.php, NewFeatureWorkflowTest.php  # PHP E2E
│   ├── *.spec.ts       # Playwright specs (toolbar, dialog, tasks, translate, vision, context zoom)
│   └── fixtures/       # Playwright auth fixture
├── JavaScript/         # Vitest: AIService, cowriter, CowriterDialog, UrlLoader (+ __mocks__/)
└── Support/            # TestQueryResult.php, TaskStubTrait.php
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

## Conventions (PHPUnit Attributes)

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

## Security

- Verify output handling in tests: HTML escaping/sanitization test cases are mandatory for anything that renders LLM output
- Never put real API keys or credentials in fixtures — mock `LlmServiceManagerInterface` (below); Playwright auth uses the local DDEV instance only

## Examples: Mocking nr-llm

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

## When Stuck

- **Suite won't start:** `runTests.sh` needs a running Docker/Podman daemon
- **Mock errors on final classes:** see "TYPO3 Final Class Workarounds" above
- **Playwright failures:** run headed (`npm run test:e2e:headed`) against the DDEV instance (`make up`)
- **Coverage attribution missing:** add `#[CoversClass]` for every exercised class

## Related

- **[../Classes/AGENTS.md](../Classes/AGENTS.md)** - Implementation details
- **[../Build/phpunit/](../Build/phpunit/)** - PHPUnit configurations
