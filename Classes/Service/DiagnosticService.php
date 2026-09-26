<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use Closure;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\T3Cowriter\Service\Dto\DiagnosticCheck;
use Netresearch\T3Cowriter\Service\Dto\DiagnosticResult;
use Netresearch\T3Cowriter\Service\Dto\Severity;

/**
 * Checks the LLM configuration chain and reports specific failures. The
 * messages are in the backend user's language (locallang_be.xlf, diagnostic.*).
 */
readonly class DiagnosticService
{
    public function __construct(
        private ProviderRepository $providerRepository,
        private ModelRepository $modelRepository,
        private LlmConfigurationRepository $configurationRepository,
        private BackendLabels $labels = new BackendLabels(),
    ) {}

    /**
     * Run all diagnostic checks and return full results.
     */
    public function runAll(): DiagnosticResult
    {
        return $this->run(stopOnFailure: false);
    }

    /**
     * Run checks until the first failure, then stop.
     */
    public function runFirst(): DiagnosticResult
    {
        return $this->run(stopOnFailure: true);
    }

    private function run(bool $stopOnFailure): DiagnosticResult
    {
        $checks = [];
        $ok     = true;

        foreach ($this->getCheckCallbacks() as $callback) {
            $check    = $callback();
            $checks[] = $check;

            if (!$check->passed) {
                $ok = false;
                if ($stopOnFailure) {
                    break;
                }
            }
        }

        return new DiagnosticResult($ok, $checks);
    }

    /**
     * @return list<Closure(): DiagnosticCheck>
     */
    private function getCheckCallbacks(): array
    {
        return [
            $this->checkProviderExists(...),
            $this->checkProviderActive(...),
            $this->checkProviderHasApiKey(...),
            $this->checkModelExists(...),
            $this->checkModelActive(...),
            $this->checkConfigurationExists(...),
            $this->checkConfigurationActive(...),
            $this->checkConfigurationDefault(...),
        ];
    }

    private function checkProviderExists(): DiagnosticCheck
    {
        $count = $this->providerRepository->findAll()->count();

        return new DiagnosticCheck(
            key: 'provider_exists',
            passed: $count > 0,
            message: $count > 0
                ? $this->labels->get('diagnostic.provider_exists.passed', $count)
                : $this->labels->get('diagnostic.provider_exists.failed'),
            severity: $count > 0 ? Severity::Ok : Severity::Error,
            fixRoute: $count > 0 ? null : 'nrllm_providers',
        );
    }

    private function checkProviderActive(): DiagnosticCheck
    {
        $activeCount = $this->providerRepository->countActive();

        return new DiagnosticCheck(
            key: 'provider_active',
            passed: $activeCount > 0,
            message: $activeCount > 0
                ? $this->labels->get('diagnostic.provider_active.passed', $activeCount)
                : $this->labels->get('diagnostic.provider_active.failed'),
            severity: $activeCount > 0 ? Severity::Ok : Severity::Error,
            fixRoute: $activeCount > 0 ? null : 'nrllm_providers',
        );
    }

    private function checkProviderHasApiKey(): DiagnosticCheck
    {
        $providers  = $this->providerRepository->findActive();
        $withKey    = 0;
        $withoutKey = '';

        foreach ($providers as $provider) {
            if ($provider->hasApiKey()) {
                ++$withKey;
            } elseif ($withoutKey === '') {
                $withoutKey = $provider->getName();
            }
        }

        if ($withKey === 0 && $withoutKey === '') {
            return new DiagnosticCheck(
                key: 'provider_has_api_key',
                passed: false,
                message: $this->labels->get('diagnostic.provider_has_api_key.none'),
                severity: Severity::Error,
                fixRoute: 'nrllm_providers',
            );
        }

        $passed = $withKey > 0;

        return new DiagnosticCheck(
            key: 'provider_has_api_key',
            passed: $passed,
            message: $passed
                ? $this->labels->get('diagnostic.provider_has_api_key.passed', $withKey)
                : $this->labels->get('diagnostic.provider_has_api_key.failed', $withoutKey),
            severity: $passed ? Severity::Ok : Severity::Error,
            fixRoute: $passed ? null : 'nrllm_providers',
        );
    }

    private function checkModelExists(): DiagnosticCheck
    {
        $count = $this->modelRepository->findAll()->count();

        return new DiagnosticCheck(
            key: 'model_exists',
            passed: $count > 0,
            message: $count > 0
                ? $this->labels->get('diagnostic.model_exists.passed', $count)
                : $this->labels->get('diagnostic.model_exists.failed'),
            severity: $count > 0 ? Severity::Ok : Severity::Error,
            fixRoute: $count > 0 ? null : 'nrllm_models',
        );
    }

    private function checkModelActive(): DiagnosticCheck
    {
        $activeCount = $this->modelRepository->countActive();

        return new DiagnosticCheck(
            key: 'model_active',
            passed: $activeCount > 0,
            message: $activeCount > 0
                ? $this->labels->get('diagnostic.model_active.passed', $activeCount)
                : $this->labels->get('diagnostic.model_active.failed'),
            severity: $activeCount > 0 ? Severity::Ok : Severity::Error,
            fixRoute: $activeCount > 0 ? null : 'nrllm_models',
        );
    }

    private function checkConfigurationExists(): DiagnosticCheck
    {
        $count = $this->configurationRepository->findAll()->count();

        return new DiagnosticCheck(
            key: 'configuration_exists',
            passed: $count > 0,
            message: $count > 0
                ? $this->labels->get('diagnostic.configuration_exists.passed', $count)
                : $this->labels->get('diagnostic.configuration_exists.failed'),
            severity: $count > 0 ? Severity::Ok : Severity::Error,
            fixRoute: $count > 0 ? null : 'nrllm_configurations',
        );
    }

    private function checkConfigurationActive(): DiagnosticCheck
    {
        $activeCount = $this->configurationRepository->countActive();

        return new DiagnosticCheck(
            key: 'configuration_active',
            passed: $activeCount > 0,
            message: $activeCount > 0
                ? $this->labels->get('diagnostic.configuration_active.passed', $activeCount)
                : $this->labels->get('diagnostic.configuration_active.failed'),
            severity: $activeCount > 0 ? Severity::Ok : Severity::Error,
            fixRoute: $activeCount > 0 ? null : 'nrllm_configurations',
        );
    }

    private function checkConfigurationDefault(): DiagnosticCheck
    {
        $default = $this->configurationRepository->findDefault();

        return new DiagnosticCheck(
            key: 'configuration_default',
            passed: $default instanceof LlmConfiguration,
            message: $default instanceof LlmConfiguration
                ? $this->labels->get('diagnostic.configuration_default.passed', $default->getName())
                : $this->labels->get('diagnostic.configuration_default.failed'),
            severity: $default instanceof LlmConfiguration ? Severity::Ok : Severity::Error,
            fixRoute: $default instanceof LlmConfiguration ? null : 'nrllm_configurations',
        );
    }
}
