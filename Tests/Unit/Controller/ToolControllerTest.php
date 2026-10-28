<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\T3Cowriter\Controller\ToolController;
use Netresearch\T3Cowriter\Service\BudgetRefusal;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Tests\Support\ConfigurationAccessDouble;
use Netresearch\T3Cowriter\Tests\Support\XliffLanguageServiceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Context\Context;

#[CoversClass(ToolController::class)]
final class ToolControllerTest extends TestCase
{
    use XliffLanguageServiceTrait;

    private ToolLoopServiceInterface&Stub $toolLoopServiceStub;

    private LlmConfigurationRepository&Stub $configRepositoryStub;

    private RateLimiterInterface&Stub $rateLimiterStub;

    private ToolController $subject;

    protected function setUp(): void
    {
        $this->useXliffLanguageService();
        $this->toolLoopServiceStub  = $this->createStub(ToolLoopServiceInterface::class);
        $this->configRepositoryStub = $this->createStub(LlmConfigurationRepository::class);
        $this->rateLimiterStub      = $this->createStub(RateLimiterInterface::class);
        $contextStub                = $this->createStub(Context::class);
        $contextStub->method('getPropertyFromAspect')->willReturn(1);

        // Default: a configuration exists and the loop returns a plain result.
        $this->configRepositoryStub->method('findDefault')->willReturn($this->createStub(LlmConfiguration::class));
        $this->toolLoopServiceStub->method('runLoop')->willReturn($this->loopResult('Result'));

        $this->subject = new ToolController(
            $this->toolLoopServiceStub,
            ConfigurationAccessDouble::selector($this->configRepositoryStub),
            $this->rateLimiterStub,
            $contextStub,
            new NullLogger(),
        );
    }

    #[Test]
    public function executeActionReturns429WithHeadersWhenRateLimited(): void
    {
        $resetTime = time() + 60;
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(false, 20, 0, $resetTime));

        $response = $this->subject->executeAction($this->createJsonRequest(['prompt' => 'Find text elements']));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function executeActionReturns400ForInvalidJson(): void
    {
        $this->allowRateLimit();

        $response = $this->subject->executeAction($this->createRawRequest('{bad json'));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
    }

    #[Test]
    public function executeActionReturns400ForEmptyPrompt(): void
    {
        $this->allowRateLimit();

        $response = $this->subject->executeAction($this->createJsonRequest(['prompt' => '']));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('Missing prompt', $data['error']);
    }

    #[Test]
    public function executeActionRunsTheLoopAndReturnsFinalContent(): void
    {
        $this->allowRateLimit();

        $response = $this->subject->executeAction($this->createJsonRequest(['prompt' => 'What is on page 5?']));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertSame('Result', $data['content']);
        self::assertSame(2, $data['iterations']);
        self::assertSame(30, $data['usage']['totalTokens']);
    }

    #[Test]
    public function executeActionPassesRequestedToolsAsAllowedNames(): void
    {
        $this->allowRateLimit();

        $captured = 'unset';
        $this->toolLoopServiceStub->method('runLoop')
            ->willReturnCallback(function (array $messages, LlmConfiguration $config, ToolExecutionContext $context, ?array $allowed) use (&$captured): ToolLoopResult {
                $captured = $allowed;

                return $this->loopResult('ok');
            });

        $this->subject->executeAction($this->createJsonRequest([
            'prompt' => 'Query',
            'tools'  => ['query_content'],
        ]));

        self::assertSame(['query_content'], $captured);
    }

    #[Test]
    public function executeActionNamesThisExtensionAndOperationAsCallerSource(): void
    {
        $this->allowRateLimit();

        $captured = null;
        $this->toolLoopServiceStub->method('runLoop')
            ->willReturnCallback(function (
                array $messages,
                LlmConfiguration $config,
                ToolExecutionContext $context,
                ?array $allowed,
                ?ToolOptions $options = null,
            ) use (&$captured): ToolLoopResult {
                $captured = $options;

                return $this->loopResult('ok');
            });

        $this->subject->executeAction($this->createJsonRequest(['prompt' => 'Query']));

        self::assertInstanceOf(ToolOptions::class, $captured);
        self::assertSame('t3_cowriter', $captured->getCallerSourceExtension());
        self::assertSame('toolCall', $captured->getCallerSourceOperation());
    }

    #[Test]
    public function executeActionRunsTheLoopUnderTheBudgetOfTheBackendUser(): void
    {
        $this->allowRateLimit();

        $captured = null;
        $this->toolLoopServiceStub->method('runLoop')
            ->willReturnCallback(function (
                array $messages,
                LlmConfiguration $config,
                ToolExecutionContext $context,
                ?array $allowed,
                ?ToolOptions $options = null,
            ) use (&$captured): ToolLoopResult {
                $captured = $options;

                return $this->loopResult('ok');
            });

        $this->subject->executeAction($this->createJsonRequest(['prompt' => 'Query']));

        self::assertInstanceOf(ToolOptions::class, $captured);
        // The context stub reports backend user 1.
        self::assertSame(1, $captured->getBeUserUid());
    }

