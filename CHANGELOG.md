# Unreleased

## CHANGE

- Require `netresearch/nr-llm ^0.25` (was `^0.23.0`). The tool loop now runs under an explicit actor identity: `ToolLoopServiceInterface::runLoop()` takes a required `ToolExecutionContext` (nr-llm ADR-083), which the tool endpoint derives from the live backend user (`ToolExecutionContext::fromBackendUser()`), falling back to a non-interactive context when no backend user is present. Tools authorise against this context instead of the ambient `$GLOBALS['BE_USER']`, so a queued run authorises identically to a synchronous one.

## MIGRATION

- Upgrade the nr-llm extension to `^0.25` and run `typo3 extension:setup` on the host install (nr-llm adds governance/lease schema).
- Heads-up: nr-llm's tool data-class gate now defaults to `enforce` on **fresh** installs (ADR-115). Cowriter ships no tools of its own, but the tool loop runs nr-llm's builtin tools, so a builtin whose data class exceeds a configuration's trust zone is withheld under `enforce`. Upgraded installs are pinned to `observe` by nr-llm's `DataClassEnforcementDefaultUpdateWizard` until the operator opts in — run the upgrade wizard after updating.

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

