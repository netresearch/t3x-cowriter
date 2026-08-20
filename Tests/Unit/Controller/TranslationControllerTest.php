<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\TranslationResult;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Feature\TranslationPromptBuilder;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Service\Feature\TranslationServiceInterface;
use Netresearch\NrLlm\Service\Guardrail\InputGuardrailScreener;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Netresearch\NrLlm\Specialized\Translation\DeepLTranslator;
use Netresearch\NrLlm\Specialized\Translation\LlmTranslator;
use Netresearch\NrLlm\Specialized\Translation\TranslatorInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorRegistry;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Netresearch\NrVault\Service\VaultServiceInterface;
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
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;

#[CoversClass(TranslationController::class)]
final class TranslationControllerTest extends TestCase
{
    private TranslationServiceInterface&Stub $translationServiceStub;
    private LlmConfigurationRepository&Stub $configurationRepositoryStub;
    private RateLimiterInterface&Stub $rateLimiterStub;
    private BackendUriBuilder&Stub $backendUriBuilderStub;
    private DiagnosticService&Stub $diagnosticServiceStub;
    private TranslationController $subject;

    protected function setUp(): void
    {
        $this->translationServiceStub      = $this->createStub(TranslationServiceInterface::class);
        $this->configurationRepositoryStub = $this->createStub(LlmConfigurationRepository::class);
        $this->rateLimiterStub             = $this->createStub(RateLimiterInterface::class);
        $this->backendUriBuilderStub       = $this->createStub(BackendUriBuilder::class);
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
            $this->configurationRepositoryStub,
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

        $data = $this->assertSuccessfulTranslationResponse($response, 'Hallo Welt');
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
    public function translateActionNamesThisExtensionAndOperationAsCallerSource(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $captured          = new stdClass();
        $captured->options = null;

        $this->translationServiceStub->method('translate')
            ->willReturnCallback(
                static function (
                    string $text,
                    string $targetLanguage,
                    ?string $sourceLanguage = null,
                    ?TranslationOptions $options = null,
                ) use ($captured): TranslationResult {
                    $captured->options = $options;

                    return new TranslationResult(
                        translation: 'Hallo Welt',
                        sourceLanguage: 'en',
                        targetLanguage: 'de',
                        confidence: 0.9,
                        usage: new UsageStatistics(1, 1, 2),
                    );
                },
            );

        $this->subject->translateAction(
            $this->createJsonRequest(['text' => 'Hello world', 'targetLanguage' => 'de']),
        );

        self::assertInstanceOf(TranslationOptions::class, $captured->options);
        self::assertSame('t3_cowriter', $captured->options->getCallerSourceExtension());
        self::assertSame('translate', $captured->options->getCallerSourceOperation());
    }

    #[Test]
    public function translateActionNamesTheCallerSourceOnThePinnedConfigurationPath(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $this->configurationRepositoryStub->method('findOneByIdentifier')
            ->willReturn($this->createStub(LlmConfiguration::class));

        $captured          = new stdClass();
        $captured->options = null;

        $this->translationServiceStub->method('translateForConfiguration')
            ->willReturnCallback(
                static function (
                    string $text,
                    string $targetLanguage,
                    LlmConfiguration $configuration,
                    ?string $sourceLanguage = null,
                    ?TranslationOptions $options = null,
                ) use ($captured): TranslationResult {
                    $captured->options = $options;

                    return new TranslationResult(
                        translation: 'Hallo Welt',
                        sourceLanguage: 'en',
                        targetLanguage: 'de',
                        confidence: 0.9,
                        usage: new UsageStatistics(1, 1, 2),
                    );
                },
            );

        $this->subject->translateAction($this->createJsonRequest([
            'text'           => 'Hello world',
            'targetLanguage' => 'de',
            'configuration'  => 'pinned',
        ]));

        self::assertInstanceOf(TranslationOptions::class, $captured->options);
        self::assertSame('t3_cowriter', $captured->options->getCallerSourceExtension());
        self::assertSame('translate', $captured->options->getCallerSourceOperation());
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
    public function translateActionReturnsApiKeyRejectedOn401(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $this->translationServiceStub->method('translate')
            ->willThrowException(new ProviderResponseException(message: 'Unauthorized', httpStatus: 401));

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
    public function translateActionReturnsRateLimitMessageOn429(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $this->translationServiceStub->method('translate')
            ->willThrowException(new ProviderResponseException(message: 'Too many requests', httpStatus: 429));

        $request  = $this->createJsonRequest(['text' => 'Hello', 'targetLanguage' => 'de']);
        $response = $this->subject->translateAction($request);

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertSame(
            'The LLM provider rate limit was exceeded. Please wait a moment and try again.',
            $data['error'],
        );
    }

    #[Test]
    public function translateActionReturnsGenericMessageForOtherProviderStatus(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $this->translationServiceStub->method('translate')
            ->willThrowException(new ProviderResponseException(message: 'Forbidden', httpStatus: 403));

        $request  = $this->createJsonRequest(['text' => 'Hello', 'targetLanguage' => 'de']);
        $response = $this->subject->translateAction($request);

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertSame(
            'Translation failed. Check the TYPO3 system log for details.',
            $data['error'],
        );
    }

    #[Test]
    public function translateActionReturnsDiagnosticMessageWhenNoProviderConfigured(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $this->translationServiceStub->method('translate')
            ->willThrowException(new ProviderException('No provider specified and no default provider configured', 4867297358));

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

        $controller = $this->createControllerWith($this->translationServiceStub, diagnosticService: $diagnosticStub);

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
    public function translateActionRoutesThroughConfigurationWhenPinned(): void
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

        $configuration = $this->createStub(LlmConfiguration::class);

        $configurationRepositoryMock = $this->createMock(LlmConfigurationRepository::class);
        $configurationRepositoryMock->expects(self::once())
            ->method('findOneByIdentifier')
            ->with('editorial-tone')
            ->willReturn($configuration);

        /** @var list<array{string, string, LlmConfiguration, ?string, ?TranslationOptions}> */
        $capturedArgs = [];

        $translationServiceMock = $this->createMock(TranslationServiceInterface::class);
        $translationServiceMock->expects(self::never())->method('translate');
        $translationServiceMock->expects(self::once())
            ->method('translateForConfiguration')
            ->willReturnCallback(static function (
                string $text,
                string $targetLanguage,
                LlmConfiguration $config,
                ?string $sourceLanguage,
                ?TranslationOptions $options,
            ) use (&$capturedArgs, $translationResult): TranslationResult {
                $capturedArgs[] = [$text, $targetLanguage, $config, $sourceLanguage, $options];

                return $translationResult;
            });

        $controller = $this->createControllerWith($translationServiceMock, $configurationRepositoryMock);

        $request = $this->createJsonRequest([
            'text'           => 'Hello world',
            'targetLanguage' => 'de',
            'configuration'  => 'editorial-tone',
            'formality'      => 'formal',
        ]);
        $response = $controller->translateAction($request);

        $this->assertSuccessfulTranslationResponse($response, 'Hallo Welt');
        self::assertCount(1, $capturedArgs);

        [$text, $targetLang, $config, $sourceLang, $options] = $capturedArgs[0];
        self::assertSame('Hello world', $text);
        self::assertSame('de', $targetLang);
        self::assertSame($configuration, $config, 'the resolved configuration must be forwarded');
        self::assertNull($sourceLang, 'sourceLanguage must be null, not the configuration value');
        self::assertInstanceOf(TranslationOptions::class, $options);
        self::assertNull($options->getProvider(), 'the configuration identifier must not leak into provider');
        self::assertSame('formal', $options->getFormality());
    }

    #[Test]
    public function translateActionUsesPlainPathWithNullSourceLanguageWhenNoConfiguration(): void
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

        /** @var list<array{string, string, ?string, ?TranslationOptions}> */
        $capturedArgs = [];

        $translationServiceMock = $this->createMock(TranslationServiceInterface::class);
        $translationServiceMock->expects(self::never())->method('translateForConfiguration');
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

        $controller = $this->createControllerWith($translationServiceMock);

        $request = $this->createJsonRequest([
            'text'           => 'Hello world',
            'targetLanguage' => 'de',
            'formality'      => 'formal',
        ]);
        $response = $controller->translateAction($request);

        $this->assertSuccessfulTranslationResponse($response, 'Hallo Welt');
        self::assertCount(1, $capturedArgs);

        [$text, $targetLang, $sourceLang, $options] = $capturedArgs[0];
        self::assertSame('Hello world', $text);
        self::assertSame('de', $targetLang);
        self::assertNull($sourceLang, 'sourceLanguage must be null, not the configuration value');
        self::assertInstanceOf(TranslationOptions::class, $options);
        self::assertSame('formal', $options->getFormality());
    }

    #[Test]
    public function translateActionPrefersSpecializedTranslatorWhenAvailable(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $deeplTranslator = $this->createStub(TranslatorInterface::class);
        $deeplTranslator->method('getIdentifier')->willReturn('deepl');

        $translatorResult = new TranslatorResult(
            translatedText: 'Hallo Welt',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'deepl',
            confidence: 0.95,
        );

        /** @var list<array{string, string, ?string, ?TranslationOptions}> */
        $capturedArgs = [];

        $translationServiceMock = $this->createMock(TranslationServiceInterface::class);
        $translationServiceMock->expects(self::never())->method('translateForConfiguration');
        $translationServiceMock->expects(self::never())->method('translate');
        $translationServiceMock->expects(self::once())
            ->method('findBestTranslator')
            ->with('auto', 'de')
            ->willReturn($deeplTranslator);
        $translationServiceMock->expects(self::once())
            ->method('translateWithTranslator')
            ->willReturnCallback(static function (
                string $text,
                string $targetLanguage,
                ?string $sourceLanguage,
                ?TranslationOptions $options,
            ) use (&$capturedArgs, $translatorResult): TranslatorResult {
                $capturedArgs[] = [$text, $targetLanguage, $sourceLanguage, $options];

                return $translatorResult;
            });

        $controller = $this->createControllerWith($translationServiceMock);

        $request  = $this->createJsonRequest(['text' => 'Hello world', 'targetLanguage' => 'de']);
        $response = $controller->translateAction($request);

        $data = $this->assertSuccessfulTranslationResponse($response, 'Hallo Welt');
        self::assertSame('en', $data['sourceLanguage']);
        self::assertSame(0.95, $data['confidence']);
        self::assertSame('Deepl', $data['translator']);
        self::assertArrayNotHasKey('usage', $data, 'TranslatorResult carries no token usage, unlike TranslationResult');

        self::assertCount(1, $capturedArgs);
        [$text, $targetLang, $sourceLang, $options] = $capturedArgs[0];
        self::assertSame('Hello world', $text);
        self::assertSame('de', $targetLang);
        self::assertNull($sourceLang);
        self::assertInstanceOf(TranslationOptions::class, $options);
        self::assertSame('deepl', $options->getTranslator());
    }

    #[Test]
    public function translateActionUsesPlainLlmPathWhenBestTranslatorIsTheLlmFallbackItself(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $llmTranslator = $this->createStub(TranslatorInterface::class);
        $llmTranslator->method('getIdentifier')->willReturn(LlmTranslator::IDENTIFIER);

        $translationResult = new TranslationResult(
            translation: 'Hallo Welt',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 0.9,
            usage: new UsageStatistics(50, 30, 80),
        );

        $translationServiceMock = $this->createMock(TranslationServiceInterface::class);
        $translationServiceMock->method('findBestTranslator')->willReturn($llmTranslator);
        $translationServiceMock->expects(self::never())->method('translateWithTranslator');
        $translationServiceMock->expects(self::once())
            ->method('translate')
            ->willReturn($translationResult);

        $controller = $this->createControllerWith($translationServiceMock);

        $request  = $this->createJsonRequest(['text' => 'Hello world', 'targetLanguage' => 'de']);
        $response = $controller->translateAction($request);

        $data = $this->assertSuccessfulTranslationResponse($response, 'Hallo Welt');
        self::assertArrayHasKey('usage', $data);
    }

    /**
     * Functional-lite: every other test in this class stubs
     * TranslationServiceInterface directly, which only proves the
     * controller reacts correctly to whatever TranslationService returns —
     * it can never catch a regression in the actual selection chain
     * (registry priority ordering, DeepLTranslator::supportsLanguagePair()).
     * This wires the REAL TranslationService + TranslatorRegistry +
     * DeepLTranslator + LlmTranslator; only the outermost HTTP transport is
     * faked, via DeepLTranslator::setHttpClient() (the same test seam
     * netresearch/t3x-nr-llm's own DeepLTranslatorTest uses).
     */
    #[Test]
    public function translateActionRoutesThroughARealTranslatorRegistryToDeepL(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $translationService = $this->createRealTranslationServiceDispatchingDeeplTo(
            $this->createJsonResponseMock(['translations' => [
                ['text' => 'Hallo Welt', 'detected_source_language' => 'EN'],
            ]]),
        );

        $controller = $this->createControllerWith($translationService);

        $request  = $this->createJsonRequest(['text' => 'Hello world', 'targetLanguage' => 'de']);
        $response = $controller->translateAction($request);

        $data = $this->assertSuccessfulTranslationResponse($response, 'Hallo Welt');
        self::assertSame(
            'Deepl',
            $data['translator'],
            'the real TranslatorRegistry must select DeepLTranslator over the LlmTranslator fallback for an "auto"->"de" pair',
        );
        self::assertSame('en', $data['sourceLanguage']);
    }

    #[Test]
    public function translateActionReturnsErrorWhenPinnedConfigurationNotFound(): void
    {
        $this->rateLimiterStub->method('checkLimit')
            ->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $configurationRepositoryMock = $this->createMock(LlmConfigurationRepository::class);
        $configurationRepositoryMock->expects(self::once())
            ->method('findOneByIdentifier')
            ->with('missing-config')
            ->willReturn(null);

        // A requested configuration that does not exist must not silently fall
        // back to the default translation path.
        $translationServiceMock = $this->createMock(TranslationServiceInterface::class);
        $translationServiceMock->expects(self::never())->method('translateForConfiguration');
        $translationServiceMock->expects(self::never())->method('translate');

        $controller = $this->createControllerWith($translationServiceMock, $configurationRepositoryMock);

        $request = $this->createJsonRequest([
            'text'           => 'Hello world',
            'targetLanguage' => 'de',
            'configuration'  => 'missing-config',
        ]);
        $response = $controller->translateAction($request);

        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['success']);
        self::assertNotSame('', $data['error']);
        // Classified as a configuration error, so the status-page link is offered.
        self::assertArrayHasKey('statusUrl', $data);
    }

    /**
     * Build a TranslationController wired to a real (aspect-stubbed) Context
     * and the shared rate-limiter/backend-URI-builder stubs, swapping in only
     * the translation service (and, for the handful of tests that need it,
     * the configuration repository / diagnostic service). Every action test
     * needs this same wiring; only the service under observation differs.
     */
    private function createControllerWith(
        TranslationServiceInterface $translationService,
        ?LlmConfigurationRepository $configurationRepository = null,
        ?DiagnosticService $diagnosticService = null,
    ): TranslationController {
        $contextStub = $this->createStub(Context::class);
        $contextStub->method('getPropertyFromAspect')->willReturn(1);

        return new TranslationController(
            $translationService,
            $configurationRepository ?? $this->configurationRepositoryStub,
            $this->rateLimiterStub,
            $contextStub,
            new NullLogger(),
            $this->backendUriBuilderStub,
            $diagnosticService ?? $this->diagnosticServiceStub,
        );
    }

    /**
     * Assert the response-shape tail every success-path test starts with
     * (HTTP 200, decoded JSON, success=true, the translated text), and hand
     * back the decoded body so the caller can assert on the fields that
     * actually differ between paths (sourceLanguage/confidence/usage/
     * translator — TranslationResult and TranslatorResult don't carry the
     * same shape, see translateActionPrefersSpecializedTranslatorWhenAvailable
     * vs. translateActionReturnsTranslation).
     *
     * @return array<string, mixed>
     */
    private function assertSuccessfulTranslationResponse(ResponseInterface $response, string $expectedTranslation): array
    {
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['success']);
        self::assertSame($expectedTranslation, $data['translation']);

        return $data;
    }

