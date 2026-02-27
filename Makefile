# Makefile for t3_cowriter TYPO3 Extension
# AI-powered content writing assistant for TYPO3 CKEditor

.PHONY: help
help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

# ===================================
# DDEV Environment Commands
# ===================================

.PHONY: up
up: start setup ## Complete startup (start DDEV + run setup)

.PHONY: start
start: ## Start DDEV environment
	ddev start

.PHONY: stop
stop: ## Stop DDEV environment
	ddev stop

.PHONY: setup
setup: ## Complete setup (install TYPO3 v14)
	@ddev describe >/dev/null 2>&1 || ddev start
	ddev install-all

.PHONY: install-v14
install-v14: ## Install TYPO3 v14 with extension
	ddev install-v14

.PHONY: install-all
install-all: ## Install all TYPO3 versions (v14)
	ddev install-all

.PHONY: ddev-restart
ddev-restart: ## Restart DDEV containers
	ddev restart

.PHONY: ssh
ssh: ## SSH into DDEV web container
	ddev ssh

# ===================================
# Composer & Quality Commands
# ===================================

.PHONY: install
install: ## Install composer dependencies
	composer install

.PHONY: cgl
cgl: ## Check code style (dry-run)
	composer ci:test:php:cgl

.PHONY: cgl-fix
cgl-fix: ## Fix code style
	composer ci:cgl

.PHONY: phpstan
phpstan: ## Run PHPStan static analysis
	composer ci:test:php:phpstan

.PHONY: rector
rector: ## Run Rector dry-run
	composer ci:test:php:rector

.PHONY: lint
lint: ## Run all linters (PHP syntax + PHPStan + code style)
	@echo "==> Running PHP lint..."
	composer ci:test:php:lint
	@echo "==> Running PHPStan..."
	composer ci:test:php:phpstan
	@echo "==> Running code style check..."
	composer ci:test:php:cgl
	@echo "==> Running Rector check..."
	composer ci:test:php:rector
	@echo "All linters passed"

.PHONY: ci
ci: ## Run complete CI pipeline (pre-commit checks)
	composer ci:test

# ===================================
# Test Commands
# ===================================

.PHONY: test
test: test-unit test-integration test-e2e test-functional ## Run all tests

.PHONY: test-unit
test-unit: ## Run unit tests
	composer ci:test:php:unit

.PHONY: test-functional
test-functional: ## Run functional tests
	composer ci:test:php:functional

.PHONY: test-integration
test-integration: ## Run integration tests
	composer ci:test:php:integration

.PHONY: test-e2e
test-e2e: ## Run E2E tests
	composer ci:test:php:e2e

.PHONY: test-coverage
test-coverage: ## Generate test coverage report (unit + integration)
	ddev exec -d /var/www/t3_cowriter php -d pcov.enabled=1 .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --coverage-html var/coverage/unit
	ddev exec -d /var/www/t3_cowriter php -d pcov.enabled=1 .Build/bin/phpunit -c Build/phpunit/IntegrationTests.xml --coverage-html var/coverage/integration

# ===================================
# Cleanup
# ===================================

.PHONY: clean
clean: ## Clean temporary files and caches
	rm -rf .php-cs-fixer.cache
	rm -rf var/
	rm -rf .Build/.cache
	rm -rf .phplint.cache

# ===================================
# Documentation
# ===================================

.PHONY: docs
docs: ## Render TYPO3 extension documentation
	ddev docs

.PHONY: docs-clean
docs-clean: ## Clean generated documentation
	rm -rf Documentation-GENERATED-temp

# ===================================
# Extension-Specific Commands
# ===================================

.PHONY: urls
urls: ## Show all access URLs
	@echo ""
	@echo "t3_cowriter - Access URLs"
	@echo "========================="
	@echo ""
	@echo "TYPO3 v14:"
	@echo "  Frontend: https://v14.t3-cowriter.ddev.site/"
	@echo "  Backend:  https://v14.t3-cowriter.ddev.site/typo3/"
	@echo ""
	@echo "Documentation:"
	@echo "  Docs:     https://docs.t3-cowriter.ddev.site/"
	@echo ""
	@echo "Backend Credentials:"
	@echo "  Username: admin"
	@echo "  Password: Joh316!"
	@echo ""

.DEFAULT_GOAL := help
