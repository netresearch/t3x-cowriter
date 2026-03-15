# AI Agent Development Guide

**Project:** t3_cowriter - AI-powered content writing assistant for TYPO3 CKEditor
**Last Updated:** 2026-03-15
**Type:** TYPO3 CMS Extension (PHP 8.2+ / JavaScript/ES6)
**TYPO3:** 13.4 LTS + 14.0
**License:** GPL-3.0-or-later

## Quick Start

```bash
# Install dependencies
composer install

# Development workflow
composer ci:test             # Run all linters + static analysis
composer ci:test:all         # Run all tests (unit, integration, e2e)
composer ci:cgl              # Fix code style
composer ci:rector           # Apply PHP modernization

# Local TYPO3 instance (optional, for manual testing only)
make up                      # Start DDEV + install TYPO3 v14
make urls                    # Show access URLs
```

## Setup

### Prerequisites

- **PHP:** 8.2+ (CI tests 8.2, 8.3, 8.4, 8.5) with extensions: dom, libxml
- **Composer:** Latest stable
- **TYPO3:** 13.4 LTS or 14.0+
- **DDEV:** For local development
- **Docker:** For DDEV and docs rendering

### Installation

```bash
# Clone and start
git clone https://github.com/netresearch/t3x-cowriter.git
cd t3x-cowriter
make up                      # Start DDEV + install all

# Or manually
ddev start
ddev install-v14
```

## Architecture

### Supported Version Matrix

| TYPO3 | PHP | Status |
|-------|-----|--------|
| 13.4 LTS | 8.2, 8.3, 8.4, 8.5 | Supported |
| 14.0 | 8.2, 8.3, 8.4, 8.5 | Supported |

### Component Overview

```
t3_cowriter/
├── Classes/
│   ├── Controller/
│   │   ├── AjaxController.php       # Main chat/complete/stream endpoints
│   │   ├── Backend/
│   │   │   └── StatusController.php # Setup status diagnostic page
│   │   ├── VisionController.php     # Image analysis (alt text)
│   │   ├── TranslationController.php # Content translation
│   │   ├── TemplateController.php   # Prompt template listing
│   │   └── ToolController.php       # LLM function calling
│   ├── Domain/DTO/                  # Request/response DTOs
│   ├── Service/
│   │   ├── DiagnosticService.php    # 8-step LLM config chain checker
│   │   ├── Dto/                     # Severity, DiagnosticCheck, DiagnosticResult
│   │   ├── ContextAssemblyService.php # Context building for tasks
│   │   ├── RateLimiterInterface.php # Rate limiter abstraction
│   │   └── RateLimiterService.php   # Sliding window rate limiter
│   └── Tools/ContentQueryTool.php   # Tool definition for TYPO3 queries
├── Configuration/
│   ├── Backend/
│   │   ├── AjaxRoutes.php           # 11 AJAX route definitions
│   │   └── Modules.php              # Backend module registration (cowriter_status)
│   ├── RTE/Cowriter.yaml            # CKEditor toolbar configuration
│   └── Services.yaml                # DI container
├── Resources/Public/JavaScript/Ckeditor/
│   ├── AIService.js                 # Frontend API client (all endpoints + AIServiceError)
│   ├── cowriter.js                  # CKEditor plugin (4 toolbar items)
│   ├── CowriterDialog.js            # Task dialog UI (incl. status link on errors)
│   └── UrlLoader.js                 # CSP-compliant URL injection
└── Documentation/                   # RST documentation
```

### Data Flow

```
CKEditor Toolbar
  ├─ cowriter         → CowriterDialog → AIService.js → AjaxController
  ├─ cowriterVision   → AIService.js ─────────────────→ VisionController
  ├─ cowriterTranslate→ AIService.js ─────────────────→ TranslationController
  ├─ cowriterTemplates→ AIService.js ─────────────────→ TemplateController
  └─ (tool calling)   → AIService.js ─────────────────→ ToolController
                                                              ↓
                                                   LlmServiceManagerInterface
                                                              ↓
                                                        nr-llm Provider → AI API
```

### AJAX Routes

