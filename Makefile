# Makefile for t3_cowriter TYPO3 Extension
# AI-powered content writing assistant for TYPO3 CKEditor

.PHONY: help
help: ## Show available targets
	@awk 'BEGIN{FS=":.*##";print "\nUsage: make <target>\n"} /^[a-zA-Z0-9_.-]+:.*##/ {printf "  %-22s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# ===================================
# DDEV Environment Commands
# ===================================

.PHONY: up
up: start setup ## Complete startup (start DDEV + run setup) - ONE COMMAND TO RULE THEM ALL

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

.PHONY: format
format: ## Auto-fix code style issues (PSR-12)
	composer ci:php:rector
	composer ci:php:cgl

.PHONY: typecheck
typecheck: ## Run PHPStan static analysis
	composer ci:test:php:phpstan

.PHONY: test
test: test-unit test-integration test-e2e ## Run all tests (unit + integration + e2e)

.PHONY: test-unit
test-unit: ## Run unit tests only
	ddev exec -d /var/www/t3_cowriter .Build/bin/phpunit -c Build/phpunit/UnitTests.xml

.PHONY: test-integration
test-integration: ## Run integration tests only
	ddev exec -d /var/www/t3_cowriter .Build/bin/phpunit -c Build/phpunit/IntegrationTests.xml

.PHONY: test-e2e
test-e2e: ## Run E2E tests only
	ddev exec -d /var/www/t3_cowriter .Build/bin/phpunit -c Build/phpunit/E2ETests.xml

.PHONY: test-coverage
test-coverage: ## Generate test coverage report (unit + integration)
	ddev exec -d /var/www/t3_cowriter php -d pcov.enabled=1 .Build/bin/phpunit -c Build/phpunit/UnitTests.xml --coverage-html var/coverage/unit
	ddev exec -d /var/www/t3_cowriter php -d pcov.enabled=1 .Build/bin/phpunit -c Build/phpunit/IntegrationTests.xml --coverage-html var/coverage/integration

.PHONY: ci
ci: ## Run complete CI pipeline (pre-commit checks)
	composer ci:test

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
