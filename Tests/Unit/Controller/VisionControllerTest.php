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

        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertSame('A cat sitting on a mat', $data['altText']);
        self::assertSame('gpt-4o', $data['model']);
        self::assertSame(0.95, $data['confidence']);
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
    }

    #[Test]
    public function analyzeActionReturns429WhenRateLimited(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(false, 20, 0, time() + 60));

        $request  = $this->createJsonRequest(['imageUrl' => 'https://example.com/image.jpg']);
        $response = $this->subject->analyzeAction($request);

        self::assertSame(429, $response->getStatusCode());
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
    }

    private function createJsonRequest(array $body): ServerRequestInterface&Stub
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);

        return $request;
    }
}
