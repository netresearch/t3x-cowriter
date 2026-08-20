<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-08-19 -->

# AGENTS.md - Classes (PHP Backend)

**Scope:** PHP backend components for t3_cowriter TYPO3 extension
**Parent:** [../AGENTS.md](../AGENTS.md)

## Overview

PHP source of the extension, PSR-4 autoloaded under `Netresearch\T3Cowriter\`. Controllers answer the backend AJAX routes defined in `../Configuration/Backend/AjaxRoutes.php`, DTOs parse and validate request/response payloads, and services wrap diagnostics, context assembly, rate limiting, and LLM error classification. All LLM calls go through nr-llm's `LlmServiceManagerInterface`.

## Setup

```bash
composer install     # Installs to .Build/vendor (bin-dir .Build/bin)
```

Dependency injection is configured in `../Configuration/Services.yaml`; new services are picked up automatically via autowiring, interfaces need an explicit alias there.

## Key Files

| File | Purpose |
|------|---------|
| Controller/AjaxController.php | AJAX handler for CKEditor integration |
| Controller/Backend/StatusController.php | Setup status diagnostic page (admin only) |
| Controller/RateLimitedControllerTrait.php | Shared rate-limit check for controllers |
| Controller/TranslationController.php | Content translation endpoint |
| Controller/VisionController.php | Image analysis (alt text) |
| Controller/TemplateController.php | Prompt template listing |
| Controller/ToolController.php | LLM function calling |
| Domain/DTO/CompleteRequest.php | Request DTO with validation |
| Domain/DTO/CompleteResponse.php | Response DTO with HTML escaping |
| Domain/DTO/ContextRequest.php | Context preview request DTO |
| Domain/DTO/ExecuteTaskRequest.php | Task execution request DTO |
| Domain/DTO/PageSearchResult.php | Page search result DTO (tx_cowriter_page_search) |
| Domain/DTO/ToolRequest.php | Tool/function calling request DTO |
| Domain/DTO/TranslationRequest.php | Translation request DTO |
| Domain/DTO/UsageData.php | Token usage statistics |
| Domain/DTO/VisionRequest.php | Vision/alt-text request DTO |
| EventListener/InjectAjaxUrlsListener.php | AJAX URL injection for frontend |
| Service/CallerSource.php | Extension key + pipeline metadata naming this extension to nr-llm telemetry |
| Service/DiagnosticService.php | 8-step LLM config chain checker |
| Service/Dto/Severity.php | Check severity enum (Ok/Warning/Error) |
| Service/Dto/DiagnosticCheck.php | Individual check result DTO |
| Service/Dto/DiagnosticResult.php | Aggregate diagnostic result |
| Service/ContextAssemblyService.php | Context assembly for task execution (`ContextAssemblyServiceInterface`) |
| Service/LlmErrorClassifier.php | Classifies provider failures (`LlmErrorKind` enum) |
| Service/RateLimiterInterface.php | Rate limiter abstraction for DI |
| Service/RateLimiterService.php | Sliding window rate limiter implementation |
| Service/RateLimitResult.php | Rate limit check result DTO |

## PHP 8.2+ Patterns (REQUIRED)

```php
// Readonly classes
final readonly class CompleteRequest { }

// Constants (untyped for PHP 8.2 compatibility)
private const SYSTEM_PROMPT = '...';

// Constructor promotion with DI
public function __construct(
    private readonly LlmServiceManagerInterface $llmServiceManager,
) {}
```

## Security (CRITICAL)

```php
// LLM output is returned RAW in JSON — do NOT htmlspecialchars() it server-side.
// The frontend sanitizes it via a DOMParser-based pipeline before it enters the
// editor; escaping here would double-encode and break that contract.
content: $response->content,
```

## nr-llm Integration

```php
// Use LlmServiceManagerInterface for all LLM operations. Resolve a configuration
// and pass the backend user id as budget metadata so per-user nr-llm
// BudgetMiddleware enforcement applies to the call, plus the caller identity so
// the Analytics module attributes the call to this extension.
$configuration = $this->configurationRepository->findDefault();
$metadata      = [BudgetMiddleware::METADATA_BE_USER_UID => $beUserUid]
    + CallerSource::metadata('chat');
