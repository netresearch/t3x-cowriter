<!-- SPDX-License-Identifier: GPL-3.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Security assurance

What users of t3_cowriter can and cannot expect in terms of security, and the argument for it: threat model, trust boundaries, the design principles applied, and how common weaknesses are countered. Every claim names the file that implements it. Components, AJAX routes and data flow: `docs/ARCHITECTURE.md`. Vulnerability reporting: `SECURITY.md`.

The document describes the code on `main`.

## What the extension does, security-wise

A backend editor works in the CKEditor 5 rich-text editor or in a FormEngine form. The Cowriter toolbar items and the "Suggest" field control send editor content to the TYPO3 backend over AJAX routes (`Configuration/Backend/AjaxRoutes.php`). The controllers in `Classes/Controller/` pass it to the `netresearch/nr-llm` extension, which forwards it to the language-model provider of the chosen LLM configuration. The answer comes back to the browser as JSON and is inserted into the editor or the form field only when the editor picks it.

## What leaves the system, and to whom

Everything below is sent to the provider that the selected nr-llm configuration points at (for example a hosted API or a local Ollama instance). Which provider that is, and what it stores, is decided by the TYPO3 administrator in nr-llm, not by this extension.

| Editor action | Content sent | Where it is assembled |
|---------------|--------------|-----------------------|
| Task dialog (Cowriter button, Tasks dropdown) | The selected HTML, or the whole editor content when nothing is selected; the task prompt or the editor's own instruction; optional style instruction (audience, tone, length) | `Resources/Public/JavaScript/Ckeditor/cowriter.js`, `Classes/Controller/AjaxController.php` (`executeTaskAction`, `executeTaskStreamAction`), `Classes/Service/Style/StyleInstructionBuilder.php` |
| Task dialog with a context scope or reference pages | `header`, `subheader` and `bodytext` of `tt_content` records: the current element, its page, or its page plus one or two ancestor pages (at most 50 elements per page), and of up to 10 reference pages chosen by the editor | `Classes/Service/ContextAssemblyService.php`, `Classes/Domain/DTO/ExecuteTaskRequest.php` (`MAX_REFERENCE_PAGES`) |
| Translate dropdown | The selected HTML and the target language | `cowriter.js`, `Classes/Controller/TranslationController.php` |
| Generate alt text | The image URL of the selected image (the URL string, not the image bytes) and the default alt-text prompt (`VisionRequest::DEFAULT_PROMPT`) | `cowriter.js`, `Classes/Controller/VisionController.php` |
| "Suggest" field control | The current field value and context read from the record being edited | `Resources/Public/JavaScript/FormEngine/FieldSuggestions.js`, `Classes/Service/FieldSuggestion/RecordContextReader.php`, `FieldSuggestionService.php` |

The extension passes its own name to nr-llm as caller metadata (`Classes/Service/CallerSource.php`). Saved prompts (`tx_cowriter_prompt`) stay in the TYPO3 database. The extension itself writes no request content to logs; on failure it logs the exception message and, for alt text, the first 100 characters of the image URL (`VisionController.php`).

## Security expectations

Users can expect:

- **No provider credentials in the browser or in this extension.** The browser talks only to the TYPO3 backend: `Resources/Public/JavaScript/Ckeditor/AIService.js` sends its requests to the backend AJAX route URLs in `TYPO3.settings.ajaxUrls`, which TYPO3 fills for the extension's routes (`Configuration/Backend/AjaxRoutes.php`) and `UrlLoader.js` completes from a JSON data element that `Classes/EventListener/InjectAjaxUrlsListener.php` renders; the field suggestion control (`Resources/Public/JavaScript/FormEngine/FieldSuggestions.js`) calls the URL that `Classes/Form/FieldControl/FieldSuggestionsControl.php` puts in its `data-url` attribute. Every model call goes through nr-llm's `LlmServiceManagerInterface`, `VisionServiceInterface`, `TranslationServiceInterface` or tool loop. The extension never reads a key value; the setup status check asks nr-llm only whether a provider has one (`DiagnosticService::checkProviderHasApiKey()`).
- **Only logged-in backend users reach the AJAX routes.** None of the 18 routes in `AjaxRoutes.php` sets `access` to `public`, so TYPO3 requires a backend session (`BackendUserAuthenticator`) and a valid route token for each call (`RouteDispatcher::assertRequestToken()`, read at TYPO3 13.4.35 and 14.3.7). The setup status module is admin-only (`Configuration/Backend/Modules.php`, `'access' => 'admin'`).
- **Editors can use only the LLM configurations they are allowed to.** Every route that takes a configuration identifier resolves it through `Classes/Service/ConfigurationSelector.php`, which applies nr-llm's configuration access rule (backend groups) and answers 403 otherwise.
- **Context comes only from pages the editor may see.** `ContextAssemblyService` reads `tt_content` only (`ALLOWED_TABLES`) and returns no record whose page fails `BackendUserAuthentication::doesUserHaveAccess()` with read permission; reference pages are checked the same way. The page search filters by `getPagePermsClause(Permission::PAGE_SHOW)` (`AjaxController::searchPagesAction`).
- **Field suggestions only for fields the editor may edit.** `RecordContextReader` checks `tables_modify`, `non_exclude_fields`, the page permission (`CONTENT_EDIT`, or `PAGE_EDIT`/`PAGE_NEW` for pages) on server-side data, and reads records as the editor's workspace shows them (`RecordFinder.php`, `WorkspaceRestriction`). Only fields that carry the control in the TCA are served (`RegisterFieldSuggestionControlsListener.php`). Nothing is saved: the editor picks a value into the form and saves the record.
- **Record data in suggestion prompts is fenced as data.** `Classes/Service/FieldSuggestion/UntrustedDataFence.php` places the record data between fixed markers named in the system prompt and defuses marker look-alikes inside the data; the answer is requested against a JSON schema and cut to the field's length limits (`FieldSuggestionService.php`, `SuggestionNormalizer.php`).
- **AI output is inserted as the editor chooses it, never automatically.** See the next section.
- **Only approval-free tools in the task dialog.** With the tools switch on, `Classes/Service/Tool/UnattendedToolRunner.php` offers only the tools that nr-llm's tool policy allows this user and configuration and that nr-llm classifies as running without approval (`UnattendedToolFilterInterface`); a tool that asks for approval all the same ends the task (`ToolNeedsApprovalException`). Tools run under the editor's identity (`ToolExecutionContext::fromBackendUser()`).
- **Bounded requests.** Every route that calls a model, and the template and saved-prompt routes, check a sliding-window limit of 20 requests per minute per backend user before doing anything else (`Classes/Service/RateLimiterService.php`, `RateLimitedControllerTrait.php`) and answer 429 above it. Request DTOs cap input sizes (32768 characters for prompts, instructions and context, 3 variants, 5 suggestions; `Classes/Domain/DTO/`), and the chat action accepts at most 50 messages (`AjaxController::MAX_MESSAGES`). A user can keep a limited number of saved prompts (`SavedPromptRepository::MAX_PER_USER`).
- **Saved prompts are private until shared.** Users see their own prompts and shared prompts of others; with approval required, another user's shared prompt appears only after an administrator approved it; only the owner can delete a prompt (`Classes/Service/Prompt/SavedPromptRepository.php`).
- **Error details stay on the server.** Provider and configuration failures produce fixed or classified messages (`Classes/Service/LlmErrorClassifier.php`, `AjaxController::buildErrorResponse()`); the exception message is added to the response only when `$GLOBALS['TYPO3_CONF_VARS']['BE']['debug']` is enabled.

Users cannot expect:

- **Confidentiality of the content sent to the provider.** Editor content, page context and image URLs leave the TYPO3 instance for the configured provider. What that provider stores or trains on is outside this extension.
- **Correct or safe AI output.** Generated text can be wrong, biased or unsuitable, and a page's content can steer the model's answer; the editor reviews the result before inserting it and before saving.
- **Protection against a malicious administrator or a compromised nr-llm configuration.** Both are trusted.

## How AI output enters the editor

The server returns model output as it arrives, after converting Markdown to HTML where the model answered in Markdown (`AjaxController::convertMarkdownToHtml()`, which keeps only `http(s)`, relative and fragment link targets). It does not HTML-escape the output (`Classes/Domain/DTO/CompleteResponse.php`); the browser processes it before it reaches the document:

- **Task dialog** (Cowriter button and Tasks dropdown): the answer, each variant and each streamed chunk pass through `sanitizeHtml()` (`Resources/Public/JavaScript/Ckeditor/HtmlSanitizer.js`, called by `CowriterDialog._sanitizeHtml()`). It parses the HTML with `DOMParser`, keeps only an allow-list of formatting elements and attributes, unwraps every other element (keeping its text), removes `href`/`src` values with a `javascript:`, `vbscript:` or non-image `data:` scheme (after stripping whitespace and control characters), and sets `rel="noopener noreferrer"` on `target="_blank"` links. It keeps an image only when its source is an inline `data:image/` (PNG, JPEG, GIF, WebP), is on the backend's own origin, or already appeared in the editor content the dialog was opened with; any other image is removed before the preview shows it. The dialog shows the sanitised result as a preview; only when the editor presses Insert does `cowriter.js` pass that sanitised HTML through CKEditor's data pipeline (`editor.data.processor.toView()`, `editor.data.toModel()`, `model.insertContent()`), which keeps what the editor's schema allows.
- **Translate dropdown:** the translated HTML passes through the same `sanitizeHtml()`, with the images of the selected text as the known ones, then through the CKEditor data pipeline, and replaces the selection (`cowriter.js`).
- **Generate alt text:** the answer is set as the image's `alt` attribute or inserted as plain text (`writer.setAttribute('alt', …)`, `writer.insertText()`), never parsed as markup.
- **"Suggest" field control:** suggestions are shown with `textContent` and written into the form field as its value (`FieldSuggestions.js`); they are never parsed as markup.