    /**
     * Build a real TranslationService wired to a real TranslatorRegistry
     * containing a real DeepLTranslator (configured + available, HTTP
     * transport replaced by a stub returning $deeplResponse) and a real
     * LlmTranslator (available, but never reachable in these tests since
     * DeepL's supportsLanguagePair('auto', 'de') wins the registry scan
     * first — registry array order here mirrors DeepLTranslator's real
     * getPriority()=90 sorting ahead of LlmTranslator's getPriority()=-1000).
     */
    private function createRealTranslationServiceDispatchingDeeplTo(ResponseInterface $deeplResponse): TranslationServiceInterface
    {
        $vaultStub = $this->createStub(VaultServiceInterface::class);
        $vaultStub->method('retrieve')->willReturn('test-secret');
        $vaultStub->method('exists')->willReturn(true);

        $deeplHttpClientStub = $this->createStub(ClientInterface::class);
        $deeplHttpClientStub->method('sendRequest')->willReturn($deeplResponse);

        $extensionConfigurationStub = $this->createStub(ExtensionConfiguration::class);
        $extensionConfigurationStub->method('get')->willReturn([
            'translators' => [
                'deepl' => ['apiKeyIdentifier' => 'deepl-test-key', 'timeout' => 30],
            ],
        ]);

        $budgetServiceStub = $this->createStub(BudgetServiceInterface::class);
        $budgetServiceStub->method('check')->willReturn(BudgetCheckResult::allowed());

        $deeplTranslator = new DeepLTranslator(
            $vaultStub,
            $this->createBareRequestFactory(),
            $this->createBareStreamFactory(),
            $extensionConfigurationStub,
            $this->createStub(UsageTrackerServiceInterface::class),
            new NullLogger(),
            $this->createStub(SpecializedCostCalculatorInterface::class),
            $budgetServiceStub,
            new MiddlewarePipeline([]),
            new InputGuardrailScreener([]),
        );
        $deeplTranslator->setHttpClient($deeplHttpClientStub);

        $llmManagerStub = $this->createStub(LlmServiceManagerInterface::class);
        $llmManagerStub->method('hasAvailableProvider')->willReturn(true);
        $llmTranslator = new LlmTranslator($llmManagerStub, $this->createStub(UsageTrackerServiceInterface::class));

        $registry = new TranslatorRegistry([$deeplTranslator, $llmTranslator]);

        return new TranslationService(
            $llmManagerStub,
            $registry,
            $this->createStub(LlmConfigurationServiceInterface::class),
            new TranslationPromptBuilder(),
        );
    }

