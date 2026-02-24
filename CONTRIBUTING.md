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

## Questions?

Open an issue or contact the maintainers via [GitHub Issues](https://github.com/netresearch/t3x-cowriter/issues).