$response      = $this->llmServiceManager->chatWithConfiguration($messages, $configuration, $metadata);
```

Calls that take an options object instead of a metadata array (vision,
translation, tool loop) tag the identity with
`AbstractOptions::withCallerSource(CallerSource::EXTENSION, '<operation>')`. The
operation is the editor action, named per call site — never one constant for the
whole extension.

### Response Handling

```php
// Handle errors gracefully with diagnostic messages
try {
    $response = $this->llmServiceManager->chatWithConfiguration($messages, $configuration, $metadata);
    return CompleteResponse::success($response);
} catch (ProviderException $e) {
    // enrichErrorMessage classifies the failure via LlmErrorClassifier and adds
    // a Setup-Status link for configuration errors.
    return $this->buildErrorResponse('LLM provider error occurred.', $e);
} catch (\Throwable $e) {
    return $this->buildErrorResponse('An unexpected error occurred.', $e);
}
```

### Diagnostic Integration

Controllers use `DiagnosticService::runFirst()` to provide specific error messages when LLM configuration is incomplete. Always wrap in try/catch since it runs inside an exception handler:

```php
if ($this->isConfigurationError($exception)) {
    try {
        $failure = $this->diagnosticService->runFirst()->getFirstFailure();
    } catch (\Throwable) {
        $failure = null;
    }
    // Use $failure->message for specific guidance
}
```

## Build & Tests

```bash
composer ci:test:php:unit    # Unit tests
composer ci:test:php:phpstan # PHPStan level 10
composer ci:test             # Full quality suite (lint + phpstan + rector + cgl)
make test-coverage           # Coverage report (unit + integration, needs DDEV)
```

## Code Style

### Required Patterns

**1. Strict Types (Always First)**
```php
<?php

declare(strict_types=1);
```

**2. Final Readonly Classes for DTOs**
```php
final readonly class CompleteRequest
{
    public function __construct(
        public string $prompt,
        public ?string $configuration,
        public ?string $modelOverride,
    ) {}
}
```

**3. Static Factory Methods**
```php
public static function fromRequest(ServerRequestInterface $request): self
{
    // Parse and validate
    return new self(...);
}

public static function success(CompletionResponse $response): self
{
    return new self(
        success: true,
        content: htmlspecialchars($response->content, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        // ...
    );
}
```

## PR/Commit Checklist

1. **PHPStan level 10:** Zero errors
2. **Output contract:** LLM output returned raw, sanitized on the frontend (no server-side escaping)
3. **Exception handling:** ProviderException, RateLimitResult handling
4. **Type safety:** All properties typed
5. **Tests first:** TDD approach

## Good vs Bad Examples

### Good: Proper Response Handling

```php
public function completeAction(ServerRequestInterface $request): ResponseInterface
{
    $rateLimitResult = $this->checkRateLimit();
    if (!$rateLimitResult->allowed) {
        return $this->rateLimitedResponse($rateLimitResult);
    }

    $dto = CompleteRequest::fromRequest($request);
    if (!$dto->isValid()) {
        return new JsonResponse(
            CompleteResponse::error('No prompt provided')->jsonSerialize(),
            400
        );
    }

    try {
        $response = $this->llmServiceManager->chatWithConfiguration($messages, $configuration);
        return new JsonResponse(
            CompleteResponse::success($response)->jsonSerialize()
        );
    } catch (ProviderException $e) {
        return new JsonResponse(
            CompleteResponse::error('LLM provider error occurred')->jsonSerialize(),
            500
        );
    }
}
```

### Bad: Missing Error Handling

```php
// DON'T: no rate limiting, no input validation, no error handling
public function completeAction($request)
{
    $body = json_decode($request->getBody()->getContents(), true);
    $response = $this->llmServiceManager->chatWithConfiguration($body['messages'], $configuration);
    return new JsonResponse(['content' => $response->content]); // unhandled ProviderException, no rate limit
}
```

## When Stuck

1. **AJAX routing:** Routes live in `../Configuration/Backend/AjaxRoutes.php`; a 403 usually means no backend login
2. **DI failures:** Check `../Configuration/Services.yaml` (interface aliases are explicit)
3. **LLM behavior:** Read the nr-llm extension (https://github.com/netresearch/t3x-nr-llm) — configuration chain is provider → model → configuration
4. **Setup errors:** `DiagnosticService::runFirst()` mirrors the backend status module (`cowriter_status`) and names the first broken step

## Related

- **[../Tests/AGENTS.md](../Tests/AGENTS.md)** - Test requirements
- **[../Configuration/Backend/AjaxRoutes.php](../Configuration/Backend/AjaxRoutes.php)** - AJAX routes
- **[../Configuration/Services.yaml](../Configuration/Services.yaml)** - DI configuration
