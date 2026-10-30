<!-- SPDX-License-Identifier: GPL-3.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Architecture

Agent-facing component map. For user documentation see `Documentation/` (RST, rendered to docs.typo3.org).

## System Overview

t3_cowriter integrates AI assistance into the TYPO3 CKEditor 5 RTE. The frontend plugin (registered as RTE preset `cowriter` in `ext_localconf.php` → `Configuration/RTE/Cowriter.yaml`) calls backend AJAX routes; controllers delegate every LLM call to the `netresearch/nr-llm` extension via `LlmServiceManagerInterface`, so no API keys ever reach the browser. A backend module (`cowriter_status`) runs an 8-step diagnostic chain to troubleshoot the LLM configuration.

## Components

| Component | Path | Responsibility |
|-----------|------|----------------|
| CKEditor plugin | `Resources/Public/JavaScript/Ckeditor/cowriter.js` | Toolbar items, editor integration |
| Task dialog | `Resources/Public/JavaScript/Ckeditor/CowriterDialog.js` | Task dialog UI, status link on errors |
| HTML sanitiser | `Resources/Public/JavaScript/Ckeditor/HtmlSanitizer.js` | Allow-list for model output shown as markup or inserted (dialog, translation) |
| API client | `Resources/Public/JavaScript/Ckeditor/AIService.js` | Fetch wrapper for all AJAX routes, `AIServiceError` |
| URL loader | `Resources/Public/JavaScript/Ckeditor/UrlLoader.js` | CSP-compliant AJAX URL injection |
| Field suggestions (JS) | `Resources/Public/JavaScript/FormEngine/FieldSuggestions.js` | FormEngine field control: suggestion list, pick fills the field |
| Alternative presentation (JS) | `Resources/Public/JavaScript/FormEngine/AlternativePresentation.js` | FormEngine field control on the rich text of a text element: proposal with editable section titles, layout choice, creation |
| AJAX routes | `Configuration/Backend/AjaxRoutes.php` | 26 route definitions (see table below) |
| Main controller | `Classes/Controller/AjaxController.php` | Chat, complete, stream (SSE), configurations, style options, tasks, task execution (plain and SSE), context, page search |
| Vision controller | `Classes/Controller/VisionController.php`, `Classes/Service/Vision/` | Image analysis / alt-text generation; the instruction from `altText.prompt` plus the edited record's language, resolved server-side with read checks (`RecordLanguageResolver`) |
| Translation controller | `Classes/Controller/TranslationController.php` | Content translation |
| Image generation | `Classes/Controller/ImageGenerationController.php`, `Classes/Service/Image/`, `Classes/EventListener/RemoveDisabledImageButtonListener.php`, `Resources/Public/JavaScript/Ckeditor/ImageDialog.js` | "Image by prompt": nr-llm's `DallEImageService` generates the image (settings `image.enable`, `image.model`), `GeneratedImageStorage` stores the returned bytes in the editor's default upload folder through FAL with the editor's permissions; the plugin inserts it as the `typo3image` element of rte-ckeditor-image. The toolbar button is removed while the setting is off |
| Inline suggestions | `Classes/Controller/InlineSuggestionController.php`, `Classes/Service/InlineSuggestion/`, `Classes/EventListener/InlineSuggestionEditorListener.php`, `Resources/Public/JavaScript/Ckeditor/InlineSuggestions.js` | A short continuation of the text before the cursor while typing (settings `inlineSuggestions.*`): nr-llm's `CompletionService` with a small token limit, the answer stripped to one line of plain text (`SuggestionText`); a rate-limit window of its own (`cowriter.rateLimiter.inlineSuggestions`, 12/min). The plugin shows it as a UIElement of a collapsed marker in the editing view only (never in `getData()`), Tab inserts it. The listener hands the settings to the plugin as `cowriter.inlineSuggestions` and removes the toggle while the setting is off |
| Template controller | `Classes/Controller/TemplateController.php` | Prompt template listing |
| Tool controller | `Classes/Controller/ToolController.php` | LLM tool/function calling |
| Saved prompt controller | `Classes/Controller/SavedPromptController.php`, `Classes/Service/Prompt/` | List, save and delete the editor's saved prompts (`tx_cowriter_prompt`), sharing with optional admin approval |
| Field suggestion controller | `Classes/Controller/FieldSuggestionController.php` | N suggestions for one form field (permission check, structured LLM call) |
| Field overview | `Classes/Controller/FieldOverviewController.php`, `Classes/Service/FieldSuggestion/FieldOverviewService.php`, `Classes/EventListener/AddFieldOverviewButtonListener.php`, `Resources/Public/JavaScript/FormEngine/FieldOverview.js` | "Suggest values for all fields": a hidden document-header button on the record form (ModifyButtonBarEvent) that `FieldSuggestions.js` reveals when a suggestion control is rendered; one structured LLM call returns per field a value or "keep" with a reason (entries checked one by one, values normalised to the field limit); the modal applies the checked values client-side (`FieldValue.js`) |
| Budget status controller | `Classes/Controller/BudgetStatusController.php` | The session user's own usage today and this month against their nr-llm budget (`UserBudgetStatusService`), for the dialog's "Your AI usage" table |
| Budget refusal | `Classes/Service/BudgetRefusal.php` | Answers a call nr-llm's budget refused (`BudgetExceededException`, or a tool loop ended with `BUDGET_EXHAUSTED`) with 429, `budgetExceeded: true` and a localised message naming the limit |
| Field suggestion services | `Classes/Service/FieldSuggestion/` | Record/page context with permission checks (workspace-restricted and overlaid): the record's fields, the page's descriptive fields by name (`RecordContextReader::PAGE_FIELDS`), the page content, the parent and up to 5 neighbouring page titles (`RecordFinder::findVisibleSubpages()`, `findPageTranslation()`); `ContextComposer` writes it fenced (`UntrustedDataFence`) within 16,000 characters and counts the sources that survived the cut; system prompt + JSON schema, length limits, slug building via `SlugHelper` |
| "About this suggestion" | `Classes/Service/Transparency/`, `Resources/Public/JavaScript/Ckeditor/AboutSection.js` | `ModelUse` (configuration + the model and provider nr-llm reports in its response), `ContextSources` (names and counts of what was sent), `AboutBuilder` (the `about` object of the suggestion, overview, presentation and image routes: origin, configuration, model, sources, current settings, links gated by module access and admin); `AboutSection.js` renders it as a collapsed `<details>` in each dialog |
| Image context | `Classes/Controller/ImageContextController.php`, `Classes/Service/Image/ImagePurpose.php` | Read-only route for the image dialog: what the image is for and a description proposal built from the record and its page (no model call, not rate-limited), plus the `about` object with the model the generation will use |
| Translation languages | `Classes/EventListener/InjectTranslationLanguagesListener.php`, `Classes/Service/SiteTranslationLanguages.php`, `Configuration/SiteConfiguration/Overrides/site_language.php` | Puts the languages of the record's site (also those hidden from the frontend), with their `cowriterFormality`, into the CKEditor configuration (`AfterPrepareConfigurationForEditorEvent`, `cowriter.translationLanguages`); adds the formality field to the Sites module |
| Alternative presentation | `Classes/Controller/PresentationController.php`, `Classes/Service/Presentation/`, `Classes/EventListener/RegisterPresentationControlListener.php`, `Classes/Form/FieldControl/PresentationControl.php` | "Present as tabs or accordion" (setting `presentation.enable`): `BlockParser` cuts the stored bodytext of a text element into top-level blocks (masterminds/html5), `SectionPlanner` cuts it at its h2 (else h3) headings without a model call, or `SectionProposalService` asks the model for start blocks and titles only. `PresentationAccess` decides which of bootstrap_package's `tab`/`accordion` elements the editor may create (CType allow/deny, page TSconfig removeItems/keepItems, item tables, excluded fields, language, page permission), `PresentationWriter` creates the element hidden after the original, with its items, the original's access and appearance fields and copies of its images in the first item, through DataHandler as the editor, reads the result back and undoes an incomplete creation (and logs when the undo itself fails). `PresentationSwitcher` shows the new element and hides the original in one DataHandler run, only for the pair named by the `PresentationSwitchToken` (HMAC over both uids, user and workspace) the creation returned, and never for an original with translations or a connected translation, because the frontend overlays translations on the default-language row; `ContentLanguageLabel` names the introduction in the element's site language. The control goes into the `columnsOverrides` of the text types after TCA compilation, only when bootstrap_package's TCA is there |
| Field control registration | `Classes/EventListener/RegisterFieldSuggestionControlsListener.php`, `Classes/Form/FieldControl/FieldSuggestionsControl.php` | Adds the control to the configured TCA fields (`AfterTcaCompilationEvent`, `ext_conf_template.txt`) and renders the button |
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
| `tx_cowriter_style_options` | `AjaxController::getStyleOptionsAction` |
| `tx_cowriter_tasks` | `AjaxController::getTasksAction` |
| `tx_cowriter_task_execute` | `AjaxController::executeTaskAction` |
| `tx_cowriter_task_stream` | `AjaxController::executeTaskStreamAction` (SSE) |
| `tx_cowriter_context` | `AjaxController::getContextAction` |
| `tx_cowriter_page_search` | `AjaxController::searchPagesAction` |
| `tx_cowriter_vision` | `VisionController::analyzeAction` |
| `tx_cowriter_translate` | `TranslationController::translateAction` |
| `tx_cowriter_templates` | `TemplateController::listAction` |
| `tx_cowriter_prompts` | `SavedPromptController::listAction` |
| `tx_cowriter_prompt_save` | `SavedPromptController::saveAction` (POST) |
| `tx_cowriter_prompt_delete` | `SavedPromptController::deleteAction` (POST) |
| `tx_cowriter_tools` | `ToolController::executeAction` |
| `tx_cowriter_suggestions` | `FieldSuggestionController::suggestAction` |
| `tx_cowriter_suggestions_all` | `FieldOverviewController::suggestAllAction` |
| `tx_cowriter_budget_status` | `BudgetStatusController::statusAction` (GET) |
| `tx_cowriter_image_generate` | `ImageGenerationController::generateAction` (POST) |
| `tx_cowriter_image_context` | `ImageContextController::contextAction` (GET) |
| `tx_cowriter_inline_suggestion` | `InlineSuggestionController::suggestAction` (POST) |
| `tx_cowriter_presentation_propose` | `PresentationController::proposeAction` (POST) |
| `tx_cowriter_presentation_create` | `PresentationController::createAction` (POST) |
| `tx_cowriter_presentation_switch` | `PresentationController::switchAction` (POST) |