The chat, complete, stream and tools routes have no caller in the shipped toolbar; `AIService.js` exposes them for integrations, which are responsible for handling the output the same way.

## Threat model and trust boundaries

| Boundary | Untrusted input | Control |
|----------|-----------------|---------|
| Browser → AJAX routes | JSON request bodies, record and page identifiers, configuration identifiers | TYPO3 backend session and route token; request DTOs with length limits; `ConfigurationSelector`; page and record permission checks; rate limit |
| Page content → prompt | Text of content elements and records, which may contain instructions aimed at the model | Field suggestions: `UntrustedDataFence` and a JSON schema; task dialog: the editor reviews the answer before inserting it |
| Language model → browser and editor | Generated HTML, Markdown and text | Allow-list sanitiser for dialog and translation results, CKEditor data pipeline and schema, text-only insertion for alt text and suggestions |
| Extension → nr-llm → provider | Prompts, context, image URLs | Credentials held by nr-llm; configuration access rule |
| Model → tools | Tool calls requested by the model | nr-llm tool policy for the user and configuration; approval-bound tools not offered in the dialog |

Attackers considered: a backend editor trying to read content or use configurations beyond their permissions, content on a page crafted to steer the model (prompt injection), and a model answer carrying markup or script. The TYPO3 administrator, the nr-llm configuration and the provider chosen there are trusted.

## Secure design principles applied

- **Least privilege:** the extension holds no provider credentials; every permission decision uses the current backend user's rights on server-side data (`RecordContextReader`, `ContextAssemblyService`, `ConfigurationSelector`); tools run under the editor's identity.
- **Complete mediation:** each route that calls a model checks the rate limit and resolves a client-named configuration through `ConfigurationSelector` before the call.
- **Fail-safe defaults:** a missing session gives a non-interactive tool context (`ToolController`); an unknown or denied configuration is refused with 404 or 403; a record the user may not read yields no context; tools in the dialog are off by default.
- **Separation of data and instructions:** record data in suggestion prompts is fenced (`UntrustedDataFence`).
- **Economy of mechanism:** all model access goes through nr-llm's service interfaces; all browser traffic goes to the extension's backend AJAX routes (`Configuration/Backend/AjaxRoutes.php`).
- **Human in the loop:** nothing generated is written without the editor pressing Insert or picking a suggestion, and saving the record.

## Countering common weaknesses

| Weakness (CWE / OWASP) | Counter |
|------------------------|---------|
| Cross-site scripting (CWE-79, A03:2021) | Allow-list sanitiser (`HtmlSanitizer.js`) for dialog and translation results, and the CKEditor data pipeline; `textContent` and attribute writes for alt text and suggestions; the labels and route URLs that `InjectAjaxUrlsListener.php` renders reach the page in `type="application/json"` elements that the browser does not execute, read with `JSON.parse` (`UrlLoader.js`, `Labels.js`); the extension adds no exception to the backend Content Security Policy (`Configuration/ContentSecurityPolicies.php`) |
| Cross-site request forgery (CWE-352) | TYPO3 route token on every AJAX route |
| Missing or incorrect authorisation (CWE-862, CWE-863, A01:2021) | Page and record permission checks, `ConfigurationSelector`, owner checks for saved prompts, admin-only status module |
| SQL injection (CWE-89) | QueryBuilder with named parameters in every query and the DBAL connection's `insert()` with a data array (`ContextAssemblyService.php`, `SavedPromptRepository.php`, `RecordFinder.php`, `AjaxController::searchPagesAction`); no SQL is built by string concatenation |
| Prompt injection (OWASP LLM01) | Fenced record data and JSON schema for field suggestions; human review before insertion everywhere |
| Improper output handling (OWASP LLM05) | See "How AI output enters the editor" |
| Excessive agency (OWASP LLM06) | Only tools that nr-llm classifies as approval-free are offered in the dialog, under the editor's identity and nr-llm's tool policy |
| Uncontrolled resource consumption (CWE-400, OWASP LLM10) | Rate limit per user, input size limits, at most 50 elements per page and 10 reference pages |
| Information exposure through error messages (CWE-209) | Classified messages; raw exception text only with `BE.debug` |
| Hard-coded credentials (CWE-798) | None in the code; Betterleaks scans every pull request (`.github/workflows/checks.yml`) |
| Vulnerable components (A06:2021) | Composer Audit and Dependency Review on every pull request (`checks.yml`); Renovate (`renovate.json`) |

Static checks on every pull request (Opengrep, CodeQL for JavaScript and the workflows, PHPStan level 10 on `Classes/` and `Configuration/`), the PHP unit, functional, integration and E2E suites and the Vitest suite (`Tests/`) back these claims; see "Governance and policies" in `CONTRIBUTING.md`.
