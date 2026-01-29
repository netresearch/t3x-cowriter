# TYPO3 Extension: t3_cowriter

<!-- CI/Quality -->
[![CI](https://github.com/netresearch/t3x-cowriter/actions/workflows/ci.yml/badge.svg)](https://github.com/netresearch/t3x-cowriter/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/netresearch/t3x-cowriter/graph/badge.svg)](https://codecov.io/gh/netresearch/t3x-cowriter)

<!-- Standards -->
[![PHPStan](https://img.shields.io/badge/PHPStan-level%2010-brightgreen.svg)](https://phpstan.org/)
[![PHP 8.5+](https://img.shields.io/badge/PHP-8.5%2B-blue.svg)](https://www.php.net/)
[![TYPO3 v14](https://img.shields.io/badge/TYPO3-v14-orange.svg)](https://typo3.org/)
[![License: GPL v3](https://img.shields.io/badge/License-GPL_v3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0.en.html)
[![Latest Release](https://img.shields.io/github/v/release/netresearch/t3x-cowriter)](https://github.com/netresearch/t3x-cowriter/releases)
[![Contributor Covenant](https://img.shields.io/badge/Contributor%20Covenant-3.0-4baaaa.svg)](CODE_OF_CONDUCT.md)

<!-- TYPO3 TER -->
![Composer](https://typo3-badges.dev/badge/t3_cowriter/composer/shields.svg)
![Downloads](https://typo3-badges.dev/badge/t3_cowriter/downloads/shields.svg)
![Extension](https://typo3-badges.dev/badge/t3_cowriter/extension/shields.svg)
![Stability](https://typo3-badges.dev/badge/t3_cowriter/stability/shields.svg)
![TYPO3](https://typo3-badges.dev/badge/t3_cowriter/typo3/shields.svg)
![Version](https://typo3-badges.dev/badge/t3_cowriter/version/shields.svg)

AI-powered content assistant for TYPO3 CKEditor - write better content with help from AI.

![TYPO3 AI cowriter](Documentation/Images/t3-cowriter.gif)

## Features

- **CKEditor Integration**: Seamless toolbar button in TYPO3's rich text editor
- **Multi-Provider Support**: Works with all LLM providers supported by nr-llm (OpenAI, Claude, Gemini, etc.)
- **Secure Backend Proxy**: API keys never exposed to frontend - all requests proxied through TYPO3 backend
- **Model Override**: Use `#cw:model-name` prefix to request specific models
- **XSS Protection**: All LLM output is HTML-escaped for defense in depth

## Requirements

- PHP 8.5+
- TYPO3 v14
- [netresearch/nr-llm](https://github.com/netresearch/t3x-nr-llm) extension (LLM provider abstraction)

## Installation

Install via Composer:

```bash
composer require netresearch/t3-cowriter
```

## Configuration

### 1. Configure nr-llm Extension

First, set up at least one LLM provider in the nr-llm extension:

1. Navigate to **Admin Tools → LLM Management**
2. Add a provider (e.g., OpenAI with your API key)
3. Create a model configuration
4. Create an LLM configuration and set it as default

### 2. Add CKEditor Preset

#### Option A: Use the included preset

Add the cowriter preset to your Page TSconfig:

```typoscript
RTE.default.preset = cowriter
```

#### Option B: Extend your existing preset

Add to your RTE configuration YAML:

```yaml
editor:
  externalPlugins:
    cowriter:
      resource: "EXT:t3_cowriter/Resources/Public/JavaScript/Plugins/cowriter/"
```

## Usage

1. Select text in the CKEditor
2. Click the **Cowriter** button in the toolbar
3. The selected text is sent to the LLM with a system prompt to improve it
4. The improved text replaces your selection

### Model Override

Prefix your prompt with `#cw:model-name` to use a specific model:

```
#cw:gpt-4o Improve this text
#cw:claude-sonnet-4-20250514 Make this more professional
```

## Architecture

```
[CKEditor Button] → [AIService.js] → [TYPO3 AJAX]
                                         ↓
                                    [AjaxController]
                                         ↓
                              [LlmServiceManagerInterface]
                                         ↓
                                   [nr-llm Provider]
                                         ↓
                                   [External LLM API]
```

All LLM requests are proxied through the TYPO3 backend. API keys are stored encrypted and never exposed to the browser.

## Development

### Prerequisites

- DDEV for local development
- PHP 8.5+ with required extensions

### Setup

```bash
ddev start
ddev composer install
ddev install-v14
```

### Testing

```bash
# Run all tests
make ci

# Individual test suites
make test-unit           # Unit tests
make test-functional     # Functional tests
make test-integration    # Integration tests

# Code quality
make lint               # PHP-CS-Fixer
make phpstan            # PHPStan level 10
```

### Test Coverage

Target: >80% code coverage

```bash
make test-coverage
open .Build/coverage/html/index.html
```

## Security

- API keys stored in nr-llm with sodium encryption
- All backend AJAX endpoints require TYPO3 authentication
- LLM output HTML-escaped to prevent XSS
- CSRF protection via TYPO3 middleware
- Content Security Policy (CSP) compatible

## Migration from v2.x

Version 3.0 removes the frontend-only architecture. API keys are no longer stored in extension settings.

See [CHANGELOG.md](CHANGELOG.md) for migration details.

## License

This project is licensed under the GNU General Public License v3.0 - see the [LICENSE](LICENSE) file for details.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for contribution guidelines.

## Contact

[Netresearch DTT GmbH](https://www.netresearch.de/) - Your TYPO3 and eCommerce experts.

> [Twitter](https://twitter.com/netresearch) | [LinkedIn](https://www.linkedin.com/company/netresearch/) | [GitHub](https://github.com/netresearch)