    #[Test]
    public function executeActionMapsAbsentToolsToNullAllowedNames(): void
    {
        $this->allowRateLimit();

        $captured = 'unset';
        $this->toolLoopServiceStub->method('runLoop')
            ->willReturnCallback(function (array $messages, LlmConfiguration $config, ToolExecutionContext $context, ?array $allowed) use (&$captured): ToolLoopResult {
                $captured = $allowed;

                return $this->loopResult('ok');
            });

        $this->subject->executeAction($this->createJsonRequest(['prompt' => 'Query']));

        self::assertNull($captured);
    }

    #[Test]
    public function executeActionPassesToolExecutionContextAsThirdArgument(): void
    {
        $this->allowRateLimit();

        $captured = null;
        $this->toolLoopServiceStub->method('runLoop')
            ->willReturnCallback(function (array $messages, LlmConfiguration $config, ToolExecutionContext $context) use (&$captured): ToolLoopResult {
                $captured = $context;

                return $this->loopResult('ok');
            });

        $this->subject->executeAction($this->createJsonRequest(['prompt' => 'Query']));

        self::assertInstanceOf(ToolExecutionContext::class, $captured);
    }

    #[Test]
    public function executeActionReturns404ForUnknownRequestedConfiguration(): void
    {
        $this->allowRateLimit();
        // A requested id that does not resolve → error, not silent fallback.
        $this->configRepositoryStub->method('findOneByIdentifier')->willReturn(null);

        $response = $this->subject->executeAction($this->createJsonRequest([
            'prompt'        => 'Query',
            'configuration' => 'ghost',
        ]));

        self::assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('The selected LLM configuration does not exist or is inactive.', $data['error']);
    }

    #[Test]
    public function executeActionReturns404WhenNoConfigurationIsAvailable(): void
    {
        $contextStub = $this->createStub(Context::class);
        $contextStub->method('getPropertyFromAspect')->willReturn(1);

        $configRepository = $this->createStub(LlmConfigurationRepository::class);
        $configRepository->method('findDefault')->willReturn(null);

        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $subject = new ToolController(
            $this->toolLoopServiceStub,
            ConfigurationAccessDouble::selector($configRepository),
            $rateLimiter,
            $contextStub,
            new NullLogger(),
        );

        $response = $subject->executeAction($this->createJsonRequest(['prompt' => 'Query']));

        self::assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
    }

    #[Test]
    public function aLoopTheBudgetStoppedIsA429NamingTheLimitTheBudgetReports(): void
    {
        $budget = $this->createMock(BudgetServiceInterface::class);
        $budget->expects(self::once())->method('check')
            ->with(1, 0.0, self::isInstanceOf(LlmConfiguration::class))
            ->willReturn(BudgetCheckResult::denied(BudgetCheckResult::LIMIT_DAILY_REQUESTS, 20.0, 20.0));

        $response = $this->budgetStoppedSubject(new BudgetRefusal($budget))
            ->executeAction($this->createJsonRequest(['prompt' => 'Query']));

        self::assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('The request was refused. This limit is used up: your daily number of requests.', $data['error']);
        self::assertTrue($data['budgetExceeded']);
        self::assertSame(BudgetCheckResult::LIMIT_DAILY_REQUESTS, $data['exceededLimit']);
    }

    #[Test]
    public function aLoopTheBudgetStoppedIsA429WithoutNamedLimitWhenTheBudgetCannotBeAsked(): void
    {
        $response = $this->budgetStoppedSubject(new BudgetRefusal())
            ->executeAction($this->createJsonRequest(['prompt' => 'Query']));

        self::assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('The request was refused because a limit of your AI budget is used up.', $data['error']);
    }

    #[Test]
    public function aRefusalTheLoopThrowsIsA429(): void
    {
        $this->allowRateLimit();
        $loop = $this->createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willThrowException(new BudgetExceededException(
            BudgetCheckResult::denied(BudgetCheckResult::LIMIT_MONTHLY_TOKENS, 1.0, 1.0),
        ));
        $context = $this->createStub(Context::class);
        $context->method('getPropertyFromAspect')->willReturn(1);

        $response = (new ToolController($loop, ConfigurationAccessDouble::selector($this->configRepositoryStub), $this->rateLimiterStub, $context, new NullLogger()))
            ->executeAction($this->createJsonRequest(['prompt' => 'Query']));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame(
            'The request was refused. This limit is used up: your monthly number of tokens.',
            json_decode((string) $response->getBody(), true)['error'],
        );
    }

    private function budgetStoppedSubject(BudgetRefusal $refusal): ToolController
    {
        $this->allowRateLimit();
        $loop = $this->createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturn(
            new ToolLoopResult('', [], 1, true, new UsageStatistics(0, 0, 0), AgentRunTerminationReason::BUDGET_EXHAUSTED),
        );
        $context = $this->createStub(Context::class);
        $context->method('getPropertyFromAspect')->willReturn(1);

        return new ToolController(
            $loop,
            ConfigurationAccessDouble::selector($this->configRepositoryStub),
            $this->rateLimiterStub,
            $context,
            new NullLogger(),
            budgetRefusal: $refusal,
        );
    }

    private function allowRateLimit(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));
    }

    private function loopResult(string $content): ToolLoopResult
    {
        return new ToolLoopResult($content, [], 2, false, new UsageStatistics(20, 10, 30));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createJsonRequest(array $body): ServerRequestInterface&Stub
    {
        return $this->createRawRequest(json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function createRawRequest(string $rawBody): ServerRequestInterface&Stub
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($rawBody);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($stream);

        return $request;
    }
}
