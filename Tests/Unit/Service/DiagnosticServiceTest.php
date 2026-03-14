<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\T3Cowriter\Service\DiagnosticService;
use Netresearch\T3Cowriter\Service\Dto\Severity;
use Netresearch\T3Cowriter\Tests\Support\TestQueryResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(DiagnosticService::class)]
#[CoversClass(\Netresearch\T3Cowriter\Service\Dto\DiagnosticCheck::class)]
#[CoversClass(\Netresearch\T3Cowriter\Service\Dto\DiagnosticResult::class)]
#[CoversClass(Severity::class)]
final class DiagnosticServiceTest extends TestCase
{
    private ProviderRepository&Stub $providerRepoStub;
    private ModelRepository&Stub $modelRepoStub;
    private LlmConfigurationRepository&Stub $configRepoStub;

    protected function setUp(): void
    {
        $this->providerRepoStub = $this->createStub(ProviderRepository::class);
        $this->modelRepoStub    = $this->createStub(ModelRepository::class);
        $this->configRepoStub   = $this->createStub(LlmConfigurationRepository::class);
    }

    #[Test]
    public function runAllReturnsOkWhenEverythingIsConfigured(): void
    {
        $provider = $this->createStub(Provider::class);
        $provider->method('hasApiKey')->willReturn(true);

        $config = $this->createStub(LlmConfiguration::class);
        $config->method('getName')->willReturn('Default');

        $this->providerRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([$provider]));
        $this->providerRepoStub->method('countActive')->willReturn(1);
        $this->providerRepoStub->method('findActive')
            ->willReturn(new TestQueryResult([$provider]));

