# Unreleased

## FEATURE

- The Cowriter dialog offers the LLM configurations the editor may use. The first option runs the task on its own configuration, or on the default one; its label names that configuration. A chosen configuration now wins over the task's own. The dialog opens without waiting for the list and shows the picker once it arrives; without a list it looks as before.
- The Cowriter dialog can ask for two or three versions at once. One structured call (`completeStructuredForConfiguration()`, schema with exactly the requested count) returns them; each gets the markdown-to-HTML fallback, and the first is also `content`, so a client without versions support inserts a complete answer. A radio group above the preview chooses the version the preview shows and Insert takes. Several versions are not streamed. `variants` outside 1 to 3 is an invalid request.
- Style choices in the Cowriter dialog: an audience and a tone of voice from the nr-llm prompt snippets tagged `audience` and `tone_of_voice`, and a length step from much shorter to much longer. The server adds them as one instruction, the last system message before the editor's instruction, so they apply over a tone the configuration sets; a chosen snippet counts only while it is active and carries its selector's tag. Page TSconfig `tx_cowriter.targetLength.<CType>` sets a target length in words per content type, which the length step scales (50 to 150 per cent); the dialog then shows the word count of the answer against the target. New route `tx_cowriter_style_options`.
- The Cowriter dialog shows the answer while the model writes it. The new route `tx_cowriter_task_stream` takes the same request as `tx_cowriter_task_execute`, builds the same messages and sends the answer as Server-Sent Events; the last event carries the complete answer after the same markdown-to-HTML fallback. Pieces arriving within 80 ms of the previous event are sent together, and each event is padded to 4 KB so a buffering proxy passes it on. The preview is marked `aria-busy` meanwhile, so a screen reader announces the finished answer once. Without the stream route the dialog calls `tx_cowriter_task_execute` as before.
- Editors save prompts from the Cowriter dialog: "Save as prompt" stores the instruction under a title, and the task list offers the editor's own prompts and the shared prompts of others as option groups; a chosen prompt runs as a custom instruction. New table `tx_cowriter_prompt` (root level, one owner per record, up to 100 per editor) and routes `tx_cowriter_prompts`, `tx_cowriter_prompt_save`, `tx_cowriter_prompt_delete`; only the owner deletes a prompt. The extension setting `prompts.sharedNeedApproval` (on by default) keeps a shared prompt from other editors until an administrator sets "Approved" on the record in the List module; an administrator's shared prompt is approved when saved.

## FIX

- Every route checks that the backend user may use the configuration it runs on: the one the editor chose, the task's own and the default one. nr-llm restricts a configuration to backend groups, but cowriter did not ask, so any editor could run a restricted configuration by sending its identifier, and the configuration list showed every active configuration. A refused configuration answers HTTP 403 with "You are not allowed to use this LLM configuration."; an unknown or inactive chosen one answers 404, and is no longer replaced by the default; an inactive configuration of the task itself answers 409 and asks the editor to choose another. The tool route used to answer an unknown configuration with HTTP 400 and the English exception text, and the translation route with HTTP 500.

# 3.8.0 (2026-09-26)

## FEATURE

- German translations of both language files (`de.locallang_be.xlf`, `de.locallang_mod_status.xlf`). A backend user whose backend language is German sees the "Suggest values with AI" button, the suggestion list, its status messages and the `cowriter_status` module in German.
- Every user-facing text now comes from the language files and follows the backend language of the user: the `cowriter_status` module (heading, introduction, result callouts, table header, "Fix" button, closing note and the result text of each check), the CKEditor toolbar actions, notifications and language names, the Cowriter dialog, the rate-limit answer shared by all AJAX routes, and the error messages of the AJAX routes. The plugin's labels are resolved on the server and passed to the page the same way as the AJAX URLs; the JavaScript keeps its English texts as fallback. The suggestion list has its own text for a single suggestion. Log messages stay English, and so do the errors `AIService.js` throws when a route is not configured, which point at a broken installation rather than at something an editor can act on.

## DOCS

- The field suggestions section shows a screenshot of the suggestion list below the SEO title field.

# 3.7.0 (2026-09-25)

## FEATURE

