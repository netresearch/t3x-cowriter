<!-- FOR AI AGENTS - Human readability is a side effect, not a goal -->
<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Last updated: 2026-08-19 | Last verified: 2026-08-19 -->

# AGENTS.md

**Precedence:** The **closest AGENTS.md** to changed files wins. Root holds global defaults only.

## Project Overview

AI-powered content writing assistant for the TYPO3 CKEditor 5 RTE. Adds toolbar actions for chat/completion, vision (image alt text), translation, prompt templates, and LLM tool calling. All LLM traffic goes through the PHP backend via `netresearch/nr-llm` — never directly from the browser.

- **Package**: `netresearch/t3-cowriter` (Composer) / `t3_cowriter` (TER extension key)
- **Namespace**: `Netresearch\T3Cowriter\`
- **Tech Stack**: PHP ^8.2, TYPO3 ^13.4 || ^14.3, CKEditor 5, JavaScript ES modules
- **License**: GPL-3.0-or-later; version: see [ext_emconf.php](ext_emconf.php) (do not pin versions here)
- **Key dependency**: `netresearch/nr-llm` (LLM abstraction, provides `LlmServiceManagerInterface`)

## Architecture

Component map, AJAX route table, and data flow: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

Short form: CKEditor plugin (`Resources/Public/JavaScript/Ckeditor/`) → 12 backend AJAX routes ([Configuration/Backend/AjaxRoutes.php](Configuration/Backend/AjaxRoutes.php)) → controllers in `Classes/Controller/` → `LlmServiceManagerInterface` (nr-llm) → provider API. A backend status module (`cowriter_status`) surfaces `DiagnosticService` results for setup troubleshooting.

## Commands

```bash
composer install             # Install dependencies (vendor dir: .Build/vendor)

# Quality checks (same as CI)
composer ci:test             # lint + phpstan + rector + cgl
composer ci:test:php:lint    # PHP syntax check (phplint)
composer ci:test:php:phpstan # PHPStan (Build/phpstan.neon)
composer ci:test:php:rector  # Rector dry-run
composer ci:test:php:cgl     # Code style check
composer ci:cgl              # Auto-fix code style
composer ci:rector           # Apply Rector

# PHP tests (Docker-based via Build/Scripts/runTests.sh)
composer ci:test:php:unit        # Unit tests
composer ci:test:php:functional  # Functional tests
composer ci:test:php:integration # Integration tests
composer ci:test:php:e2e         # E2E tests (PHP)
composer ci:test:all             # unit + functional + integration + e2e

# JavaScript
npm run lint                 # ESLint on Resources/Public/JavaScript
npm test                     # Vitest unit tests (Tests/JavaScript/)
npm run test:e2e             # Playwright E2E specs (Tests/E2E/)

# Local TYPO3 instance (DDEV, manual testing only)
make up                      # Start DDEV + install TYPO3 v13/v14 + render docs + pull Ollama model
make urls                    # Show access URLs
```

**CI is authoritative** — verify fixes pass in GitHub Actions before merging. The matrix (PHP 8.2–8.5 × TYPO3 ^13.4/^14.3) runs via the shared reusable workflows in `netresearch/typo3-ci-workflows`.

## CI/CD Workflows

All files in `.github/workflows/` are thin callers of `netresearch/typo3-ci-workflows` and `netresearch/.github` reusables:

| File | Purpose |
|------|---------|
| `ci.yml` | Test matrix PHP 8.2–8.5 × TYPO3 ^13.4/^14.3 incl. functional tests, coverage upload |
| `checks.yml` | Security audit, gitleaks, zizmor, fuzz, license check |
| `testing.yml` | Extended testing (shared extended-testing reusable) |
| `docs.yml` | TYPO3 documentation rendering |
| `release.yml` | TYPO3 extension release + TER publish |
| `harness-verify.yml` | Agent-harness consistency check (`Build/Scripts/verify-harness.sh`) |
| `republish.yml`, `auto-merge-deps.yml`, `labeler.yml`, `community.yml`, `check-template-drift.yml` | Housekeeping |

## Code Style

- PSR-12 + PER-CS 2.0; `declare(strict_types=1);` in every PHP file; PHP 8.2 baseline (must stay compatible through 8.5)
- PHPStan level 10; refresh baseline only via `composer ci:test:php:phpstan:baseline`
- Prefer `final readonly` classes, constructor promotion, DI via [Configuration/Services.yaml](Configuration/Services.yaml)
- JavaScript: ES modules, CKEditor 5 plugin conventions, ESLint ([eslint.config.js](eslint.config.js)), no jQuery

## Security

- No API keys in the frontend — all LLM calls go through backend controllers and nr-llm
- AJAX routes require TYPO3 backend authentication; rate limiting via `RateLimiterService`
- LLM output is returned raw in JSON and sanitized on the frontend — do not escape it server-side (see [Classes/AGENTS.md](Classes/AGENTS.md))

## PR/Commit Checklist

1. `composer ci:test` green; tests updated and green (`composer ci:test:all`, `npm test`)
2. Conventional Commits: `type(scope): subject` — scopes: `backend`, `frontend`, `config`, `docs`, `build`, `ddev`
3. Signed commits with DCO sign-off required: `git commit -S --signoff` (see [CONTRIBUTING.md](CONTRIBUTING.md))
4. Update Documentation/ and CHANGELOG.md for user-facing changes; keep AGENTS.md in sync when commands or CI change

## Index of scoped AGENTS.md

- `./Classes/AGENTS.md` — PHP backend: controllers, DTOs, services, nr-llm integration
- `./Resources/AGENTS.md` — JavaScript/CKEditor 5 frontend, icons, backend templates
- `./Tests/AGENTS.md` — Test suites: unit, functional, integration, E2E, JavaScript

## When Instructions Conflict

Nearest AGENTS.md wins. Explicit user prompts override files.