        $this->modelRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([new stdClass()]));
        $this->modelRepoStub->method('countActive')->willReturn(1);

        $this->configRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([$config]));
        $this->configRepoStub->method('countActive')->willReturn(1);
        $this->configRepoStub->method('findDefault')->willReturn($config);

        $service = $this->createService();
        $result  = $service->runAll();

        self::assertTrue($result->ok);
        self::assertCount(8, $result->checks);
        self::assertNull($result->getFirstFailure());

        foreach ($result->checks as $check) {
            self::assertTrue($check->passed);
            self::assertSame(Severity::Ok, $check->severity);
            self::assertNull($check->fixRoute, sprintf('Check "%s" should have null fixRoute when passed', $check->key));
        }

        self::assertSame('1 provider(s) configured.', $result->checks[0]->message);
        self::assertSame('Default configuration: "Default".', $result->checks[7]->message);
    }

    #[Test]
    public function runFirstStopsAtFirstFailure(): void
    {
        $this->providerRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([]));

        $service = $this->createService();
        $result  = $service->runFirst();

        self::assertFalse($result->ok);
        self::assertCount(1, $result->checks);
        self::assertSame('provider_exists', $result->checks[0]->key);
        self::assertFalse($result->checks[0]->passed);
        self::assertSame(Severity::Error, $result->checks[0]->severity);
        self::assertSame(
            'No LLM provider configured. Create a provider in Admin Tools > LLM > Providers.',
            $result->checks[0]->message,
        );
        self::assertSame('nrllm_providers', $result->checks[0]->fixRoute);
    }

    #[Test]
    public function runFirstDetectsNoActiveProvider(): void
    {
        $provider = $this->createStub(Provider::class);

        $this->providerRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([$provider]));
        $this->providerRepoStub->method('countActive')->willReturn(0);

        $service = $this->createService();
        $result  = $service->runFirst();

        self::assertFalse($result->ok);
        $failure = $result->getFirstFailure();
        self::assertNotNull($failure);
        self::assertSame('provider_active', $failure->key);
        self::assertSame('No active provider. Activate a provider in Admin Tools > LLM > Providers.', $failure->message);
    }

    #[Test]
    public function runFirstDetectsProviderWithoutApiKey(): void
    {
        $provider = $this->createStub(Provider::class);
        $provider->method('hasApiKey')->willReturn(false);
        $provider->method('getName')->willReturn('OpenAI');

        $this->providerRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([$provider]));
        $this->providerRepoStub->method('countActive')->willReturn(1);
        $this->providerRepoStub->method('findActive')
            ->willReturn(new TestQueryResult([$provider]));

        $service = $this->createService();
        $result  = $service->runFirst();

        self::assertFalse($result->ok);
        $failure = $result->getFirstFailure();
        self::assertNotNull($failure);
        self::assertSame('provider_has_api_key', $failure->key);
        self::assertSame('Provider "OpenAI" has no API key. Add one in Admin Tools > LLM > Providers.', $failure->message);
    }

    #[Test]
    public function runFirstDetectsNoActiveProviderWithApiKey(): void
    {
        $this->providerRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([$this->createStub(Provider::class)]));
        $this->providerRepoStub->method('countActive')->willReturn(1);
        $this->providerRepoStub->method('findActive')
            ->willReturn(new TestQueryResult([]));

        $service = $this->createService();
        $result  = $service->runFirst();

        self::assertFalse($result->ok);
        $failure = $result->getFirstFailure();
        self::assertNotNull($failure);
        self::assertSame('provider_has_api_key', $failure->key);
        self::assertSame('No active provider has an API key. Add one in Admin Tools > LLM > Providers.', $failure->message);
    }

    #[Test]
    public function runAllDetectsNoDefaultConfiguration(): void
    {
        $provider = $this->createStub(Provider::class);
        $provider->method('hasApiKey')->willReturn(true);

        $config = $this->createStub(LlmConfiguration::class);

        $this->providerRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([$provider]));
        $this->providerRepoStub->method('countActive')->willReturn(1);
        $this->providerRepoStub->method('findActive')
            ->willReturn(new TestQueryResult([$provider]));

        $this->modelRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([new stdClass()]));
        $this->modelRepoStub->method('countActive')->willReturn(1);

        $this->configRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([$config]));
        $this->configRepoStub->method('countActive')->willReturn(1);
        $this->configRepoStub->method('findDefault')->willReturn(null);

        $service = $this->createService();
        $result  = $service->runAll();

        self::assertFalse($result->ok);
        $failure = $result->getFirstFailure();
        self::assertNotNull($failure);
        self::assertSame('configuration_default', $failure->key);
        self::assertSame('No default LLM configuration. Mark one as default in Admin Tools > LLM > Configurations.', $failure->message);
        self::assertSame('nrllm_configurations', $failure->fixRoute);
    }

    #[Test]
    public function runAllSetsErrorSeverityAndFixRouteWhenNoActiveProvider(): void
    {
        $provider = $this->createStub(Provider::class);

        $this->providerRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([$provider]));
        $this->providerRepoStub->method('countActive')->willReturn(0);

        $this->modelRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([new stdClass()]));
        $this->modelRepoStub->method('countActive')->willReturn(1);

        $config = $this->createStub(LlmConfiguration::class);
        $config->method('getName')->willReturn('Default');
        $this->configRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([$config]));
        $this->configRepoStub->method('countActive')->willReturn(1);
        $this->configRepoStub->method('findDefault')->willReturn($config);

        $service = $this->createService();
        $result  = $service->runAll();

        self::assertFalse($result->ok);

        // Find the provider_active check specifically
        $providerActiveCheck = null;
        foreach ($result->checks as $check) {
            if ($check->key === 'provider_active') {
                $providerActiveCheck = $check;

                break;
            }
        }

        self::assertNotNull($providerActiveCheck);
        self::assertFalse($providerActiveCheck->passed);
        self::assertSame(Severity::Error, $providerActiveCheck->severity);
        self::assertSame('nrllm_providers', $providerActiveCheck->fixRoute);
    }

    #[Test]
    public function runAllSetsErrorSeverityAndFixRouteWhenNoActiveModel(): void
    {
        $provider = $this->createStub(Provider::class);
        $provider->method('hasApiKey')->willReturn(true);

        $this->providerRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([$provider]));
        $this->providerRepoStub->method('countActive')->willReturn(1);
        $this->providerRepoStub->method('findActive')
            ->willReturn(new TestQueryResult([$provider]));

        $this->modelRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([new stdClass()]));
        $this->modelRepoStub->method('countActive')->willReturn(0);

        $config = $this->createStub(LlmConfiguration::class);
        $config->method('getName')->willReturn('Default');
        $this->configRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([$config]));
        $this->configRepoStub->method('countActive')->willReturn(1);
        $this->configRepoStub->method('findDefault')->willReturn($config);

        $service = $this->createService();
        $result  = $service->runAll();

        self::assertFalse($result->ok);

        $modelActiveCheck = null;
        foreach ($result->checks as $check) {
            if ($check->key === 'model_active') {
                $modelActiveCheck = $check;

                break;
            }
        }

        self::assertNotNull($modelActiveCheck);
        self::assertFalse($modelActiveCheck->passed);
        self::assertSame(Severity::Error, $modelActiveCheck->severity);
        self::assertSame('nrllm_models', $modelActiveCheck->fixRoute);
    }

    #[Test]
    public function runAllSetsErrorSeverityAndFixRouteWhenNoActiveConfiguration(): void
    {
        $provider = $this->createStub(Provider::class);
        $provider->method('hasApiKey')->willReturn(true);

        $this->providerRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([$provider]));
        $this->providerRepoStub->method('countActive')->willReturn(1);
        $this->providerRepoStub->method('findActive')
            ->willReturn(new TestQueryResult([$provider]));

        $this->modelRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([new stdClass()]));
        $this->modelRepoStub->method('countActive')->willReturn(1);

        $config = $this->createStub(LlmConfiguration::class);
        $config->method('getName')->willReturn('Default');
        $this->configRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([$config]));
        $this->configRepoStub->method('countActive')->willReturn(0);
        $this->configRepoStub->method('findDefault')->willReturn($config);

        $service = $this->createService();
        $result  = $service->runAll();

        self::assertFalse($result->ok);

        $configActiveCheck = null;
        foreach ($result->checks as $check) {
            if ($check->key === 'configuration_active') {
                $configActiveCheck = $check;

                break;
            }
        }

        self::assertNotNull($configActiveCheck);
        self::assertFalse($configActiveCheck->passed);
        self::assertSame(Severity::Error, $configActiveCheck->severity);
        self::assertSame('nrllm_configurations', $configActiveCheck->fixRoute);
    }

    #[Test]
    public function getFirstFailureMessageReturnsEmptyWhenAllPass(): void
    {
        $provider = $this->createStub(Provider::class);
        $provider->method('hasApiKey')->willReturn(true);

        $config = $this->createStub(LlmConfiguration::class);
        $config->method('getName')->willReturn('Default');

        $this->providerRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([$provider]));
        $this->providerRepoStub->method('countActive')->willReturn(1);
        $this->providerRepoStub->method('findActive')
            ->willReturn(new TestQueryResult([$provider]));

        $this->modelRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([new stdClass()]));
        $this->modelRepoStub->method('countActive')->willReturn(1);

        $this->configRepoStub->method('findAll')
            ->willReturn(new TestQueryResult([$config]));
        $this->configRepoStub->method('countActive')->willReturn(1);
        $this->configRepoStub->method('findDefault')->willReturn($config);

        $service = $this->createService();
        $result  = $service->runFirst();

        self::assertSame('', $result->getFirstFailureMessage());
    }

    private function createService(): DiagnosticService
    {
        return new DiagnosticService(
            $this->providerRepoStub,
            $this->modelRepoStub,
            $this->configRepoStub,
        );
    }
}