- AI suggestions for form fields outside the rich text editor: a "Suggest values with AI" button next to `pages.seo_title` (with EXT:seo), `pages.description`, `pages.keywords` and `pages.slug` asks the LLM for three alternative values and lists them below the field. Picking one fills the form field; nothing is saved until the editor saves the record. The button works with the keyboard (Enter/Space, arrow keys, Escape returns focus to the button, picking moves focus into the filled field) and announces loading, results and errors through a live region.
- New AJAX route `tx_cowriter_suggestions` (`FieldSuggestionController::suggestAction`). It reads the record, its page and the page content on the server, and checks write access to the table, access to the field if it is an exclude field, and edit rights on the page (page creation rights for a new page) before calling the LLM. A record that does not exist is refused with the same 403 as one the user may not edit. All reads are limited to the live records and the user's own workspace and show that workspace's drafts; draft rows of other workspaces never reach the model, and a uid that names a version row is refused. The instructions go to the model as the system prompt; the record data travels only in the user message, between fixed `<<<BEGIN/END UNTRUSTED PAGE DATA>>>` markers, and anything in the data that looks like one of the markers is defused first. The answer is requested as JSON against a schema (nr-llm `completeStructuredForConfiguration()`), and the per-field length limits (60 characters for the SEO title, 160 for the description) are enforced in code. For the slug the model proposes only the last path segment; TYPO3's `SlugHelper` adds the parent path and sanitises it. Uniqueness is checked by TYPO3's slug element and by DataHandler on save, as it is for a slug that is typed in.
- New extension configuration `fieldSuggestions.fields` (default `pages.seo_title,pages.description,pages.keywords,pages.slug`) and `fieldSuggestions.count` (default 3, 1 to 5, and the upper bound the server enforces). A configured field that does not exist in the TCA is skipped.

## BUILD

- `typo3/cms-seo` and `typo3/cms-workspaces` are dev dependencies, so the functional tests cover the SEO title with and without EXT:seo and the suggestion context inside workspaces.
- `Build/phpunit/FunctionalTests.xml` defaults `typo3DatabaseDriver` to `pdo_sqlite`, so the functional coverage job of Extended Testing, which configures no database, can run the functional suite. An exported value still wins.

# 3.6.10 (2026-09-24)

## CHANGE

- Accepts `netresearch/nr-llm` at `^0.34 || ^0.35 || ^0.36 || ^0.37`, and `ext_emconf.php` with it, at `0.34.0-0.37.99`. On a 0.x `^0.36` does not admit 0.37.0, so 3.6.9 would pin every installation it is part of to nr-llm 0.36.

# 3.6.9 (2026-09-23)

## CHANGE

