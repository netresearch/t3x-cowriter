<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\T3Cowriter\Controller\ToolController;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Context\Context;

#[CoversClass(ToolController::class)]
final class ToolControllerTest extends TestCase
{
    private LlmServiceManagerInterface&Stub $llmServiceManagerStub;
    private RateLimiterInterface&Stub $rateLimiterStub;
    private ToolController $subject;

    protected function setUp(): void
    {
        $this->llmServiceManagerStub = $this->createStub(LlmServiceManagerInterface::class);
        $this->rateLimiterStub       = $this->createStub(RateLimiterInterface::class);
        $contextStub                 = $this->createStub(Context::class);

        $contextStub->method('getPropertyFromAspect')
            ->willReturn(1);

        $this->subject = new ToolController(
            $this->llmServiceManagerStub,
            $this->rateLimiterStub,
            $contextStub,
            new NullLogger(),
        );
    }

    #[Test]
    public function executeActionReturnsRateLimitResponse(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(false, 20, 0, time() + 60));

        $request  = $this->createJsonRequest(['prompt' => 'Find all text elements']);
        $response = $this->subject->executeAction($request);

        self::assertSame(429, $response->getStatusCode());
    }

    #[Test]
    public function executeActionReturnsBadRequestForMissingPrompt(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $request  = $this->createJsonRequest([]);
        $response = $this->subject->executeAction($request);

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
    }

    #[Test]
    public function executeActionReturnsToolCallResult(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $completionResponse = new CompletionResponse(
            content: 'Here are the content elements on page 1.',
            model: 'gpt-4',
            usage: new UsageStatistics(100, 50, 150),
            finishReason: 'stop',
            provider: 'openai',
            toolCalls: null,
            metadata: null,
        );

        $this->llmServiceManagerStub->method('chatWithTools')
            ->willReturn($completionResponse);

        $request  = $this->createJsonRequest(['prompt' => 'Find all text elements']);
        $response = $this->subject->executeAction($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertSame('Here are the content elements on page 1.', $data['content']);
        self::assertSame('stop', $data['finishReason']);
        self::assertSame(100, $data['usage']['promptTokens']);
        self::assertSame(50, $data['usage']['completionTokens']);
        self::assertSame(150, $data['usage']['totalTokens']);
    }

    #[Test]
    public function executeActionReturnsErrorOnException(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $this->llmServiceManagerStub->method('chatWithTools')
            ->willThrowException(new RuntimeException('API error'));

        $request  = $this->createJsonRequest(['prompt' => 'Find all text elements']);
        $response = $this->subject->executeAction($request);

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
    }

    private function createJsonRequest(array $body): ServerRequestInterface&Stub
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);

        return $request;
    }
}
