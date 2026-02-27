# 4.0.0 (2026-02-27)

## FEATURE

- TYPO3 v13.4 LTS support added alongside v14
- PHP 8.2, 8.3, 8.4, 8.5 support (widened from 8.5-only)
- Enterprise CI/CD: PR quality gates, release with SBOM/Cosign signing, SLSA Level 3 provenance, security scanning
- PHP modernization via Rector
- TYPO3 conformance fixes
- RTE config renamed from Pluginv12.yaml to Cowriter.yaml
- Updated documentation and badges

## Contributors

- Team der Netresearch DTT GmbH

# 3.0.0 (2026-01-29)

## BREAKING

- Requires PHP 8.5+ and TYPO3 14.0+ (historical: no v13 support, PHP 8.5 only)
- Requires nr-llm extension for LLM provider abstraction
- Removed direct OpenAI/Ollama API support from JavaScript
- API configuration now handled by nr-llm extension

## FEATURE

- Integrated nr-llm extension for unified LLM provider support
- Added PHP backend controller for secure LLM requests
- Supports all nr-llm providers: OpenAI, Claude, Gemini, OpenRouter, Mistral, Groq
- API keys are now securely stored on the server (not exposed to frontend)

## SECURITY

- Removed API key exposure from frontend JavaScript
- All LLM requests now routed through authenticated TYPO3 AJAX endpoints

## MIGRATION

- Install and configure nr-llm extension
- Remove old API configuration from extension settings
- Provider selection is now handled via nr-llm configuration

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