    private function createBareRequestFactory(): RequestFactoryInterface
    {
        $stub = $this->createStub(RequestFactoryInterface::class);
        $stub->method('createRequest')->willReturnCallback(
            fn (string $method, string $uri): RequestInterface => $this->createBareRequest($method, $uri),
        );

        return $stub;
    }

    private function createBareRequest(string $method, string $uri): RequestInterface
    {
        $uriStub = $this->createStub(UriInterface::class);
        $uriStub->method('__toString')->willReturn($uri);

        $request = $this->createStub(RequestInterface::class);
        $request->method('withHeader')->willReturnCallback(fn (): RequestInterface => $request);
        $request->method('withBody')->willReturnCallback(fn (): RequestInterface => $request);
        $request->method('withoutHeader')->willReturnCallback(fn (): RequestInterface => $request);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uriStub);

        return $request;
    }

    private function createBareStreamFactory(): StreamFactoryInterface
    {
        $stub = $this->createStub(StreamFactoryInterface::class);
        $stub->method('createStream')->willReturnCallback(function (string $content): StreamInterface {
            $stream = $this->createStub(StreamInterface::class);
            $stream->method('__toString')->willReturn($content);
            $stream->method('getContents')->willReturn($content);

            return $stream;
        });

        return $stub;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createJsonResponseMock(array $data, int $statusCode = 200): ResponseInterface
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);
        $stream->method('getContents')->willReturn($body);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($stream);

        return $response;
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