| Route | Path | Controller | Purpose |
|-------|------|------------|---------|
| `tx_cowriter_chat` | `/cowriter/chat` | `AjaxController::chatAction` | Chat completion (stateless) |
| `tx_cowriter_complete` | `/cowriter/complete` | `AjaxController::completeAction` | Single prompt completion |
| `tx_cowriter_stream` | `/cowriter/stream` | `AjaxController::streamAction` | Streaming via SSE |
| `tx_cowriter_configurations` | `/cowriter/configurations` | `AjaxController::getConfigurationsAction` | List LLM configurations |
| `tx_cowriter_tasks` | `/cowriter/tasks` | `AjaxController::getTasksAction` | List available tasks |
| `tx_cowriter_task_execute` | `/cowriter/task-execute` | `AjaxController::executeTaskAction` | Execute a task |
| `tx_cowriter_context` | `/cowriter/context` | `AjaxController::getContextAction` | Context preview |
| `tx_cowriter_vision` | `/cowriter/vision` | `VisionController::analyzeAction` | Image alt text generation |
| `tx_cowriter_translate` | `/cowriter/translate` | `TranslationController::translateAction` | Content translation |
| `tx_cowriter_templates` | `/cowriter/templates` | `TemplateController::listAction` | Prompt template listing |
| `tx_cowriter_tools` | `/cowriter/tools` | `ToolController::executeAction` | LLM tool/function calling |

### Key Dependencies

- **netresearch/nr-llm:** Backend LLM abstraction layer
- **typo3/cms-rte-ckeditor:** CKEditor 5 integration

## Build & Test Commands

**CI is authoritative** - always verify fixes pass in GitHub Actions CI before merging.
Run tests locally via composer (same as CI), not via DDEV.

CI runs a **multi-version matrix**: PHP 8.2, 8.3, 8.4, 8.5 x TYPO3 ^13.4, ^14.0.
See `.github/workflows/ci.yml` for the full matrix definition.

### Quality Checks

```bash
composer ci:test:php:lint    # PHP syntax check
composer ci:test:php:phpstan # Static analysis (level 10)
composer ci:test:php:cgl     # Code style check
composer ci:test:php:rector  # PHP modernization check
composer ci:test             # All of the above
```

### Tests

```bash
composer ci:test:php:unit        # Unit tests
composer ci:test:php:integration # Integration tests
composer ci:test:php:e2e         # E2E tests
composer ci:test:all             # All tests
```

### Code Fixes

```bash
composer ci:cgl              # Auto-fix code style
composer ci:rector           # Apply PHP modernization
```

### Individual Commands

```bash
# PHP Linting
composer ci:test:php:lint

# Static Analysis
composer ci:test:php:phpstan

# Code Style Check
composer ci:test:php:cgl

# Code Style Fix
composer ci:cgl

# Rector (PHP Modernization)
composer ci:test:php:rector
composer ci:rector                  # Apply changes
```

## CI/CD Workflows

All workflows are in `.github/workflows/`. Key workflows:

| Workflow | File | Purpose |
|----------|------|---------|
| **CI** | `ci.yml` | Multi-version matrix: PHP 8.2-8.5 x TYPO3 v13.4/v14.0 (lint, phpstan, unit, functional, integration, e2e tests) |
| **PR Quality Gates** | `pr-quality.yml` | Auto-approve, Copilot review, merge readiness checks |
| **Release** | `release.yml` | SBOM generation, Cosign signing, GitHub Release creation, TER publish |
| **Security** | `security.yml` | Dependency audit, Trivy scanning, security analysis |
| **CodeQL** | `codeql.yml` | GitHub code scanning for vulnerabilities |
| **Dependency Review** | `dependency-review.yml` | Review new dependencies in PRs |
| **Scorecard** | `scorecard.yml` | OpenSSF Scorecard supply-chain security |
| **Publish to TER** | `publish-to-ter.yml` | TYPO3 Extension Repository publishing |
| **Auto-merge Deps** | `auto-merge-deps.yml` | Auto-merge Renovate dependency updates |

## Code Style

### PHP Standards

- **Base:** PSR-12 + PER-CS 2.0
- **Strict types:** Required in all files (`declare(strict_types=1);`)
- **PHP version:** 8.2+ baseline (code must be compatible with PHP 8.2 through 8.5)
- **Config:** `Build/.php-cs-fixer.dist.php`

### Key Rules

```php
<?php

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\JsonResponse;

final readonly class AjaxController
{
    public function __construct(
        private LlmServiceManagerInterface $llmServiceManager,
        private LlmConfigurationRepository $configurationRepository,
        private RateLimiterInterface $rateLimiter,
        private Context $context,
        private LoggerInterface $logger,
    ) {}

    public function chatAction(ServerRequestInterface $request): ResponseInterface
    {
        // Implementation...
        return new JsonResponse($response);
    }
}
```

### JavaScript Standards

- **ES6 modules:** CKEditor 5 plugin format
- **TYPO3 AJAX:** Use TYPO3.settings.ajaxUrls
- **Style:** Follow CKEditor 5 conventions
- **Location:** `Resources/Public/JavaScript/Ckeditor/`

## Security