- Accepts `netresearch/nr-llm` at `^0.34 || ^0.35 || ^0.36`, and `ext_emconf.php` with it, at `0.34.0-0.36.99`. On a 0.x `^0.35` does not admit 0.36.0, so 3.6.8 would pin every installation it is part of to nr-llm 0.35 or older. Source compatibility was checked against nr-llm's API surface snapshot between v0.35.0 and the 0.36.0 release commit (`0c56cae4`): the only changes are additive (`WriteKind::DELETED`, `RecordCreatorInterface`), and the one its changelog marks as breaking — `WriteKind` gaining a case — affects only code that matches `WriteKind` exhaustively, which this extension does not reference.
- CI workflows are synced with `netresearch/.github`'s `typo3-extension` template (#177, #178), and the documentation names the secret scan betterleaks, as CI now does (#179).
- Adds `.bestpractices.json` with the evidence behind the OpenSSF Best Practices badge answers (#180, #181).

# 3.6.8 (2026-09-17)

## CHANGE

- Accepts `netresearch/nr-llm` at `^0.34 || ^0.35`, and `ext_emconf.php` with it, at `0.34.0-0.35.99`. nr-llm 0.35.0 is released and on a 0.x `^0.34` does not admit it, so 3.6.7 pinned every installation it is part of to nr-llm 0.34. Source compatibility was measured: of nr-llm 0.35's three breaking changes, `ToolResult::withWriteTarget()` affects only extensions that register tools, `ConversationService::startSession()` is not called here, and the `ModelResolution` added to `chatForConfiguration()`/`chatWithConfiguration()` is nullable and last, so existing call sites are unchanged.

# 3.6.7 (2026-09-03)

## CHANGE

- Requires `netresearch/nr-llm` `^0.34`, and `ext_emconf.php` with it, at `0.34.0-0.34.99` rather than the `0.33.0-0.33.99` it carried.
- The `cowriter_status` module no longer declares `['after' => 'nrllm']`. nr-llm 0.34.0 moved its modules into a shared `AI` section (ADR-183) and left `nrllm` behind as an alias for `nrllm_overview`. `ModuleFactory` rewrites a `position` reference through that alias, so the anchor now resolves — to a module under `netresearch_ai`, while `cowriter_status` sits under `tools`. `ModuleRegistry::applySorting()` only honours an `after` whose target is a sibling, so the line had become a declaration that cannot take effect. The module keeps its place in Admin Tools by default ordering.
- `ext_localconf.php`'s cache-configuration guard is a `??=` assignment rather than an `isset()` block. rector/rector 2.6.5 widened `IfToNullCoalescingAssignRector`, and because this repository commits no `composer.lock`, CI resolved the new release and the Rector gate went red on `main` without a line of ours moving — taking the required `ci / All CI checks` context with it. `??=` assigns exactly when `isset()` is false, so the rewrite is behaviour-preserving.
- `step-security/harden-runner` is pinned to v2.21.1, matching `netresearch/.github`'s `typo3-extension` template.

# 3.6.6 (2026-08-21)

## CHANGE

- Requires `netresearch/nr-llm` `^0.33`. The floor rises because 0.33.0 removes a regression 0.32.0 introduced: `vision()` and `embed()` handed the provider registry the `tx_nrllm_provider` row's identifier where it is keyed by the adapter's own name, so a call that names no provider — which is what this extension makes — failed with "Provider … not found" on an installation that has a perfectly good default configuration. 0.32.0 did not fix the failure it was written for, it renamed it.
- `ext_emconf.php` declares the same dependency and is raised with it, so the two cannot disagree about which versions this extension accepts.

# 3.6.5 (2026-08-21)

## CHANGE

- Requires `netresearch/nr-llm` `^0.32`. The floor rises because 0.32.0 is what
  carries the caller source through to dispatch, and it gives `vision()` and
  `embed()` the default-configuration fallback `chat()` already had — a call
  naming no provider now uses the installation's default instead of throwing.
- Every LLM call names this extension (`t3_cowriter`) and the editor action
  that triggered it as its caller source, so the nr-llm Analytics module
  attributes usage and cost to cowriter instead of listing it as
  "Unattributed". Operations: `chat`, `complete`, `streamComplete`, the task
  identifier (or `customInstruction` when no task is selected) for task
  execution, `altText`, `translate` and `toolCall`.
- Alt text, translation and tool calling carry the identity on the options
  object. nr-llm discarded it before dispatch up to 0.31.1 — `VisionService` and
  `TranslationService` rebuilt their options without the field, and
  `ToolLoopService` forwarded only budget metadata — so those three endpoints
  were unattributed. 0.32.0 forwards it
  ([nr-llm#845](https://github.com/netresearch/t3x-nr-llm/issues/845)), and with
  the floor raised in this release all seven operations are attributed.

# 3.6.4 (2026-08-20)

## CHANGE

- Requires `netresearch/nr-llm` `^0.31` — a floor raise, not a widening: 0.30
  is no longer accepted. 0.31.0 is additive (per-extension usage attribution
  in analytics, ADR-178; explicit half of the per-call outcome, ADR-176) and
  nothing this extension consumes changed. The `ext_emconf.php` constraint
  moves with it.

# 3.6.3 (2026-08-19)

## CHANGE

- Requires `netresearch/nr-llm` `^0.30` — a floor raise, not a widening: 0.28
  and 0.29 are no longer accepted. 0.30 is additive (caller-source telemetry
  attribution, ADR-177); nothing this extension consumes changed. The
  `ext_emconf.php` constraint moves with it.

# 3.6.2 (2026-08-13)

## CHANGE

- Accepts `netresearch/nr-llm` `^0.29` alongside `^0.28`, so this extension can
  be installed next to one that needs 0.29. Nothing here touches the three
  surfaces 0.29 broke: `ModelSelectionServiceInterface` is consumed, never
  implemented, and neither `ContextFitResult` nor `InputSubmission` is
  constructed.

## FIX

- `ext_emconf.php` demanded nr_llm `0.25.0-0.25.99`, which excluded the `^0.28`
  composer.json has required since 3.6.1. The two constraints now say the same
  thing.

# 3.6.1 (2026-08-10)

## CHANGE

- Requires `netresearch/nr-llm` `^0.28`. The previous cap at `^0.26` did not
  even admit 0.27 — the caret excludes the next minor on a 0.x version — and
  held the whole dependency tree two minors back.


# 3.6.0 (2026-08-07)

## ADD

- The quick "Translate" toolbar action now prefers a specialized translator
  (e.g. DeepL) over the generic LLM chat path when one is available and
  supports the requested language pair. Previously `translateAction()` used
  `TranslationService::translate()` for the no-configuration case, which
  never consults the translator registry at all — a configured specialized
  translator was unreachable from this action unless an editor happened to
  pin a saved `LlmConfiguration` whose own `translator` field was set.
  Requires `netresearch/nr-llm`'s `TranslationOptions::withTranslator()`
  and `LlmTranslator::IDENTIFIER`, and depends on a fix to
  `DeepLTranslator::supportsLanguagePair()` so it accepts `'auto'` as a
  source language. All three shipped in nr-llm 0.26.0
  (netresearch/t3x-nr-llm#571, #134).

## CHANGE

- Require `netresearch/nr-llm ^0.26` (was `^0.25`) (#142).

## FIX

- The setup-status link is only built from `http(s)` URLs, and only when the
  target is our own origin (#140, #141).

## MIGRATION

- Upgrade the nr-llm extension to `^0.26`. nr-llm 0.26 requires nr-vault
  `^0.14`, which replaces nr-vault's admin-only model with grantable
  operation permissions: backend users who reach an API key through nr-llm
  need `tx_nrvault:secret.use`, and `secret.create` to store one.

# 3.5.0 (2026-07-24)

## CHANGE

- Require `netresearch/nr-llm ^0.25` (was `^0.23.0`). The tool loop now runs under an explicit actor identity: `ToolLoopServiceInterface::runLoop()` takes a required `ToolExecutionContext` (nr-llm ADR-083), which the tool endpoint derives from the live backend user (`ToolExecutionContext::fromBackendUser()`), falling back to a non-interactive context when no backend user is present. Tools authorise against this context instead of the ambient `$GLOBALS['BE_USER']`, so a queued run authorises identically to a synchronous one (#127).

## MIGRATION

- Upgrade the nr-llm extension to `^0.25` and run `typo3 extension:setup` on the host install (nr-llm adds governance/lease schema).
- Heads-up: nr-llm's tool data-class gate now defaults to `enforce` on **fresh** installs (ADR-115). Cowriter ships no tools of its own, but the tool loop runs nr-llm's builtin tools, so a builtin whose data class exceeds a configuration's trust zone is withheld under `enforce`. Upgraded installs are pinned to `observe` by nr-llm's `DataClassEnforcementDefaultUpdateWizard` until the operator opts in — run the upgrade wizard after updating.

# 3.4.1 (2026-07-22)

## FIX

- Repaired `Documentation/guides.xml` and added a docs-render CI job so a broken
  docs build is caught before release (#126).

# 3.4.0 (2026-07-21)

## ADD

- A dedicated dark-mode-aware backend module icon, and the brand icons cleaned
  up alongside it (#124).

# 3.3.0 (2026-07-21)

## ADD

- Tool calling now executes: the tool endpoint drives nr-llm's bounded tool loop (`ToolLoopService`) against a resolved LLM configuration, so the model's tool calls run server-side and their results feed back into the conversation. Previously a tool was declared to the model but never executed. Tools come from nr-llm's builtin registry — cowriter ships no tool code of its own.
- Per-user budget attribution: chat, complete, task, stream, vision and translation calls pass the backend user id, so nr-llm's per-user `BudgetMiddleware` enforcement applies to cowriter traffic (previously it was skipped).

## CHANGE

- Require `netresearch/nr-llm ^0.23.0` (was `^0.22.0`) for the builtin tool catalog and the injectable `ToolLoopServiceInterface`.
- The alt-text endpoint uses nr-llm's `VisionOptions::altText()` preset (low detail, tighter token budget) instead of the defaults.

## FIX

- Non-admin editors were denied all surrounding context: the page-access check fetched a `uid`-only page row, so TYPO3 `calcPerms()` returned no permissions for every non-admin and the context feature (element/page/ancestor scopes and reference pages) silently produced nothing. It now fetches the permission columns and reflects the editor's real rights.

## DOCS

- Correct API and agent-guide documentation drift after the nr-llm 0.22 upgrade (task-execute and SSE examples, output-sanitization wording, TYPO3 v14.3 constraint, and removed-API examples).

## MIGRATION

- Upgrade the nr-llm extension to `^0.23.0`.

# 3.2.0 (2026-07-19)

## ADD

- Per-configuration translation: the translate action can route through a pinned nr-llm configuration via `translateForConfiguration()`, applying its persona/tone, model and provider; a requested-but-missing configuration is reported as an error instead of silently falling back to the default path

## CHANGE

- Adopt nr-llm 0.22: require `netresearch/nr-llm ^0.22.0` (was 0.3–0.x)
- Migrate from the removed nr-llm `PromptTemplate` stack to `Task` (nr-llm ADR-069); the CKEditor task dialog now reads tasks from `tx_nrllm_task` with `category = 'content'`
- Classify LLM failures via nr-llm typed exceptions (`ConfigurationNotFoundException`, `ProviderResponseException` HTTP status) instead of exception-message string-matching
- Declare LLM tools with the typed `ToolSpec` value object instead of hand-built arrays
- Raise the TYPO3 v14 floor to v14.3 (nr-llm 0.22 requires `^14.3`, dropping 14.0–14.2)
- Correct `ext_emconf.php` constraints: `nr_llm` to 0.22.0–0.22.99 (matching composer `^0.22.0`) and the TYPO3/rte_ckeditor upper bound to 14.99.99. `composer.json` (`^13.4 || ^14.3`) stays authoritative for the TYPO3 range — ext_emconf's single min–max range cannot express the 14.0–14.2 exclusion

# 3.1.1 (2026-03-24)

## FIX

- Align the `nr_llm` version constraint between `composer.json` and `ext_emconf.php` (>=0.3 <1.0)
- Address TYPO3 extension assessment findings

## BUILD

- Share the extended-testing CI workflow from `netresearch/typo3-ci-workflows`
- Remove redundant `phpunit` from `require-dev` (provided by the CI workflows)

# 3.1.0 (2026-03-14)

## FEATURE

- Diagnostic service and "Setup Status" backend module reporting LLM configuration health

## BUILD

- Integrate `netresearch/typo3-ci-workflows` as a Composer package
- Raise mutation score to 85%+ (MSI) and improve patch coverage

## DOCS

- Replace outdated TYPO3 v11 screenshots with TYPO3 v14 captures
- Use interlink references for nr-llm documentation

# 3.0.0 (2026-03-10)

## BREAKING

- Requires PHP 8.2+ and TYPO3 v13.4+ or v14.0+ (dropped TYPO3 v12 support)
- Requires nr-llm extension for LLM provider abstraction (no standalone operation)
- Removed direct OpenAI/Ollama API support from JavaScript frontend
- API keys now managed exclusively by nr-llm extension (not in extension settings)
- CKEditor button now opens a task dialog instead of directly replacing selected text

## FEATURE

- Task-based dialog: select from predefined tasks (Improve, Summarize, Extend, Fix Grammar, Translate EN/DE)
- Context scope control: choose between selected text, content element, page content, or ancestor pages
- Ad-hoc instructions: add custom rules per request (e.g., "Write in formal tone")
- Result preview before inserting into the editor with retry option
- Reference page picker with typeahead search for providing additional context
- Editor content injected as structured system message for better LLM results
- Actionable error notifications with links to LLM settings
- Rate limiting: 20 requests/minute per backend user
- Server-Sent Events streaming for real-time completions
- Configuration selector for multiple LLM configurations
- PHP 8.2, 8.3, 8.4, 8.5 support
- TYPO3 v13.4 LTS support added alongside v14

## SECURITY

- Removed API key exposure from frontend JavaScript
- All LLM requests routed through authenticated TYPO3 AJAX endpoints

## MIGRATION

- Install and configure nr-llm extension (v0.1.0+)
- Remove old API configuration from extension settings
- Provider selection is now handled via nr-llm configuration
- RTE config renamed from Pluginv12.yaml to Cowriter.yaml

## Contributors

- Team der Netresearch DTT GmbH

# 2.0.0 (2025-12-25)

## BREAKING

- Requires PHP 8.2+ and TYPO3 12.4+
- Dropped support for TYPO3 9.5-11.5

## FEATURE

- d909f6c feat: add comprehensive extension infrastructure
- Add TYPO3 v12.4 LTS and v13 support
- Add DDEV development environment with multi-version testing
- Add PHPUnit unit and functional test infrastructure
- Add comprehensive RST documentation following TYPO3 13.x standards
- Add enterprise-grade governance documents (SECURITY.md, CONTRIBUTING.md)
- Add Dependabot configuration for dependency updates

## SECURITY

- ca6606e fix(ci): pin GitHub Actions to commit hashes

## Contributors

- Team der Netresearch DTT GmbH

# 1.2.3 (2024-02-12)

## FEATURE

- ecc0fcb - NEXT-40: Remove obsolete pipeline + setup release to TER

## Contributors

- Norman Golatka


# 1.2.2 (2024-02-08)

## BUGFIX

- e82fd8c [BUGFIX] Replace deprecated models

## Contributors

- Martin Wunderlich

# 1.2.1 (2023-03-30)

## BUGFIX

- f7a416c [BUGFIX] Load default RTE configuration for styles

## Contributors

- Gitsko

# 1.2.0 (2023-03-30)

## FEATURE

- 8a1ae11 [FEATURE] Update ckeditor plugin to version 1.0.1 with new advanced settings

## TASK

- 4f8720d [TASK] Update version for github action
- 97c2676 [TASK] Add issues to project

## BUGFIX

- da94687 [BUGFIX] Set cowriter for all default content elements
- e1458d2 [BUGFIX] Move page ts config to static includes

## MISC

- fb1287b Revert "Update README.md"
- 9eb116f Update README.md
- 712e0fd NRLF-295: Add ignore builded packages zip files to gitignore

## Contributors

- Andreas Müller
- André Lademann
- Gitsko
- Martin Wunderlich
- Sebastian Koschel

# 1.1.2 (2023-02-03)

## MISC

- 1016728 NRLF-295: Use offical logo
- 66d14cf NRLF-295: Add notify batch
- fe47744 NRLF-295: Fix link to demo app
- d8a5c67 NRLF-295: Add link to demo app in README
- 2203b22 NRLF-295: Fix path to artifacts
- dd8770f NRLF-295: Fix path to artifacts
- 122da0d NRLF-295: Add GNU license and contact links
- 89b3be8 NRLF-295: Remove trigger on main
- 53e135d NRLF-295: Add workflow to build and relase automatically
- 42e60f5 Add action for releases
- 5657832 Update issue templates

## Contributors

- André Lademann
- André Lademann

# 1.1.1 (2023-02-01)

## MISC

- 7a4ca1d [Bugfix] Set Default organization empty

## Contributors

- Gitsko

# 1.1.0 (2023-02-01)

## FEATURE

- 554df42 [FEATURE] Enhancement for use with TYPO3 version 9.5-11.5

## Contributors

- Sebastian Koschel

# 1.0.1 (2023-01-26)

Initial stable release with minor fixes.

# 1.0.0 (2023-01-25)

First major release with CKEditor integration.

# 0.0.2 (2023-01-25)

## MISC

- e1310dc NRLF-295: Add language files
- 77c2df9 NRLF-295: Use js const only in BE mode
- 03fe141 NRLF-295: Add animated image to documentation
- 46238fd NRLF-295: Make api credentials configurable
- a942a16 NRLF-295: Add plugin configuration for CKEditor

## Contributors

- André Lademann
- Thomas Schöne

# 0.0.1 (2023-01-14)

## MISC

- cc9a8af NRLF-295: Add basic structure with make

## Contributors

- André Lademann

