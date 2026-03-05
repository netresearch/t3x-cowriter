<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Service\Feature\VisionService;
use Netresearch\T3Cowriter\Controller\VisionController;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Context\Context;

#[CoversClass(VisionController::class)]
final class VisionControllerTest extends TestCase
{
    private VisionService&Stub $visionServiceStub;
    private RateLimiterInterface&Stub $rateLimiterStub;
    private VisionController $subject;

    protected function setUp(): void
    {
        $this->visionServiceStub = $this->createStub(VisionService::class);
        $this->rateLimiterStub   = $this->createStub(RateLimiterInterface::class);
        $contextStub             = $this->createStub(Context::class);

        $contextStub->method('getPropertyFromAspect')
            ->willReturn(1);

        $this->subject = new VisionController(
            $this->visionServiceStub,
            $this->rateLimiterStub,
            $contextStub,
            new NullLogger(),
        );
    }

    #[Test]
    public function analyzeActionReturnsAltText(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $visionResponse = new VisionResponse(
            description: 'A cat sitting on a mat',
            model: 'gpt-4o',
            usage: new UsageStatistics(100, 50, 150),
            confidence: 0.95,
        );

        $this->visionServiceStub->method('analyzeImageFull')
            ->willReturn($visionResponse);

        $request  = $this->createJsonRequest(['imageUrl' => 'https://example.com/cat.jpg']);
        $response = $this->subject->analyzeAction($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertSame('A cat sitting on a mat', $data['altText']);
        self::assertSame('gpt-4o', $data['model']);
        self::assertSame(0.95, $data['confidence']);
        self::assertArrayHasKey('usage', $data);
        self::assertSame(100, $data['usage']['promptTokens']);
        self::assertSame(50, $data['usage']['completionTokens']);
        self::assertSame(150, $data['usage']['totalTokens']);
        self::assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('19', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function analyzeActionReturns400ForMissingImageUrl(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $request  = $this->createJsonRequest([]);
        $response = $this->subject->analyzeAction($request);

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('Missing', $data['error']);
        self::assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('19', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function analyzeActionReturns429WithHeadersWhenRateLimited(): void
    {
        $resetTime = time() + 60;
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(false, 20, 0, $resetTime));

        $request  = $this->createJsonRequest(['imageUrl' => 'https://example.com/image.jpg']);
        $response = $this->subject->analyzeAction($request);

        self::assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('error', $data);
        self::assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
        self::assertNotEmpty($response->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function analyzeActionReturns400ForInvalidJson(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $request  = $this->createRawRequest('not valid json{');
        $response = $this->subject->analyzeAction($request);

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('Invalid JSON', $data['error']);
        self::assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('19', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function analyzeActionReturns400ForExcessiveFieldLength(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $longUrl  = 'https://example.com/' . str_repeat('a', 40000);
        $request  = $this->createJsonRequest(['imageUrl' => $longUrl]);
        $response = $this->subject->analyzeAction($request);

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('maximum length', $data['error']);
    }

    #[Test]
    public function analyzeActionReturns500OnServiceError(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $this->visionServiceStub->method('analyzeImageFull')
            ->willThrowException(new RuntimeException('API error'));

        $request  = $this->createJsonRequest(['imageUrl' => 'https://example.com/image.jpg']);
        $response = $this->subject->analyzeAction($request);

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('failed', $data['error']);
        self::assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('19', $response->getHeaderLine('X-RateLimit-Remaining'));
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