- **No API keys in frontend:** All LLM calls via backend nr-llm
- **AJAX routes:** Protected by TYPO3 backend authentication
- **Input validation:** Type-cast all request parameters
- **XSS prevention:** JsonResponse for API output
- **Static analysis:** PHPStan level 10

## PR/Commit Checklist

Before committing:

1. **Lint passed:** `make lint`
2. **Style fixed:** `make cgl-fix`
3. **Tests pass:** `make test`
4. **Static analysis:** No new PHPStan errors
5. **Rector check:** No modernization suggestions
6. **Docs updated:** Update relevant docs if API changed
7. **CHANGELOG:** Add entry if user-facing change
8. **Conventional Commits:** Use format: `type(scope): message`

### Commit Format

```
<type>(<scope>): <subject>

[optional body]
```

**Types:** `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`
**Scopes:** `backend`, `frontend`, `config`, `docs`, `build`, `ddev`

**Examples:**
```
feat(backend): add streaming support for chat responses
fix(frontend): handle empty AI responses gracefully
docs(api): update AjaxController endpoint documentation
```

## Good vs Bad Examples

### Good: TYPO3 AJAX Controller

```php
<?php

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

final readonly class AjaxController
{
    public function __construct(
        private LlmServiceManagerInterface $llmServiceManager,
    ) {}

    public function chatAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode($request->getBody()->getContents(), true);
        $messages = $body['messages'] ?? [];

        $response = $this->llmServiceManager->chat($messages);

        return new JsonResponse($response);
    }
}
```

### Bad: Direct API calls from frontend

```javascript
// DON'T: Exposing API keys to browser
const response = await fetch('https://api.openai.com/v1/chat', {
    headers: { 'Authorization': `Bearer ${apiKey}` }  // API key in frontend!
});
```

### Good: Use TYPO3 AJAX routes

```javascript
// DO: Use TYPO3 AJAX routes
const response = await fetch(TYPO3.settings.ajaxUrls.tx_cowriter_chat, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ messages }),
});
```

## When Stuck

1. **Architecture:** Check nr-llm repository for LLM integration patterns
2. **CKEditor 5:** See TYPO3 rte_ckeditor documentation; plugin registered in `Configuration/RTE/Cowriter.yaml`
3. **AJAX Routes:** Check `Configuration/Backend/AjaxRoutes.php`
4. **DI Issues:** Verify `Configuration/Services.yaml` registration
5. **Version compatibility:** Code must work on both TYPO3 v13.4 and v14.0; check CI matrix in `.github/workflows/ci.yml`

### Common Issues

- **LLM not responding:** Check nr-llm extension configuration
- **AJAX 403 error:** User not logged into backend
- **PHPStan errors:** Update baseline: `composer ci:test:php:phpstan:baseline`
- **Code style fails:** Run `make cgl-fix` to auto-fix

## House Rules

### Design Principles

- **Security first:** No API keys in frontend, all calls via backend
- **SOLID:** Single responsibility, dependency injection
- **KISS:** Keep it simple
- **Composition > Inheritance:** Prefer composition

### Dependencies

- **Supported versions:** TYPO3 13.4 LTS + 14.0, PHP 8.2+
- **Renovate:** Auto-updates enabled
- **nr-llm:** Primary LLM abstraction layer

### API & Versioning

- **SemVer:** Semantic versioning (MAJOR.MINOR.PATCH)
- **Breaking changes:** Increment major version
- **Deprecations:** Add `@deprecated` tag + removal plan

## Related Files

**Directory-specific guides:**
- **[Classes/AGENTS.md](Classes/AGENTS.md)** - PHP backend components
- **[Resources/AGENTS.md](Resources/AGENTS.md)** - JavaScript/CKEditor integration
- **[Tests/AGENTS.md](Tests/AGENTS.md)** - Test suite structure

**Configuration:**
- **[composer.json](composer.json)** - Dependencies & scripts (PHP ^8.2, TYPO3 ^13.4 || ^14.0)
- **[Configuration/RTE/Cowriter.yaml](Configuration/RTE/Cowriter.yaml)** - CKEditor plugin registration
- **[Build/](Build/)** - Development tools configuration

**CI/CD:**
- **[.github/workflows/](/.github/workflows/)** - GitHub Actions workflows

**Documentation:**
- **[Documentation/](Documentation/)** - RST documentation
- **[README.md](README.md)** - Project overview

## Additional Resources

- **Repository:** https://github.com/netresearch/t3x-cowriter
- **nr-llm:** https://github.com/netresearch/t3x-nr-llm
- **TYPO3 Docs:** https://docs.typo3.org/
- **CKEditor 5:** https://ckeditor.com/docs/ckeditor5/
