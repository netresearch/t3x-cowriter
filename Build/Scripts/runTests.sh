#!/usr/bin/env bash

#
# TYPO3 Extension Test Runner - t3_cowriter
# Based on TYPO3 Best Practices: https://github.com/TYPO3BestPractices/tea
#
# This script provides a unified interface for running various test suites
# and quality tools for the t3_cowriter extension.
#
# Usage: ./Build/Scripts/runTests.sh [options] <command>
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Extension root directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# Composer binary
VENDOR_BIN="${ROOT_DIR}/.Build/bin"

# Default values
PHP_VERSION="${PHP_VERSION:-8.5}"
DBMS="${DBMS:-sqlite}"
EXTRA_TEST_OPTIONS=""
COVERAGE=""

#
# Print usage information
#
usage() {
    cat << EOF
TYPO3 Extension Test Runner - t3_cowriter

Usage: $(basename "$0") [OPTIONS] <COMMAND>

Commands:
    unit              Run unit tests
    functional        Run functional tests
    mutation          Run mutation tests with Infection
    phpstan           Run PHPStan static analysis
    cgl               Run PHP-CS-Fixer in dry-run mode
    cgl:fix           Run PHP-CS-Fixer and apply fixes
    rector            Run Rector in dry-run mode
    rector:fix        Run Rector and apply changes
    ci                Run full CI suite (cgl, phpstan, unit)
    all               Run all tests and quality checks

Options:
    -h, --help        Show this help message
    -v, --verbose     Verbose output
    -c, --coverage    Generate code coverage report
    -p, --php         PHP version (default: ${PHP_VERSION})
    -d, --dbms        Database system for functional tests (default: ${DBMS})
                      Options: sqlite, mysql, mariadb, postgres
    -x                Extra options to pass to PHPUnit

Examples:
    $(basename "$0") unit
    $(basename "$0") -c unit                  # With coverage
    $(basename "$0") -x "--filter=testName" unit
    $(basename "$0") ci

EOF
}

#
# Print colored message
#
info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

#
# Check if composer dependencies are installed
#
check_dependencies() {
    if [[ ! -d "${ROOT_DIR}/.Build/vendor" ]]; then
        error "Dependencies not installed. Run 'composer install' first."
        exit 1
    fi
}

#
# Run unit tests
#
run_unit_tests() {
    info "Running unit tests..."
    check_dependencies

    local coverage_opts=""
    if [[ -n "${COVERAGE}" ]]; then
        coverage_opts="--coverage-html ${ROOT_DIR}/.Build/coverage/html --coverage-clover ${ROOT_DIR}/.Build/coverage/clover.xml"
        mkdir -p "${ROOT_DIR}/.Build/coverage"
    fi

    "${VENDOR_BIN}/phpunit" -c "${ROOT_DIR}/Build/phpunit/UnitTests.xml" ${coverage_opts} ${EXTRA_TEST_OPTIONS}
    success "Unit tests completed"

    if [[ -n "${COVERAGE}" ]]; then
        info "Coverage report: ${ROOT_DIR}/.Build/coverage/html/index.html"
    fi
}

#
# Run functional tests
#
run_functional_tests() {
    info "Running functional tests with DBMS=${DBMS}..."
    check_dependencies

    export typo3DatabaseDriver="pdo_sqlite"

    if [[ -f "${ROOT_DIR}/Build/phpunit/FunctionalTests.xml" ]]; then
        "${VENDOR_BIN}/phpunit" -c "${ROOT_DIR}/Build/phpunit/FunctionalTests.xml" ${EXTRA_TEST_OPTIONS}
    else
        warning "FunctionalTests.xml not found, skipping..."
    fi
    success "Functional tests completed"
}

#
# Run mutation tests
#
run_mutation_tests() {
    info "Running mutation tests with Infection..."
    check_dependencies

    if [[ -f "${ROOT_DIR}/Build/infection.json" ]]; then
        "${VENDOR_BIN}/infection" --configuration="${ROOT_DIR}/Build/infection.json" --threads=4 -s --no-progress
    else
        warning "infection.json not found in Build/, skipping..."
    fi
    success "Mutation tests completed"
}

#
# Run PHPStan
#
run_phpstan() {
    info "Running PHPStan static analysis..."
    check_dependencies
    "${VENDOR_BIN}/phpstan" analyse -c "${ROOT_DIR}/Build/phpstan.neon"
    success "PHPStan analysis completed"
}

#
# Run PHP-CS-Fixer (dry-run)
#
run_cgl() {
    info "Running PHP-CS-Fixer (dry-run)..."
    check_dependencies
    "${VENDOR_BIN}/php-cs-fixer" fix --dry-run --diff --config="${ROOT_DIR}/Build/.php-cs-fixer.dist.php"
    success "CGL check completed"
}

#
# Run PHP-CS-Fixer (fix)
#
run_cgl_fix() {
    info "Running PHP-CS-Fixer (applying fixes)..."
    check_dependencies
    "${VENDOR_BIN}/php-cs-fixer" fix --config="${ROOT_DIR}/Build/.php-cs-fixer.dist.php"
    success "CGL fixes applied"
}

#
# Run Rector (dry-run)
#
run_rector() {
    info "Running Rector (dry-run)..."
    check_dependencies
    "${VENDOR_BIN}/rector" process --config "${ROOT_DIR}/Build/rector.php" --dry-run
    success "Rector analysis completed"
}

#
# Run Rector (fix)
#
run_rector_fix() {
    info "Running Rector (applying changes)..."
    check_dependencies
    "${VENDOR_BIN}/rector" process --config "${ROOT_DIR}/Build/rector.php"
    success "Rector changes applied"
}

#
# Run CI suite
#
run_ci() {
    info "Running CI suite..."
    run_cgl
    run_phpstan
    run_rector
    run_unit_tests
    success "CI suite completed"
}

#
# Run all tests and checks
#
run_all() {
    info "Running all tests and quality checks..."
    run_cgl
    run_phpstan
    run_rector
    run_unit_tests
    run_functional_tests
    success "All tests and checks completed"
}

#
# Parse command line arguments
#
parse_args() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            -h|--help)
                usage
                exit 0
                ;;
            -v|--verbose)
                set -x
                shift
                ;;
            -c|--coverage)
                COVERAGE="1"
                shift
                ;;
            -p|--php)
                PHP_VERSION="$2"
                shift 2
                ;;
            -d|--dbms)
                DBMS="$2"
                shift 2
                ;;
            -x)
                EXTRA_TEST_OPTIONS="$2"
                shift 2
                ;;
            unit)
                run_unit_tests
                exit 0
                ;;
            functional)
                run_functional_tests
                exit 0
                ;;
            mutation)
                run_mutation_tests
                exit 0
                ;;
            phpstan)
                run_phpstan
                exit 0
                ;;
            cgl)
                run_cgl
                exit 0
                ;;
            cgl:fix)
                run_cgl_fix
                exit 0
                ;;
            rector)
                run_rector
                exit 0
                ;;
            rector:fix)
                run_rector_fix
                exit 0
                ;;
            ci)
                run_ci
                exit 0
                ;;
            all)
                run_all
                exit 0
                ;;
            *)
                error "Unknown option or command: $1"
                usage
                exit 1
                ;;
        esac
    done

    # No command provided
    usage
    exit 1
}

#
# Main entry point
#
main() {
    cd "${ROOT_DIR}"

    if [[ $# -eq 0 ]]; then
        usage
        exit 1
    fi

    parse_args "$@"
}

main "$@"
