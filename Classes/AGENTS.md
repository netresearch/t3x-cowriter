# AGENTS.md - Classes (PHP Backend)

**Scope:** PHP backend components for t3_cowriter TYPO3 extension
**Parent:** [../AGENTS.md](../AGENTS.md)

## Files to Create/Maintain

| File | Purpose |
|------|---------|
| Controller/AjaxController.php | AJAX handler for CKEditor integration |
| Controller/Backend/StatusController.php | Setup status diagnostic page (admin only) |
| Controller/TranslationController.php | Content translation endpoint |
| Controller/VisionController.php | Image analysis (alt text) |
| Controller/TemplateController.php | Prompt template listing |
| Controller/ToolController.php | LLM function calling |
| Domain/DTO/CompleteRequest.php | Request DTO with validation |
| Domain/DTO/CompleteResponse.php | Response DTO with HTML escaping |
| Domain/DTO/ExecuteTaskRequest.php | Task execution request DTO |
| Domain/DTO/TranslationRequest.php | Translation request DTO |
| Domain/DTO/UsageData.php | Token usage statistics |
| EventListener/InjectAjaxUrlsListener.php | AJAX URL injection for frontend |
| Service/DiagnosticService.php | 8-step LLM config chain checker |
| Service/Dto/Severity.php | Check severity enum (Ok/Warning/Error) |
| Service/Dto/DiagnosticCheck.php | Individual check result DTO |
| Service/Dto/DiagnosticResult.php | Aggregate diagnostic result |
| Service/ContextAssemblyService.php | Context assembly for task execution |
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
// ALWAYS escape LLM output to prevent XSS
content: htmlspecialchars($response->content, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
```

## nr-llm Integration

```php
// Use LlmServiceManagerInterface for all LLM operations
$configuration = $this->configurationRepository->findDefault();
$options = $configuration->toChatOptions();
$response = $this->llmServiceManager->chat($messages, $options);
```

### Response Handling

```php
// Handle errors gracefully with diagnostic messages
try {
    $response = $this->llmServiceManager->chat($messages, $options);
    return CompleteResponse::success($response);
} catch (ProviderException $e) {
    // enrichErrorMessage checks DiagnosticService for specific failures
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
# Unit tests (via make -> composer)
make test-unit

# With coverage
make test-coverage

# PHPStan level 10
make phpstan
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
2. **HTML escaping:** All LLM output escaped
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
        $response = $this->llmServiceManager->chat($messages, $options);
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
// DON'T: No error handling, no HTML escaping
public function completeAction($request)
{
    $body = json_decode($request->getBody()->getContents(), true);
    $response = $this->llmServiceManager->chat($body['messages']);
    return new JsonResponse(['content' => $response->content]); // XSS risk!
}
```

## Related

- **[../Tests/AGENTS.md](../Tests/AGENTS.md)** - Test requirements
- **[../Configuration/Backend/AjaxRoutes.php](../Configuration/Backend/AjaxRoutes.php)** - AJAX routes
- **[../Configuration/Services.yaml](../Configuration/Services.yaml)** - DI configuration
