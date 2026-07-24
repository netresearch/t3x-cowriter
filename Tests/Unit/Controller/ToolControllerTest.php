<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\T3Cowriter\Controller\ToolController;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
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
    private ToolLoopServiceInterface&Stub $toolLoopServiceStub;
    private LlmConfigurationRepository&Stub $configRepositoryStub;
    private RateLimiterInterface&Stub $rateLimiterStub;
    private ToolController $subject;

    protected function setUp(): void
    {
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
            $this->configRepositoryStub,
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
    public function executeActionReturns400ForUnknownRequestedConfiguration(): void
    {
        $this->allowRateLimit();
        // A requested id that does not resolve → error, not silent fallback.
        $this->configRepositoryStub->method('findOneByIdentifier')->willReturn(null);

        $response = $this->subject->executeAction($this->createJsonRequest([
            'prompt'        => 'Query',
            'configuration' => 'ghost',
        ]));

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('ghost', $data['error']);
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
            $configRepository,
            $rateLimiter,
            $contextStub,
            new NullLogger(),
        );

        $response = $subject->executeAction($this->createJsonRequest(['prompt' => 'Query']));

        self::assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
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
