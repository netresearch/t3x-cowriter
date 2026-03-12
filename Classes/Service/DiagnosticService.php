<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\T3Cowriter\Service\Dto\DiagnosticCheck;
use Netresearch\T3Cowriter\Service\Dto\DiagnosticResult;
use Netresearch\T3Cowriter\Service\Dto\Severity;

/**
 * Checks the LLM configuration chain and reports specific failures.
 */
readonly class DiagnosticService
{
    public function __construct(
        private ProviderRepository $providerRepository,
        private ModelRepository $modelRepository,
        private LlmConfigurationRepository $configurationRepository,
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
        $ok = true;

        foreach ($this->getCheckCallbacks() as $callback) {
            $check = $callback();
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
     * @return list<\Closure(): DiagnosticCheck>
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
        /** @var \Countable $result */
        $result = $this->providerRepository->findAll();
        $count  = $result->count();

        return new DiagnosticCheck(
            key: 'provider_exists',
            passed: $count > 0,
            message: $count > 0
                ? sprintf('%d provider(s) configured.', $count)
                : 'No LLM provider configured. Create a provider in Admin Tools > LLM > Providers.',
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
                ? sprintf('%d active provider(s).', $activeCount)
                : 'No active provider. Activate a provider in Admin Tools > LLM > Providers.',
            severity: $activeCount > 0 ? Severity::Ok : Severity::Error,
            fixRoute: $activeCount > 0 ? null : 'nrllm_providers',
        );
    }

    private function checkProviderHasApiKey(): DiagnosticCheck
    {
        $providers = $this->providerRepository->findActive();
        $withKey = 0;
        $withoutKey = '';

        foreach ($providers as $provider) {
            if ($provider->hasApiKey()) {
                $withKey++;
            } elseif ($withoutKey === '') {
                $withoutKey = $provider->getName();
            }
        }

        $passed = $withKey > 0;

        return new DiagnosticCheck(
            key: 'provider_has_api_key',
            passed: $passed,
            message: $passed
                ? sprintf('%d provider(s) with API key.', $withKey)
                : sprintf('Provider "%s" has no API key. Add one in Admin Tools > LLM > Providers.', $withoutKey),
            severity: $passed ? Severity::Ok : Severity::Error,
            fixRoute: $passed ? null : 'nrllm_providers',
        );
    }

    private function checkModelExists(): DiagnosticCheck
    {
        /** @var \Countable $result */
        $result = $this->modelRepository->findAll();
        $count  = $result->count();

        return new DiagnosticCheck(
            key: 'model_exists',
            passed: $count > 0,
            message: $count > 0
                ? sprintf('%d model(s) configured.', $count)
                : 'No LLM model configured. Create a model in Admin Tools > LLM > Models.',
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
                ? sprintf('%d active model(s).', $activeCount)
                : 'No active model. Activate a model in Admin Tools > LLM > Models.',
            severity: $activeCount > 0 ? Severity::Ok : Severity::Error,
            fixRoute: $activeCount > 0 ? null : 'nrllm_models',
        );
    }

    private function checkConfigurationExists(): DiagnosticCheck
    {
        /** @var \Countable $result */
        $result = $this->configurationRepository->findAll();
        $count  = $result->count();

        return new DiagnosticCheck(
            key: 'configuration_exists',
            passed: $count > 0,
            message: $count > 0
                ? sprintf('%d LLM configuration(s) created.', $count)
                : 'No LLM configuration created. Create one in Admin Tools > LLM > Configurations.',
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
                ? sprintf('%d active configuration(s).', $activeCount)
                : 'No active LLM configuration. Activate one in Admin Tools > LLM > Configurations.',
            severity: $activeCount > 0 ? Severity::Ok : Severity::Error,
            fixRoute: $activeCount > 0 ? null : 'nrllm_configurations',
        );
    }

    private function checkConfigurationDefault(): DiagnosticCheck
    {
        $default = $this->configurationRepository->findDefault();

        return new DiagnosticCheck(
            key: 'configuration_default',
            passed: $default !== null,
            message: $default !== null
                ? sprintf('Default configuration: "%s".', $default->getName())
                : 'No default LLM configuration. Mark one as default in Admin Tools > LLM > Configurations.',
            severity: $default !== null ? Severity::Ok : Severity::Error,
            fixRoute: $default !== null ? null : 'nrllm_configurations',
        );
    }
}
