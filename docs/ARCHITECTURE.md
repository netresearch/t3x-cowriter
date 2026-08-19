# Architecture

Agent-facing component map. For user documentation see `Documentation/` (RST, rendered to docs.typo3.org).

## System Overview

t3_cowriter integrates AI assistance into the TYPO3 CKEditor 5 RTE. The frontend plugin (registered as RTE preset `cowriter` in `ext_localconf.php` → `Configuration/RTE/Cowriter.yaml`) calls backend AJAX routes; controllers delegate every LLM call to the `netresearch/nr-llm` extension via `LlmServiceManagerInterface`, so no API keys ever reach the browser. A backend module (`cowriter_status`) runs an 8-step diagnostic chain to troubleshoot the LLM configuration.

## Components

| Component | Path | Responsibility |
|-----------|------|----------------|
| CKEditor plugin | `Resources/Public/JavaScript/Ckeditor/cowriter.js` | Toolbar items, editor integration |
| Task dialog | `Resources/Public/JavaScript/Ckeditor/CowriterDialog.js` | Task dialog UI, status link on errors |
| API client | `Resources/Public/JavaScript/Ckeditor/AIService.js` | Fetch wrapper for all AJAX routes, `AIServiceError` |
| URL loader | `Resources/Public/JavaScript/Ckeditor/UrlLoader.js` | CSP-compliant AJAX URL injection |
| AJAX routes | `Configuration/Backend/AjaxRoutes.php` | 12 route definitions (see table below) |
| Main controller | `Classes/Controller/AjaxController.php` | Chat, complete, stream (SSE), configurations, tasks, task execution, context, page search |
| Vision controller | `Classes/Controller/VisionController.php` | Image analysis / alt-text generation |
| Translation controller | `Classes/Controller/TranslationController.php` | Content translation |
| Template controller | `Classes/Controller/TemplateController.php` | Prompt template listing |
| Tool controller | `Classes/Controller/ToolController.php` | LLM tool/function calling |
| Status module | `Classes/Controller/Backend/StatusController.php`, `Configuration/Backend/Modules.php` | Setup diagnostics page (`cowriter_status`) |
| Diagnostics | `Classes/Service/DiagnosticService.php` | 8-step config chain check (provider → model → configuration) |
| Context assembly | `Classes/Service/ContextAssemblyService.php` | Builds page/content context for task execution |
| Rate limiting | `Classes/Service/RateLimiterService.php`, `Classes/Controller/RateLimitedControllerTrait.php` | Sliding-window limiter backed by the `cowriter_ratelimit` cache (`ext_localconf.php`) |
| Error classification | `Classes/Service/LlmErrorClassifier.php` | Maps provider failures to `LlmErrorKind` for user-facing messages |
| DTOs | `Classes/Domain/DTO/` | Request parsing/validation and response shaping per endpoint |
| DI configuration | `Configuration/Services.yaml` | Autowiring, interface aliases, public controller services |

## AJAX Routes

| Route | Target |
|-------|--------|
| `tx_cowriter_chat` | `AjaxController::chatAction` |
| `tx_cowriter_complete` | `AjaxController::completeAction` |
| `tx_cowriter_stream` | `AjaxController::streamAction` (SSE) |
| `tx_cowriter_configurations` | `AjaxController::getConfigurationsAction` |
| `tx_cowriter_tasks` | `AjaxController::getTasksAction` |
| `tx_cowriter_task_execute` | `AjaxController::executeTaskAction` |
| `tx_cowriter_context` | `AjaxController::getContextAction` |
| `tx_cowriter_page_search` | `AjaxController::searchPagesAction` |
| `tx_cowriter_vision` | `VisionController::analyzeAction` |
| `tx_cowriter_translate` | `TranslationController::translateAction` |
| `tx_cowriter_templates` | `TemplateController::listAction` |
| `tx_cowriter_tools` | `ToolController::executeAction` |

## Data Flow

```
CKEditor Toolbar
  ├─ cowriter          → CowriterDialog → AIService.js → AjaxController
  ├─ cowriterVision    → AIService.js ─────────────────→ VisionController
  ├─ cowriterTranslate → AIService.js ─────────────────→ TranslationController
  └─ cowriterTemplates → AIService.js ─────────────────→ TemplateController
                                                               ↓
                                                    LlmServiceManagerInterface
                                                          (nr-llm)
                                                               ↓
                                                     Provider → AI API
```

Controllers rate-limit first (`RateLimitedControllerTrait`), parse the request into a DTO, resolve the LLM configuration, and return `JsonResponse`. LLM output is returned raw in JSON; the frontend sanitizes it before it enters the editor (see `Classes/AGENTS.md` — do not escape server-side).

## Key Decisions

- **No frontend LLM access**: all provider credentials and calls live in nr-llm behind backend authentication (`Classes/AGENTS.md`, root `AGENTS.md` Security section).
- **Rate-limit cache via CacheManager, not DI**: extension caches are not available as DI services during container compilation; see the comment in `ext_localconf.php`.
- **Raw LLM output contract**: server returns unescaped content, frontend owns sanitization — documented in `Classes/AGENTS.md`.
- **CI via shared reusables**: all workflows delegate to `netresearch/typo3-ci-workflows` / `netresearch/.github`; the per-repo matrix lives in `.github/workflows/ci.yml`.
