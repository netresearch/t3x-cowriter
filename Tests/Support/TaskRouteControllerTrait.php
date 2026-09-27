<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Support;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\T3Cowriter\Controller\AjaxController;
use Netresearch\T3Cowriter\Service\ContextAssemblyServiceInterface;
use Netresearch\T3Cowriter\Service\DiagnosticService;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Service\Tool\UnattendedToolRunner;
use Psr\Log\NullLogger;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * For a PHPUnit TestCase: an AjaxController whose task route finds one active
 * task without a configuration of its own, runs it on an active default
 * configuration with the model "gpt-test", as backend user 1, and is never
 * rate-limited.
 */
trait TaskRouteControllerTrait
{
    private function taskRouteController(
        LlmServiceManagerInterface $llm,
        ?CompletionServiceInterface $completionService = null,
        ?UnattendedToolRunner $toolRunner = null,
    ): AjaxController {
        $configuration = $this->createStub(LlmConfiguration::class);
        $configuration->method('getIdentifier')->willReturn('default');
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('getModelId')->willReturn('gpt-test');
        $configurations = $this->createStub(LlmConfigurationRepository::class);
        $configurations->method('findDefault')->willReturn($configuration);

        $task = $this->createStub(Task::class);
        $task->method('isActive')->willReturn(true);
        $task->method('getConfiguration')->willReturn(null);
        $tasks = $this->createStub(TaskRepository::class);
        $tasks->method('findByUid')->willReturn($task);

        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(true, 20, 19, time() + 60));
        $context = $this->createStub(Context::class);
        $context->method('getPropertyFromAspect')->willReturn(1);

        return new AjaxController(
            $llm,
            ConfigurationAccessDouble::selector($configurations),
            $tasks,
            $rateLimiter,
            $context,
            new NullLogger(),
            $this->createStub(ContextAssemblyServiceInterface::class),
            $this->createStub(ConnectionPool::class),
            $this->createStub(BackendUriBuilder::class),
            $this->createStub(DiagnosticService::class),
            eventStream: new RecordingEventStream(),
            completionService: $completionService,
            toolRunner: $toolRunner,
        );
    }
}
