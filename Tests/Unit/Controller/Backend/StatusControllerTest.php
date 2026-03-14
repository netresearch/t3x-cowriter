<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller\Backend;

use Netresearch\T3Cowriter\Controller\Backend\StatusController;
use Netresearch\T3Cowriter\Service\DiagnosticService;
use Netresearch\T3Cowriter\Service\Dto\DiagnosticCheck;
use Netresearch\T3Cowriter\Service\Dto\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\Uri;

#[CoversClass(StatusController::class)]
final class StatusControllerTest extends TestCase
{
    #[Test]
    public function buildFixUrlsResolvesRoutesForFailedChecks(): void
    {
        $backendUriBuilder = $this->createMock(BackendUriBuilder::class);
        $backendUriBuilder
            ->method('buildUriFromRoute')
            ->willReturnMap([
                ['nrllm_providers', [], new Uri('/typo3/module/nrllm/providers')],
                ['nrllm_models', [], new Uri('/typo3/module/nrllm/models')],
            ]);

        $controller = new StatusController(
            $this->createMock(DiagnosticService::class),
            $backendUriBuilder,
            $this->createFinalClassProxy(ModuleTemplateFactory::class),
        );

        $checks = [
            new DiagnosticCheck('provider_exists', false, 'No provider.', Severity::Error, 'nrllm_providers'),
            new DiagnosticCheck('provider_active', true, '1 active.', Severity::Ok),
            new DiagnosticCheck('model_exists', false, 'No model.', Severity::Error, 'nrllm_models'),
            new DiagnosticCheck('dup_route', false, 'Dup.', Severity::Error, 'nrllm_providers'),
        ];

        $method = new ReflectionMethod(StatusController::class, 'buildFixUrls');
        $result = $method->invoke($controller, $checks);

        self::assertCount(2, $result);
        self::assertSame('/typo3/module/nrllm/providers', $result['nrllm_providers']);
        self::assertSame('/typo3/module/nrllm/models', $result['nrllm_models']);
    }

    #[Test]
    public function buildFixUrlsSkipsNullFixRoutes(): void
    {
        $backendUriBuilder = $this->createMock(BackendUriBuilder::class);
        $backendUriBuilder->expects(self::never())->method('buildUriFromRoute');

        $controller = new StatusController(
            $this->createMock(DiagnosticService::class),
            $backendUriBuilder,
            $this->createFinalClassProxy(ModuleTemplateFactory::class),
        );

        $checks = [
            new DiagnosticCheck('ok_check', true, 'All good.', Severity::Ok),
        ];

        $method = new ReflectionMethod(StatusController::class, 'buildFixUrls');
        $result = $method->invoke($controller, $checks);

        self::assertSame([], $result);
    }

    #[Test]
    public function buildFixUrlsCatchesRouteResolutionErrors(): void
    {
        $backendUriBuilder = $this->createMock(BackendUriBuilder::class);
        $backendUriBuilder
            ->method('buildUriFromRoute')
            ->willThrowException(new RuntimeException('Route not found'));

        $controller = new StatusController(
            $this->createMock(DiagnosticService::class),
            $backendUriBuilder,
            $this->createFinalClassProxy(ModuleTemplateFactory::class),
        );

        $checks = [
            new DiagnosticCheck('bad', false, 'Bad.', Severity::Error, 'nonexistent'),
        ];

        $method = new ReflectionMethod(StatusController::class, 'buildFixUrls');
        $result = $method->invoke($controller, $checks);

        self::assertSame([], $result);
    }

    /**
     * Create a proxy for a final class constructor parameter.
     * The parameter is not used in the tested methods.
     */
    private function createFinalClassProxy(string $className): object
    {
        $reflection = new ReflectionClass($className);

        return $reflection->newInstanceWithoutConstructor();
    }
}
