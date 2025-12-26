# Classes/AGENTS.md

**Scope:** PHP backend components (AJAX Controllers, nr-llm integration)
**Parent:** [../AGENTS.md](../AGENTS.md)

## Overview

PHP backend implementation for t3_cowriter TYPO3 extension. This extension uses nr-llm for all LLM operations - no direct API calls.

### Controllers

- **AjaxController** - AJAX endpoints for CKEditor integration
  - `chatAction()` - Chat completions via nr-llm
  - `completeAction()` - Text completions via nr-llm

## Architecture Patterns

### TYPO3 Patterns

- **PSR-7 Request/Response:** HTTP message interfaces for controllers
- **Dependency Injection:** Constructor-based DI via Services.yaml
- **AJAX Routes:** Backend routes for authenticated API calls
- **nr-llm Integration:** LlmServiceManager for all LLM operations

### File Structure

```
Classes/
└── Controller/
    └── AjaxController.php    # AJAX endpoints
```

## Build & Tests

```bash
# PHP-specific quality checks
make lint                      # All linters
composer ci:test:php:lint      # PHP syntax check
composer ci:test:php:phpstan   # Static analysis
composer ci:test:php:rector    # Rector modernization check
composer ci:test:php:cgl       # Code style check

# Fixes
make format                    # Auto-fix code style
composer ci:php:cgl            # Alternative: fix style
composer ci:php:rector         # Apply Rector changes

# Tests (run in DDEV)
make test                      # Unit tests
```

## Code Style

### Required Patterns

**1. Strict Types (Always First)**
```php
<?php

declare(strict_types=1);
```

**2. Type Hints**
- All parameters must have type hints
- All return types must be declared
- Use nullable types `?Type` when appropriate

**3. Property Types**
```php
private readonly LlmServiceManager $llmServiceManager;
```

**4. Final Classes**
```php
final class AjaxController
```

## Security

### nr-llm Integration

- **Never call LLM APIs directly:** Use LlmServiceManager
- **Configuration:** API keys managed by nr-llm extension
- **Providers:** Supports OpenAI, Claude, Gemini, OpenRouter, Mistral, Groq

```php
// Good: Use nr-llm
$response = $this->llmServiceManager->chat($messages, $options);

// Bad: Direct API call
$response = file_get_contents('https://api.openai.com/...');
```

### Input Validation

- **Type cast request data:** `(string)($body['prompt'] ?? '')`
- **Validate before use:** Check required fields exist
- **Use JsonResponse:** For all API responses

## PR/Commit Checklist

### PHP-Specific Checks

1. **Strict types:** `declare(strict_types=1);` in all files
2. **Type hints:** All parameters and return types declared
3. **PHPStan:** Zero errors (`composer ci:test:php:phpstan`)
4. **Code style:** PSR-12/PER-CS2.0 compliant (`make format`)
5. **Rector:** No modernization suggestions
6. **DI pattern:** Constructor injection, no `new ClassName()`
7. **PSR-7:** Request/Response for controllers
8. **nr-llm:** Use LlmServiceManager, never direct API calls

## Good vs Bad Examples

### Good: Controller Pattern

```php
<?php

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\NrLlm\Service\LlmServiceManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

final class AjaxController
{
    public function __construct(
        private readonly LlmServiceManager $llmServiceManager,
    ) {}

    public function chatAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode($request->getBody()->getContents(), true);
        $messages = $body['messages'] ?? [];
        $options = $body['options'] ?? [];

        $response = $this->llmServiceManager->chat($messages, $options);

        return new JsonResponse($response);
    }
}
```

### Bad: Anti-patterns

```php
<?php
// Missing strict types
namespace Netresearch\T3Cowriter\Controller;

class AjaxController  // Not final
{
    // No constructor DI
    public function chatAction($request)  // Missing types
    {
        // Direct API call - NEVER do this
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_cowriter']['apiKey']
        ]);

        // Manual JSON encoding
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
}
```

## When Stuck

### Documentation

- **nr-llm API:** Check LlmServiceManager methods
- **TYPO3 AJAX:** https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Ajax/Index.html
- **DI:** https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/DependencyInjection/Index.html

### Common Issues

- **LlmServiceManager not found:** Check composer require and DI registration
- **AJAX route not working:** Verify Configuration/Backend/AjaxRoutes.php
- **PHPStan errors:** Update baseline: `composer ci:test:php:phpstan:baseline`
- **DI not working:** Check Configuration/Services.yaml

## House Rules

### Controllers

- **Final by default:** Use `final class` unless inheritance required
- **PSR-7 types:** ServerRequestInterface -> ResponseInterface
- **JSON responses:** Use `JsonResponse` class
- **Validation first:** Validate all input parameters at method start

### Dependencies

- **Constructor injection:** All dependencies via constructor
- **Readonly properties:** Use `readonly` for immutable dependencies
- **nr-llm:** Use LlmServiceManager for all LLM operations

### Error Handling

- **HTTP status codes:** Use appropriate status (400, 500, etc.)
- **Meaningful messages:** Include context in error responses
- **Type-safe:** Always decode JSON and validate structure

## Related

- **[../Resources/AGENTS.md](../Resources/AGENTS.md)** - JavaScript/CKEditor integration
- **[../Configuration/Backend/AjaxRoutes.php](../Configuration/Backend/AjaxRoutes.php)** - AJAX route definitions
- **[../Configuration/Services.yaml](../Configuration/Services.yaml)** - DI container configuration
