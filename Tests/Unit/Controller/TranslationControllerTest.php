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
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\T3Cowriter\Controller\TranslationController;
use Netresearch\T3Cowriter\Service\DiagnosticService;
use Netresearch\T3Cowriter\Service\Dto\DiagnosticCheck;
use Netresearch\T3Cowriter\Service\Dto\DiagnosticResult;
use Netresearch\T3Cowriter\Service\Dto\Severity;
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
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Context\Context;

#[CoversClass(TranslationController::class)]
final class TranslationControllerTest extends TestCase
{
    private TranslationService&Stub $translationServiceStub;
    private RateLimiterInterface&Stub $rateLimiterStub;
    private BackendUriBuilder&Stub $backendUriBuilderStub;
    private DiagnosticService&Stub $diagnosticServiceStub;
    private TranslationController $subject;

    protected function setUp(): void
    {
        $this->translationServiceStub = $this->createStub(TranslationService::class);
        $this->rateLimiterStub        = $this->createStub(RateLimiterInterface::class);
        $this->backendUriBuilderStub  = $this->createStub(BackendUriBuilder::class);
        $this->backendUriBuilderStub->method('buildUriFromRoute')
            ->willReturn(new \TYPO3\CMS\Core\Http\Uri('/typo3/module/cowriter/status'));
        $this->diagnosticServiceStub = $this->createStub(DiagnosticService::class);
        $contextStub                 = $this->createStub(Context::class);

        $contextStub->method('getPropertyFromAspect')
            ->willReturn(1);

        $this->diagnosticServiceStub->method('runFirst')
            ->willReturn(new DiagnosticResult(true, []));

        $this->subject = new TranslationController(
            $this->translationServiceStub,
            $this->rateLimiterStub,
            $contextStub,
            new NullLogger(),
            $this->backendUriBuilderStub,
            $this->diagnosticServiceStub,
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

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertSame('Hallo Welt', $data['translation']);
        self::assertSame('en', $data['sourceLanguage']);
        self::assertSame(0.9, $data['confidence']);
        self::assertArrayHasKey('usage', $data);
        self::assertSame(50, $data['usage']['promptTokens']);
        self::assertSame(30, $data['usage']['completionTokens']);
        self::assertSame(80, $data['usage']['totalTokens']);
        self::assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('19', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function translateActionReturns400ForMissingText(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $request  = $this->createJsonRequest(['targetLanguage' => 'de']);
        $response = $this->subject->translateAction($request);

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('Missing', $data['error']);
        self::assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('19', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function translateActionReturns400ForMissingTargetLanguage(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $request  = $this->createJsonRequest(['text' => 'Hello']);
        $response = $this->subject->translateAction($request);

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('Missing', $data['error']);
    }

    #[Test]
    public function translateActionReturns429WithHeadersWhenRateLimited(): void
    {
        $resetTime = time() + 60;
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(false, 20, 0, $resetTime));

        $request  = $this->createJsonRequest(['text' => 'Hello', 'targetLanguage' => 'de']);
        $response = $this->subject->translateAction($request);

        self::assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('error', $data);
        self::assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
        self::assertNotEmpty($response->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function translateActionReturns400ForInvalidJson(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $request  = $this->createRawRequest('{bad json');
        $response = $this->subject->translateAction($request);

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('Invalid JSON', $data['error']);
        self::assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('19', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function translateActionReturns400ForExcessiveFieldLength(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $longText = str_repeat('a', 40000);
        $request  = $this->createJsonRequest(['text' => $longText, 'targetLanguage' => 'de']);
        $response = $this->subject->translateAction($request);

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertStringContainsString('maximum length', $data['error']);
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
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('error', $data);
        self::assertStringContainsString('failed', $data['error']);
        self::assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('19', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function translateActionReturnsApiKeyRejectedForOnly401Code(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $this->translationServiceStub->method('translate')
            ->willThrowException(new RuntimeException('HTTP 401 error'));

        $request  = $this->createJsonRequest(['text' => 'Hello', 'targetLanguage' => 'de']);
        $response = $this->subject->translateAction($request);

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertSame(
            'The LLM provider rejected the API key. An administrator should check the provider configuration in the LLM module.',
            $data['error'],
        );
    }

    #[Test]
    public function translateActionReturnsApiKeyRejectedForOnlyUnauthorized(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $this->translationServiceStub->method('translate')
            ->willThrowException(new RuntimeException('Request Unauthorized'));

        $request  = $this->createJsonRequest(['text' => 'Hello', 'targetLanguage' => 'de']);
        $response = $this->subject->translateAction($request);

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertSame(
            'The LLM provider rejected the API key. An administrator should check the provider configuration in the LLM module.',
            $data['error'],
        );
    }

    #[Test]
    public function translateActionReturnsApiKeyRejectedForOnlyAuthentication(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $this->translationServiceStub->method('translate')
            ->willThrowException(new RuntimeException('Token authentication failed'));

        $request  = $this->createJsonRequest(['text' => 'Hello', 'targetLanguage' => 'de']);
        $response = $this->subject->translateAction($request);

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertSame(
            'The LLM provider rejected the API key. An administrator should check the provider configuration in the LLM module.',
            $data['error'],
        );
    }

    #[Test]
    public function translateActionReturnsDiagnosticMessageWhenNoProviderConfigured(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $this->translationServiceStub->method('translate')
            ->willThrowException(new RuntimeException('No provider specified and no default provider configured'));

        $diagnosticStub = $this->createStub(DiagnosticService::class);
        $diagnosticStub->method('runFirst')
            ->willReturn(new DiagnosticResult(false, [
                new DiagnosticCheck(
                    key: 'provider_exists',
                    passed: false,
                    message: 'No LLM provider configured.',
                    severity: Severity::Error,
                    fixRoute: 'nrllm_providers',
                ),
            ]));

        $contextStub = $this->createStub(Context::class);
        $contextStub->method('getPropertyFromAspect')->willReturn(1);

        $controller = new TranslationController(
            $this->translationServiceStub,
            $this->rateLimiterStub,
            $contextStub,
            new NullLogger(),
            $this->backendUriBuilderStub,
            $diagnosticStub,
        );

        $request  = $this->createJsonRequest(['text' => 'Hello', 'targetLanguage' => 'de']);
        $response = $controller->translateAction($request);

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertSame(
            'No LLM provider configured. Ask an administrator to check the Cowriter Setup Status page for details.',
            $data['error'],
        );
        self::assertSame('/typo3/module/cowriter/status', $data['statusUrl']);
    }

    #[Test]
    public function translateActionPassesConfigurationAsProviderNotSourceLanguage(): void
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

        /** @var list<array{string, string, ?string, TranslationOptions}> */
        $capturedArgs = [];

        // Use a mock to capture exact arguments
        $translationServiceMock = $this->createMock(TranslationService::class);
        $translationServiceMock->expects(self::once())
            ->method('translate')
            ->willReturnCallback(static function (
                string $text,
                string $targetLanguage,
                ?string $sourceLanguage,
                ?TranslationOptions $options,
            ) use (&$capturedArgs, $translationResult): TranslationResult {
                $capturedArgs[] = [$text, $targetLanguage, $sourceLanguage, $options];

                return $translationResult;
            });

        $contextStub = $this->createStub(Context::class);
        $contextStub->method('getPropertyFromAspect')->willReturn(1);

        $controller = new TranslationController(
            $translationServiceMock,
            $this->rateLimiterStub,
            $contextStub,
            new NullLogger(),
            $this->backendUriBuilderStub,
            $this->diagnosticServiceStub,
        );

        $request = $this->createJsonRequest([
            'text'           => 'Hello world',
            'targetLanguage' => 'de',
            'configuration'  => 'my-provider',
            'formality'      => 'formal',
        ]);
        $response = $controller->translateAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $capturedArgs);

        [$text, $targetLang, $sourceLang, $options] = $capturedArgs[0];
        self::assertSame('Hello world', $text);
        self::assertSame('de', $targetLang);
        self::assertNull($sourceLang, 'sourceLanguage must be null, not the configuration value');
        self::assertInstanceOf(TranslationOptions::class, $options);
        self::assertSame('my-provider', $options->getProvider());
        self::assertSame('formal', $options->getFormality());
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
