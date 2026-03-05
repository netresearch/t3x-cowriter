<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Integration\Controller;

use Netresearch\NrLlm\Domain\Model\TranslationResult;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\T3Cowriter\Controller\TranslationController;
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
 * Integration tests for TranslationController.
 *
 * Tests complete request/response flows through the controller
 * with mocked TranslationService responses.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(TranslationController::class)]
final class TranslationControllerIntegrationTest extends AbstractIntegrationTestCase
{
    private TranslationController $subject;
    private TranslationService&MockObject $translationServiceMock;
    private RateLimiterInterface&MockObject $rateLimiterMock;
    private Context&MockObject $contextMock;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->translationServiceMock = $this->createMock(TranslationService::class);
        $this->rateLimiterMock        = $this->createMock(RateLimiterInterface::class);
        $this->contextMock            = $this->createMock(Context::class);

        // Default: rate limiter allows requests
        $this->rateLimiterMock->method('checkLimit')->willReturn(
            new RateLimitResult(allowed: true, limit: 20, remaining: 19, resetTime: time() + 60),
        );

        // Default: context returns user ID
        $this->contextMock->method('getPropertyFromAspect')->willReturn(1);

        $this->subject = new TranslationController(
            $this->translationServiceMock,
            $this->rateLimiterMock,
            $this->contextMock,
            new NullLogger(),
        );
    }

    /**
     * Create a TranslationResult with realistic data.
     */
    private function createTranslationResult(
        string $translation = 'Translated text.',
        string $sourceLanguage = 'en',
        string $targetLanguage = 'de',
        float $confidence = 0.95,
        int $promptTokens = 30,
        int $completionTokens = 25,
    ): TranslationResult {
        return new TranslationResult(
            translation: $translation,
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
            confidence: $confidence,
            usage: UsageStatistics::fromTokens($promptTokens, $completionTokens),
        );
    }

    // =========================================================================
    // Complete Flow Tests
    // =========================================================================

    #[Test]
    public function translateFlowWithDefaultOptions(): void
    {
        // Arrange
        $result = $this->createTranslationResult(
            'Willkommen auf unserer Webseite.',
            'en',
            'de',
            0.97,
        );
        $this->translationServiceMock->method('translate')->willReturn($result);

        // Act
        $request = $this->createJsonRequest([
            'text'           => 'Welcome to our website.',
            'targetLanguage' => 'de',
        ]);
        $response = $this->subject->translateAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertSame('Willkommen auf unserer Webseite.', $data['translation']);
        self::assertSame('en', $data['sourceLanguage']);
        self::assertSame(0.97, $data['confidence']);
        self::assertArrayHasKey('usage', $data);
    }

    #[Test]
    public function translateFlowWithFormalityAndDomain(): void
    {
        // Arrange: verify options are passed through
        $result = $this->createTranslationResult(
            'Sehr geehrte Damen und Herren, willkommen.',
            'en',
            'de',
            0.94,
        );
        $this->translationServiceMock
            ->method('translate')
            ->willReturn($result);

        // Act
        $request = $this->createJsonRequest([
            'text'           => 'Dear Ladies and Gentlemen, welcome.',
            'targetLanguage' => 'de',
            'formality'      => 'formal',
            'domain'         => 'marketing',
        ]);
        $response = $this->subject->translateAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertSame('Sehr geehrte Damen und Herren, willkommen.', $data['translation']);
    }

    #[Test]
    public function translateFlowReturnsRawContentInResponse(): void
    {
        // Arrange: TranslationService returns content with HTML-like characters
        $translatedText = 'Ein Bild zeigt <strong>fetten Text</strong> & Sonderzeichen';
        $result         = $this->createTranslationResult($translatedText);
        $this->translationServiceMock->method('translate')->willReturn($result);

        // Act
        $request = $this->createJsonRequest([
            'text'           => 'An image shows bold text and special characters',
            'targetLanguage' => 'de',
        ]);
        $response = $this->subject->translateAction($request);

        // Assert: Raw content returned — JSON encoding handles transport safety
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame($translatedText, $data['translation']);
    }

    #[Test]
    public function translateFlowIncludesUsageStatistics(): void
    {
        // Arrange
        $result = $this->createTranslationResult(
            'Test',
            'en',
            'de',
            0.9,
            promptTokens: 45,
            completionTokens: 38,
        );
        $this->translationServiceMock->method('translate')->willReturn($result);

        // Act
        $request = $this->createJsonRequest([
            'text'           => 'Test',
            'targetLanguage' => 'de',
        ]);
        $response = $this->subject->translateAction($request);

        // Assert
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertArrayHasKey('usage', $data);
        self::assertSame(45, $data['usage']['promptTokens']);
        self::assertSame(38, $data['usage']['completionTokens']);
        self::assertSame(83, $data['usage']['totalTokens']);
    }
}
