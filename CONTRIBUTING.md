<!-- SPDX-License-Identifier: GPL-3.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Contributing to AI Cowriter

Thank you for your interest in contributing to AI Cowriter for TYPO3!

## Code of Conduct

This project adheres to the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md).
By participating, you are expected to uphold this code.

## How to Contribute

### Reporting Bugs

1. Check if the bug has already been reported in [Issues](https://github.com/netresearch/t3x-cowriter/issues)
2. If not, create a new issue with:
   - Clear, descriptive title
   - Steps to reproduce
   - Expected vs actual behavior
   - TYPO3 and PHP version
   - Browser and version (for frontend issues)

### Suggesting Features

Open an issue with the `enhancement` label describing:
- The problem you're trying to solve
- Your proposed solution
- Alternative solutions you considered

### Pull Requests

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/my-feature`
3. Make your changes following our coding standards
4. Run quality checks: `composer ci:test`
5. Commit with conventional commits: `feat: add new feature`
6. Push and create a Pull Request

## Development Setup

```bash
# Clone your fork
git clone git@github.com:YOUR_USERNAME/t3x-cowriter.git
cd t3x-cowriter

# Install dependencies
composer install

# Run tests
composer ci:test
```

## Coding Standards

- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding style
- Use `declare(strict_types=1)` in all PHP files
- Add type declarations for parameters and return values
- Run `composer ci:cgl` to auto-fix style issues

## Quality Checks

Before submitting a PR, ensure all checks pass:

```bash
composer ci:test:php:lint    # PHP syntax check
composer ci:test:php:phpstan # Static analysis
composer ci:test:php:rector  # Code modernization
composer ci:test:php:cgl     # Coding guidelines
```

## Commit Messages

Use [Conventional Commits](https://www.conventionalcommits.org/):

- `feat:` New feature
- `fix:` Bug fix
- `docs:` Documentation changes
- `chore:` Maintenance tasks
- `refactor:` Code refactoring
- `test:` Adding or updating tests

## Governance and policies

This extension follows the organisation-wide Netresearch policies:

- [Governance](https://github.com/netresearch/.github/blob/main/GOVERNANCE.md):
  ownership, roles, how decisions are made and conflicts resolved.
- [Roadmap](https://github.com/netresearch/.github/blob/main/ROADMAP.md):
  planned and excluded work for the next twelve months.
- [Handling of dependency and code analysis findings](https://github.com/netresearch/.github/blob/main/SECURITY.md#handling-of-dependency-and-code-analysis-findings):
  which vulnerability, licence and static-analysis findings must be fixed,
  by when, and how exceptions are recorded.
- [Secret management](https://github.com/netresearch/.github/blob/main/SECURITY.md#secret-management):
  where CI and release credentials are stored, who may use them, how
  committed secrets are detected, and when secrets are rotated.
- [Access roster](https://github.com/netresearch/.github/blob/main/docs/access-roster.md):
  the people and teams with administrative or write access to this
  repository.

Checks that run on every pull request in this repository:

- `.github/workflows/checks.yml`: Composer Audit (fails on any advisory for
  an installed package) and Opengrep SAST (fails on findings of severity
  WARNING or higher), both through `typo3-ci-workflows`' `security.yml`;
  Dependency Review (fails on newly added dependencies with a vulnerability
  of severity high or higher); PHP License Audit (`license-check.yml`, fails
  on an SSPL or BSL licensed Composer dependency); CodeQL for the
  JavaScript and the workflow files (CodeQL has no PHP analysis; PHPStan and
  Opengrep cover the PHP code); Betterleaks secret scanning; zizmor for the
  workflow files; the pull request quality check.
- `.github/workflows/ci.yml`: PHP lint, code style, PHPStan (level 10,
  `Build/phpstan.neon`), Rector, unit and functional tests on PHP 8.2 to 8.5
  with TYPO3 13.4 and 14.3.
- `.github/workflows/testing.yml`: unit, functional, integration and E2E
  tests with coverage, the Vitest suite, and Infection mutation testing
  (thresholds in `infection.json5`).
- `.github/workflows/harness-verify.yml`: `Build/Scripts/verify-harness.sh`.
- `.github/workflows/docs.yml`, when `Documentation/` changes: the
  documentation rendering.

## Questions?

Open an issue or contact the maintainers via [GitHub Issues](https://github.com/netresearch/t3x-cowriter/issues).

## Commit Signing

All commits must be cryptographically signed and carry a DCO sign-off: `git commit -S --signoff`. The `require-signed-commits` ruleset on the default branch enforces the signature (the "Verified" badge on GitHub); the DCO check enforces the `Signed-off-by` trailer — these are two different things and both are required. Quickest setup is SSH signing: register your SSH key as a *signing key* on your GitHub account, then `git config --global gpg.format ssh && git config --global user.signingkey ~/.ssh/<key>.pub`.
