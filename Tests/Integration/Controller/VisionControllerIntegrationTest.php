<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Integration\Controller;

use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Service\Feature\VisionService;
use Netresearch\T3Cowriter\Controller\VisionController;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Tests\Integration\AbstractIntegrationTestCase;
use Override;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Context\Context;

/**
 * Integration tests for VisionController.
 *
 * Tests complete request/response flows through the controller
 * with mocked VisionService responses.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(VisionController::class)]
final class VisionControllerIntegrationTest extends AbstractIntegrationTestCase
{
    private VisionController $subject;
    private VisionService&MockObject $visionServiceMock;
    private RateLimiterInterface&MockObject $rateLimiterMock;
    private Context&MockObject $contextMock;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->visionServiceMock = $this->createMock(VisionService::class);
        $this->rateLimiterMock   = $this->createMock(RateLimiterInterface::class);
        $this->contextMock       = $this->createMock(Context::class);

        // Default: rate limiter allows requests
        $this->rateLimiterMock->method('checkLimit')->willReturn(
            new RateLimitResult(allowed: true, limit: 20, remaining: 19, resetTime: time() + 60),
        );

        // Default: context returns user ID
        $this->contextMock->method('getPropertyFromAspect')->willReturn(1);

        $this->subject = new VisionController(
            $this->visionServiceMock,
            $this->rateLimiterMock,
            $this->contextMock,
            new NullLogger(),
        );
    }

    /**
     * Create a VisionResponse with realistic data.
     */
    private function createVisionResponse(
        string $description = 'A descriptive alt text for the image.',
        string $model = 'gpt-4o',
        int $promptTokens = 100,
        int $completionTokens = 50,
        float $confidence = 0.92,
    ): VisionResponse {
        return new VisionResponse(
            description: $description,
            model: $model,
            usage: UsageStatistics::fromTokens($promptTokens, $completionTokens),
            confidence: $confidence,
        );
    }

    // =========================================================================
    // Complete Flow Tests
    // =========================================================================

    #[Test]
    public function analyzeFlowWithDefaultOptions(): void
    {
        // Arrange: Setup VisionService response
        $response = $this->createVisionResponse(
            'A white cat sitting on a wooden desk next to a laptop.',
        );
        $this->visionServiceMock->method('analyzeImageFull')->willReturn($response);

        // Act
        $request = $this->createJsonRequest([
            'imageUrl' => 'https://example.com/cat-desk.jpg',
        ]);
        $result = $this->subject->analyzeAction($request);

        // Assert
        self::assertSame(200, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertSame('A white cat sitting on a wooden desk next to a laptop.', $data['altText']);
        self::assertSame('gpt-4o', $data['model']);
        self::assertArrayHasKey('confidence', $data);
        self::assertArrayHasKey('usage', $data);
    }

    #[Test]
    public function analyzeFlowReturnsRawContentInResponse(): void
    {
        // Arrange: VisionService returns content with HTML-like characters
        $description = 'An image showing <strong>bold text</strong> & special "characters"';
        $response    = $this->createVisionResponse($description);
        $this->visionServiceMock->method('analyzeImageFull')->willReturn($response);

        // Act
        $request = $this->createJsonRequest([
            'imageUrl' => 'https://example.com/image.jpg',
        ]);
        $result = $this->subject->analyzeAction($request);

        // Assert: Raw content returned — JSON encoding is the transport safety
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame($description, $data['altText']);
    }

    #[Test]
    public function analyzeFlowIncludesUsageStatistics(): void
    {
        // Arrange
        $response = $this->createVisionResponse(
            'Test description',
            'gpt-4o',
            promptTokens: 200,
            completionTokens: 80,
        );
        $this->visionServiceMock->method('analyzeImageFull')->willReturn($response);

        // Act
        $request = $this->createJsonRequest([
            'imageUrl' => 'https://example.com/image.jpg',
        ]);
        $result = $this->subject->analyzeAction($request);

        // Assert
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertArrayHasKey('usage', $data);
        self::assertSame(200, $data['usage']['promptTokens']);
        self::assertSame(80, $data['usage']['completionTokens']);
        self::assertSame(280, $data['usage']['totalTokens']);
    }
}