## Data Flow

```
CKEditor Toolbar
  ├─ cowriter          → CowriterDialog → AIService.js → AjaxController
  ├─ cowriterVision    → AIService.js ─────────────────→ VisionController
  ├─ cowriterTranslate → AIService.js ─────────────────→ TranslationController
  ├─ cowriterTemplates → AIService.js ─────────────────→ TemplateController
  ├─ cowriterImage     → ImageDialog → AIService.js ───→ ImageGenerationController → DallEImageService (nr-llm), FAL
  └─ (typing pause)    → InlineSuggestions → AIService.js → InlineSuggestionController → CompletionService (nr-llm)
FormEngine field control
  ├─ FieldSuggestions.js → AjaxRequest ────────────────→ FieldSuggestionController
  └─ AlternativePresentation.js → AjaxRequest ─────────→ PresentationController → CompletionService (nr-llm, only without headings), DataHandler
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
- **Translation languages travel in the editor configuration, not an AJAX route**: FormEngine resolves the record's site before the RTE renders, so each editor instance carries its own site's languages without a request or a separate access check. Records without a site (pid 0) get no list and the plugin keeps its fixed one.
- **Field suggestions are registered after TCA compilation**: `AfterTcaCompilationEvent` sees fields of other extensions (`pages.seo_title` from EXT:seo) regardless of load order, and the TCA registration is the server's allow-list — a field without the control is never served. Extension configuration, not TSconfig, selects the fields, because the TCA is instance-wide.
- **Alternative presentation creates records, it does not rewrite the text**: bootstrap_package's tabs and accordion are content elements with inline item records, and the RTE does not allow `<details>`. The bodies are always cut from the stored text; the model, when it is asked at all, only names start blocks and titles, and the creation request carries no markup. DataHandler stores an item without its excluded `header` when the editor may not edit that field, without an error, and it is not transactional (a refused item table leaves the element behind), so `PresentationAccess` checks CType, TSconfig, tables and fields before the write, and `PresentationWriter` undoes a creation that reported an error or is not stored as intended. The new element is created hidden and the switch is a separate, token-bound request, so nothing visitors see changes until the editor decides; the switch only toggles `hidden`, so access restrictions taken over from the original stay.
- **CI via shared reusables**: all workflows delegate to `netresearch/typo3-ci-workflows` / `netresearch/.github`; the per-repo matrix lives in `.github/workflows/ci.yml`.
