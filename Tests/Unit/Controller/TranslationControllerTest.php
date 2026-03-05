<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use Netresearch\NrLlm\Domain\Model\TranslationResult;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\T3Cowriter\Controller\TranslationController;
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

#[CoversClass(TranslationController::class)]
final class TranslationControllerTest extends TestCase
{
    private TranslationService&Stub $translationServiceStub;
    private RateLimiterInterface&Stub $rateLimiterStub;
    private TranslationController $subject;

    protected function setUp(): void
    {
        $this->translationServiceStub = $this->createStub(TranslationService::class);
        $this->rateLimiterStub        = $this->createStub(RateLimiterInterface::class);
        $contextStub                  = $this->createStub(Context::class);

        $contextStub->method('getPropertyFromAspect')
            ->willReturn(1);

        $this->subject = new TranslationController(
            $this->translationServiceStub,
            $this->rateLimiterStub,
            $contextStub,
            new NullLogger(),
        );
    }

    #[Test]
    public function translateActionReturnsTranslation(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $translationResult = new TranslationResult(
            translation: 'Hallo Welt',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 0.9,
            usage: new UsageStatistics(50, 30, 80),
        );

        $this->translationServiceStub->method('translate')
            ->willReturn($translationResult);

        $request  = $this->createJsonRequest(['text' => 'Hello world', 'targetLanguage' => 'de']);
        $response = $this->subject->translateAction($request);

        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertSame('Hallo Welt', $data['translation']);
        self::assertSame('en', $data['sourceLanguage']);
        self::assertSame(0.9, $data['confidence']);
    }

    #[Test]
    public function translateActionReturns400ForMissingText(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $request  = $this->createJsonRequest(['targetLanguage' => 'de']);
        $response = $this->subject->translateAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function translateActionReturns400ForMissingTargetLanguage(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $request  = $this->createJsonRequest(['text' => 'Hello']);
        $response = $this->subject->translateAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function translateActionReturns429WhenRateLimited(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(false, 20, 0, time() + 60));

        $request  = $this->createJsonRequest(['text' => 'Hello', 'targetLanguage' => 'de']);
        $response = $this->subject->translateAction($request);

        self::assertSame(429, $response->getStatusCode());
    }

    #[Test]
    public function translateActionReturns500OnServiceError(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $this->translationServiceStub->method('translate')
            ->willThrowException(new RuntimeException('API error'));

        $request  = $this->createJsonRequest(['text' => 'Hello', 'targetLanguage' => 'de']);
        $response = $this->subject->translateAction($request);

        self::assertSame(500, $response->getStatusCode());
    }

    private function createJsonRequest(array $body): ServerRequestInterface&Stub
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);

        return $request;
    }
}
