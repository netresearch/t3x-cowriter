<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use Closure;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderMiddlewareInterface;
use Netresearch\NrLlm\Provider\Middleware\TelemetryMiddleware;
use Netresearch\NrLlm\Provider\Middleware\Usage\SpecializedUsageIntent;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Guardrail\InputGuardrailScreener;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Image\DallEImageService;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Netresearch\T3Cowriter\Controller\ImageGenerationController;
use Netresearch\T3Cowriter\Domain\DTO\ImageGenerationRequest;
use Netresearch\T3Cowriter\Service\Image\GeneratedImageStorageInterface;
use Netresearch\T3Cowriter\Service\Image\ImageGenerationConfiguration;
use Netresearch\T3Cowriter\Service\Image\ImageRejectedException;
use Netresearch\T3Cowriter\Service\Image\StoredImage;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Tests\Support\ConfigurationAccessDouble;
use Netresearch\T3Cowriter\Tests\Support\XliffLanguageServiceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Resource\Folder;

/**
 * The image route with a real nr-llm DallEImageService: the service is final,
 * so, as for DeepL in TranslationControllerTest, only its HTTP transport is
 * replaced (setHttpClient(), nr-llm's own test seam) and its budget service
 * is a stub. The FAL side is the storage interface; GeneratedImageStorageTest
 * and the functional test cover the real storage.
 */
#[CoversClass(ImageGenerationController::class)]
#[CoversClass(ImageGenerationRequest::class)]
#[CoversClass(ImageGenerationConfiguration::class)]
#[CoversClass(StoredImage::class)]
final class ImageGenerationControllerTest extends TestCase
{
    use XliffLanguageServiceTrait;

    /**
     * A 1x1 PNG.
     */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /**
     * Every request the image service sent upstream, as decoded JSON.
     *
     * @var list<array<string, mixed>>
     */
    private array $sentPayloads = [];

    /**
     * The context of the last call the service ran through its pipeline.
     */
    private ?ProviderCallContext $capturedContext = null;

    /**
     * What the controller logged: [level, message, context].
     *
     * @var list<array{string, string, array<mixed>}>
     */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->useXliffLanguageService();
        $this->sentPayloads    = [];
        $this->capturedContext = null;
        $this->logged          = [];
    }

    #[Test]
    public function aRateLimitedRequestIsRefusedBeforeAnythingElse(): void
    {
        $controller = $this->controller(rateLimitAllowed: false);

        $response = $controller->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        $this->assertRefused($response, 429);
        self::assertSame([], $this->sentPayloads);
    }

    #[Test]
    public function theRouteRefusesWhileTheSettingIsOff(): void
    {
        $storage = $this->createMock(GeneratedImageStorageInterface::class);
        $storage->expects(self::never())->method('targetFolder');
        $storage->expects(self::never())->method('store');

        $controller = $this->controller(settings: ['enable' => '0'], storage: $storage);
        $response   = $controller->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        $this->assertRefused($response, 403);
        $data = $this->decode($response);
        self::assertFalse($data['success']);
        self::assertStringContainsString('image.enable', $data['error']);
        self::assertSame([], $this->sentPayloads, 'no image may be generated while the setting is off');
    }

    /**
     * Only the string '1' switches the feature on.
     */
    #[Test]
    #[DataProvider('settingsThatAreNotOn')]
    public function anythingButOneCountsAsOff(mixed $value): void
    {
        $controller = $this->controller(settings: ['enable' => $value]);

        $this->assertRefused($controller->generateAction($this->jsonRequest(['prompt' => 'A lighthouse'])), 403);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function settingsThatAreNotOn(): iterable
    {
        yield 'zero' => ['0'];
        yield 'empty' => [''];
        yield 'true as text' => ['true'];
        yield 'missing' => [null];
    }

    #[Test]
    public function invalidJsonIsRefused(): void
    {
        $response = $this->controller()->generateAction($this->rawRequest('{not json'));

        $this->assertRefused($response, 400);
        self::assertSame([], $this->sentPayloads);
    }

    #[Test]
    public function anEmptyPromptIsRefused(): void
    {
        $response = $this->controller()->generateAction($this->jsonRequest(['prompt' => "  \n "]));

        $this->assertRefused($response, 400);
        self::assertSame('Describe the image first.', $this->decode($response)['error']);
        self::assertSame([], $this->sentPayloads);
    }

    #[Test]
    public function aPromptOverTheLimitIsRefused(): void
    {
        $prompt   = str_repeat('a', ImageGenerationRequest::MAX_PROMPT_LENGTH + 1);
        $response = $this->controller()->generateAction($this->jsonRequest(['prompt' => $prompt]));

        $this->assertRefused($response, 400);
        self::assertStringContainsString('1000', $this->decode($response)['error']);
        self::assertSame([], $this->sentPayloads);
    }

    #[Test]
    public function aPromptAtTheLimitIsAccepted(): void
    {
        $prompt   = str_repeat('ä', ImageGenerationRequest::MAX_PROMPT_LENGTH);
        $response = $this->controller()->generateAction($this->jsonRequest(['prompt' => $prompt]));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function anUnknownPinnedConfigurationIsRefused(): void
    {
        $repository = $this->createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn(null);

        $controller = $this->controller(configurationRepository: $repository);
        $response   = $controller->generateAction($this->jsonRequest(['prompt' => 'A lighthouse', 'configuration' => 'missing']));

        $this->assertRefused($response, 404);
        self::assertSame([], $this->sentPayloads);
    }

    #[Test]
    public function anEditorWithoutAWritableFolderIsRefusedBeforeGeneration(): void
    {
        $storage = $this->createMock(GeneratedImageStorageInterface::class);
        $storage->method('targetFolder')->willReturn(null);
        $storage->expects(self::never())->method('store');

        $response = $this->controller(storage: $storage)->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        $this->assertRefused($response, 403);
        self::assertStringContainsString('file mount', $this->decode($response)['error']);
        self::assertSame([], $this->sentPayloads, 'an editor who cannot store the image must not pay for it');
    }

    #[Test]
    public function aBudgetRefusalIsAnsweredAs429BeforeTheProviderIsCalled(): void
    {
        $budget  = new BudgetCheckResult(false, BudgetCheckResult::LIMIT_DAILY_COST, 5.0, 5.0, 'Daily cost limit reached');
        $storage = $this->createMock(GeneratedImageStorageInterface::class);
        $storage->method('targetFolder')->willReturn($this->createStub(Folder::class));
        $storage->expects(self::never())->method('store');

        $response = $this->controller(storage: $storage, budget: $budget)
            ->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        $this->assertRefused($response, 429);
        $data = $this->decode($response);
        self::assertTrue($data['budgetExceeded']);
        self::assertSame(BudgetCheckResult::LIMIT_DAILY_COST, $data['exceededLimit']);
        self::assertSame([], $this->sentPayloads);
    }

    #[Test]
    public function aMissingOpenAiKeyIsNamed(): void
    {
        $response = $this->controller(vaultHasKey: false)->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        $this->assertRefused($response, 500);
        self::assertStringContainsString('OpenAI API key', $this->decode($response)['error']);
    }

    #[Test]
    public function aModelSettingThatIsNoOpenAiImageModelIsRefusedBeforeGeneration(): void
    {
        $response = $this->controller(settings: ['enable' => '1', 'model' => 'flux-schnell'])
            ->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        $this->assertRefused($response, 500);
        self::assertStringContainsString('"flux-schnell"', $this->decode($response)['error']);
        self::assertSame([], $this->sentPayloads);
    }

    #[Test]
    public function aGeneratedImageIsStoredAndDescribed(): void
    {
        $folder  = $this->createStub(Folder::class);
        $storage = $this->createMock(GeneratedImageStorageInterface::class);
        $storage->method('targetFolder')->willReturn($folder);
        $storage->expects(self::once())
            ->method('store')
            ->with(self::PNG_BASE64, 'A lighthouse at dusk', $folder)
            ->willReturn(new StoredImage(42, '/fileadmin/user_upload/a-lighthouse-at-dusk-0a1b2c3d.png', 1024, 1024, 'image/png', '1:/user_upload/a-lighthouse-at-dusk-0a1b2c3d.png'));

        $response = $this->controller(storage: $storage)
            ->generateAction($this->jsonRequest(['prompt' => '  A lighthouse at dusk ']));

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertTrue($data['success']);
        self::assertSame([
            'fileUid'   => 42,
            'fileTable' => 'sys_file',
            'url'       => '/fileadmin/user_upload/a-lighthouse-at-dusk-0a1b2c3d.png',
            'width'     => 1024,
            'height'    => 1024,
            'mimeType'  => 'image/png',
            'file'      => '1:/user_upload/a-lighthouse-at-dusk-0a1b2c3d.png',
        ], $data['image']);
        self::assertSame('A lighthouse at dusk', $data['alt']);
        self::assertArrayNotHasKey('revisedPrompt', $data);
        self::assertSame('19', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function gptImageIsAskedWithoutResponseFormatAndForTheSquareSize(): void
    {
        $this->controller()->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        self::assertCount(1, $this->sentPayloads);
        $payload = $this->sentPayloads[0];
        self::assertSame('gpt-image-2', $payload['model']);
        self::assertSame('1024x1024', $payload['size']);
        self::assertSame(1, $payload['n']);
        self::assertArrayNotHasKey('response_format', $payload);
        self::assertArrayNotHasKey('style', $payload);
    }

    #[Test]
    public function theCallIsAttributedToTheEditorAndThisExtension(): void
    {
        $this->controller()->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        self::assertInstanceOf(ProviderCallContext::class, $this->capturedContext);
        $metadata = $this->capturedContext->metadata;
        self::assertSame('t3_cowriter', $metadata[TelemetryMiddleware::METADATA_SOURCE_EXTENSION] ?? null);
        self::assertSame('generateImage', $metadata[TelemetryMiddleware::METADATA_SOURCE_OPERATION] ?? null);
        $intent = $metadata[SpecializedUsageIntent::METADATA_KEY] ?? null;
        self::assertInstanceOf(SpecializedUsageIntent::class, $intent);
        self::assertSame(7, $intent->beUserUid);
        self::assertSame('gpt-image-2', $intent->modelId);
    }

    #[Test]
    public function theModelSettingIsUsed(): void
    {
        $this->controller(settings: ['enable' => '1', 'model' => ' gpt-image-2.5-flare '])
            ->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        self::assertSame('gpt-image-2.5-flare', $this->sentPayloads[0]['model'] ?? null);
    }

    #[Test]
    public function aDallEModelIsAskedForBase64(): void
    {
        $this->controller(settings: ['enable' => '1', 'model' => 'dall-e-3'], revisedPrompt: 'A tall lighthouse at dusk')
            ->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        self::assertSame('dall-e-3', $this->sentPayloads[0]['model'] ?? null);
        self::assertSame('b64_json', $this->sentPayloads[0]['response_format'] ?? null);
    }

    #[Test]
    public function theRevisedPromptIsPassedOnWhenTheModelChangedIt(): void
    {
        $response = $this->controller(revisedPrompt: 'A tall lighthouse at dusk')
            ->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        self::assertSame('A tall lighthouse at dusk', $this->decode($response)['revisedPrompt']);
    }

    #[Test]
    public function anAnswerWithoutImageDataIsRejectedAndNothingIsStored(): void
    {
        $storage = $this->createStub(GeneratedImageStorageInterface::class);
        $storage->method('targetFolder')->willReturn($this->createStub(Folder::class));
        $storage->method('store')->willThrowException(new ImageRejectedException(ImageRejectedException::REASON_MISSING, 'no data'));

        $response = $this->controller(storage: $storage, imageData: ['url' => 'https://example.org/temporary.png'])
            ->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        $this->assertRefused($response, 502);
        self::assertStringContainsString('Nothing was stored', $this->decode($response)['error']);
    }

    #[Test]
    public function aStorageFailureIsReportedWithoutDetails(): void
    {
        $storage = $this->createStub(GeneratedImageStorageInterface::class);
        $storage->method('targetFolder')->willReturn($this->createStub(Folder::class));
        $storage->method('store')->willThrowException(new RuntimeException('/var/www/secret/path is not writable'));

        $response = $this->controller(storage: $storage)->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        $this->assertRefused($response, 500);
        $error = $this->decode($response)['error'];
        self::assertStringContainsString('could not be stored', $error);
        self::assertStringNotContainsString('/var/www', $error);
    }

    #[Test]
    public function aProviderFailureIsReportedWithoutDetails(): void
    {
        $response = $this->controller(providerStatus: 500)->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        $this->assertRefused($response, 500);
        self::assertSame('The image could not be generated. Check the TYPO3 system log for details.', $this->decode($response)['error']);
    }

    #[Test]
    public function aProviderRateLimitIsAnsweredAs429(): void
    {
        $response = $this->controller(providerStatus: 429)->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        $this->assertRefused($response, 429);
        self::assertArrayNotHasKey('budgetExceeded', $this->decode($response));
    }

    #[Test]
    public function anInvalidModelSettingIsLoggedWithTheModel(): void
    {
        $this->controller(settings: ['enable' => '1', 'model' => 'flux-schnell'])
            ->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        [$level, , $context] = $this->onlyLogRecord();
        self::assertSame('error', $level);
        self::assertSame('flux-schnell', $context['model']);
        self::assertIsString($context['exception']);
    }

    #[Test]
    public function aProviderFailureIsLoggedWithModelAndReason(): void
    {
        $this->controller(providerStatus: 500)->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        [$level, , $context] = $this->onlyLogRecord();
        self::assertSame('error', $level);
        self::assertSame('gpt-image-2', $context['model']);
        self::assertStringContainsString('upstream failure', $context['exception']);
    }

    #[Test]
    public function aRejectedImageIsLoggedWithTheReason(): void
    {
        $storage = $this->createStub(GeneratedImageStorageInterface::class);
        $storage->method('targetFolder')->willReturn($this->createStub(Folder::class));
        $storage->method('store')->willThrowException(new ImageRejectedException(ImageRejectedException::REASON_TYPE, 'no image'));

        $this->controller(storage: $storage)->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        [$level, , $context] = $this->onlyLogRecord();
        self::assertSame('warning', $level);
        self::assertSame(ImageRejectedException::REASON_TYPE, $context['reason']);
        self::assertSame('no image', $context['exception']);
    }

    #[Test]
    public function aStorageFailureIsLoggedWithTheFolder(): void
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('getCombinedIdentifier')->willReturn('1:/user_upload/');
        $storage = $this->createStub(GeneratedImageStorageInterface::class);
        $storage->method('targetFolder')->willReturn($folder);
        $storage->method('store')->willThrowException(new RuntimeException('disk full'));

        $this->controller(storage: $storage)->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        [$level, , $context] = $this->onlyLogRecord();
        self::assertSame('error', $level);
        self::assertSame('1:/user_upload/', $context['folder']);
        self::assertSame('disk full', $context['exception']);
    }

    #[Test]
    public function aSuccessfulGenerationLogsNothing(): void
    {
        $this->controller()->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        self::assertSame([], $this->logged);
    }

    #[Test]
    public function aProviderRateLimitNamesTheProvidersLimit(): void
    {
        $response = $this->controller(providerStatus: 429)->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        self::assertStringContainsString('rate limit', strtolower($this->decode($response)['error']));
    }

    #[Test]
    public function dallE3GetsTheStandardQualityAndTheNaturalStyle(): void
    {
        $this->controller(settings: ['enable' => '1', 'model' => 'dall-e-3'])
            ->generateAction($this->jsonRequest(['prompt' => 'A lighthouse']));

        self::assertSame('standard', $this->sentPayloads[0]['quality'] ?? null);
        self::assertSame('natural', $this->sentPayloads[0]['style'] ?? null);
    }

    #[Test]
    public function thePinnedConfigurationIsLookedUpTrimmed(): void
    {
        $repository = $this->createMock(LlmConfigurationRepository::class);
        $repository->expects(self::once())->method('findOneByIdentifier')->with('images')->willReturn(null);

        $response = $this->controller(configurationRepository: $repository)
            ->generateAction($this->jsonRequest(['prompt' => 'A lighthouse', 'configuration' => '  images ']));

        $this->assertRefused($response, 404);
    }

    #[Test]
    public function aBlankPinnedConfigurationCountsAsNone(): void
    {
        $repository = $this->createMock(LlmConfigurationRepository::class);
        $repository->expects(self::never())->method('findOneByIdentifier');

        $response = $this->controller(configurationRepository: $repository)
            ->generateAction($this->jsonRequest(['prompt' => 'A lighthouse', 'configuration' => '   ']));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function aConfigurationIdentifierOverTheLimitIsRefused(): void
    {
        $accepted = new ImageGenerationRequest('A lighthouse', str_repeat('ä', 255));
        $refused  = new ImageGenerationRequest('A lighthouse', str_repeat('a', 256));

        self::assertTrue($accepted->isValid());
        self::assertFalse($refused->isValid());
    }

    #[Test]
    public function aPromptOfExactlyTheAltLengthStaysWhole(): void
    {
        $prompt = str_repeat('ü', ImageGenerationController::MAX_ALT_LENGTH);

        self::assertSame($prompt, ImageGenerationController::altText($prompt));
    }

    #[Test]
    public function aPromptOneOverTheAltLengthIsShortened(): void
    {
        $alt = ImageGenerationController::altText(str_repeat('a', ImageGenerationController::MAX_ALT_LENGTH + 1));

        self::assertSame(str_repeat('a', ImageGenerationController::MAX_ALT_LENGTH - 1) . '…', $alt);
    }

    #[Test]
    public function aSpaceInTheFirstHalfDoesNotShortenTheAltTextToAFewWords(): void
    {
        $alt = ImageGenerationController::altText('Ein ' . str_repeat('ü', 300));

        self::assertSame('Ein ' . str_repeat('ü', ImageGenerationController::MAX_ALT_LENGTH - 5) . '…', $alt);
    }

    #[Test]
    public function aSpaceJustAfterTheMiddleIsUsedForTheAltText(): void
    {
        $head = str_repeat('ö', 126);
        $alt  = ImageGenerationController::altText($head . ' ' . str_repeat('ü', 300));

        self::assertSame($head . '…', $alt);
    }

    #[Test]
    public function aSpaceExactlyInTheMiddleIsNotUsedForTheAltText(): void
    {
        $head = str_repeat('ö', 125);
        $alt  = ImageGenerationController::altText($head . ' ' . str_repeat('ü', 300));

        self::assertSame($head . ' ' . str_repeat('ü', 123) . '…', $alt);
    }

    #[Test]
    public function theAltTextKeepsTheBeginningOfTheLastWord(): void
    {
        $words = str_repeat('wörd ', 60);
        $alt   = ImageGenerationController::altText($words);

        self::assertStringStartsWith('wörd wörd', $alt);
        self::assertSame(mb_substr($words, 0, mb_strlen($alt) - 1), mb_substr($alt, 0, -1));
    }

    #[Test]
    public function theAltTextIsThePromptOnOneLine(): void
    {
        self::assertSame('A lighthouse at dusk', ImageGenerationController::altText("  A lighthouse\n\tat  dusk "));
    }

    #[Test]
    public function aLongPromptIsShortenedAtAWordForTheAltText(): void
    {
        $prompt = str_repeat('word ', 80);
        $alt    = ImageGenerationController::altText($prompt);

        self::assertLessThanOrEqual(ImageGenerationController::MAX_ALT_LENGTH, mb_strlen($alt));
        self::assertStringEndsWith('word…', $alt);
    }

    #[Test]
    public function aLongPromptWithoutSpacesIsCutForTheAltText(): void
    {
        $alt = ImageGenerationController::altText(str_repeat('ü', 400));

        self::assertSame(ImageGenerationController::MAX_ALT_LENGTH, mb_strlen($alt));
        self::assertStringEndsWith('ü…', $alt);
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $imageData the data[0] element the provider answers with
     */
    private function controller(
        array $settings = ['enable' => '1'],
        bool $rateLimitAllowed = true,
        ?GeneratedImageStorageInterface $storage = null,
        ?LlmConfigurationRepository $configurationRepository = null,
        ?BudgetCheckResult $budget = null,
        bool $vaultHasKey = true,
        int $providerStatus = 200,
        ?string $revisedPrompt = null,
        array $imageData = ['b64_json' => self::PNG_BASE64],
    ): ImageGenerationController {
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(new RateLimitResult($rateLimitAllowed, 20, 19, time() + 60));

        $context = $this->createStub(Context::class);
        $context->method('getPropertyFromAspect')->willReturn(7);

        if (!$storage instanceof GeneratedImageStorageInterface) {
            $storage = $this->createStub(GeneratedImageStorageInterface::class);
            $storage->method('targetFolder')->willReturn($this->createStub(Folder::class));
            $storage->method('store')->willReturn(new StoredImage(1, '/fileadmin/image.png', 1, 1, 'image/png', '1:/image.png'));
        }

        if ($revisedPrompt !== null) {
            $imageData['revised_prompt'] = $revisedPrompt;
        }

        return new ImageGenerationController(
            $this->imageService($vaultHasKey, $budget, $providerStatus, $imageData),
            $storage,
            new ImageGenerationConfiguration($this->extensionConfiguration($settings)),
            ConfigurationAccessDouble::selector($configurationRepository ?? $this->createStub(LlmConfigurationRepository::class)),
            $rateLimiter,
            $context,
            $this->recordingLogger(),
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function extensionConfiguration(array $settings): ExtensionConfiguration
    {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturnCallback(
            static function (string $extension, string $path = '') use ($settings): mixed {
                if ($extension === 'nr_llm') {
                    return ['providers' => ['openai' => ['apiKeyIdentifier' => 'openai-key']]];
                }

                return $settings[substr($path, strlen('image/'))] ?? null;
            },
        );

        return $configuration;
    }

    /**
     * @param array<string, mixed> $imageData
     */
    private function imageService(bool $vaultHasKey, ?BudgetCheckResult $budget, int $providerStatus, array $imageData): DallEImageService
    {
        $vault = $this->createStub(VaultServiceInterface::class);
        $vault->method('exists')->willReturn($vaultHasKey);

        $budgetService = $this->createStub(BudgetServiceInterface::class);
        $budgetService->method('check')->willReturn($budget ?? BudgetCheckResult::allowed());

        $service = new DallEImageService(
            $vault,
            $this->requestFactory(),
            $this->streamFactory(),
            $this->extensionConfiguration([]),
            $this->createStub(UsageTrackerServiceInterface::class),
            new NullLogger(),
            $this->createStub(SpecializedCostCalculatorInterface::class),
            $budgetService,
            new MiddlewarePipeline([$this->capturingMiddleware()]),
            new InputGuardrailScreener([]),
        );

        $body = $providerStatus === 200
            ? ['created' => 1, 'data' => [$imageData]]
            : ['error' => ['message' => 'upstream failure']];

        $client = $this->createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturn($this->jsonResponse($body, $providerStatus));
        $service->setHttpClient($client);

        return $service;
    }

    private function capturingMiddleware(): ProviderMiddlewareInterface
    {
        $capture = function (ProviderCallContext $context): void {
            $this->capturedContext = $context;
        };

        return new readonly class ($capture) implements ProviderMiddlewareInterface {
            /**
             * @param Closure(ProviderCallContext): void $capture
             */
            public function __construct(private Closure $capture) {}

            public function handle(ProviderCallContext $context, callable $next): mixed
            {
                ($this->capture)($context);

                return $next($context);
            }
        };
    }

    private function requestFactory(): RequestFactoryInterface
    {
        $factory = $this->createStub(RequestFactoryInterface::class);
        $factory->method('createRequest')->willReturnCallback(function (string $method, string $uri): RequestInterface {
            $uriStub = $this->createStub(UriInterface::class);
            $uriStub->method('__toString')->willReturn($uri);

            $request = $this->createStub(RequestInterface::class);
            $request->method('withHeader')->willReturnCallback(static fn (): RequestInterface => $request);
            $request->method('withBody')->willReturnCallback(static fn (): RequestInterface => $request);
            $request->method('withoutHeader')->willReturnCallback(static fn (): RequestInterface => $request);
            $request->method('getMethod')->willReturn($method);
            $request->method('getUri')->willReturn($uriStub);

            return $request;
        });

        return $factory;
    }

    /**
     * Records every JSON body the service builds; one per upstream request.
     */
    private function streamFactory(): StreamFactoryInterface
    {
        $factory = $this->createStub(StreamFactoryInterface::class);
        $factory->method('createStream')->willReturnCallback(function (string $content): StreamInterface {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $this->sentPayloads[] = $decoded;
            }

            $stream = $this->createStub(StreamInterface::class);
            $stream->method('__toString')->willReturn($content);
            $stream->method('getContents')->willReturn($content);

            return $stream;
        });

        return $factory;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(array $data, int $statusCode): ResponseInterface
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
    private function jsonRequest(array $body): ServerRequestInterface
    {
        return $this->rawRequest(json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function rawRequest(string $rawBody): ServerRequestInterface
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($rawBody);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($stream);

        return $request;
    }

    private function recordingLogger(): LoggerInterface
    {
        $record = function (string $level, string $message, array $context): void {
            $this->logged[] = [$level, $message, $context];
        };

        return new class ($record) extends AbstractLogger {
            /**
             * @param Closure(string, string, array<mixed>): void $record
             */
            public function __construct(private readonly Closure $record) {}

            public function log($level, string|Stringable $message, array $context = []): void
            {
                ($this->record)((string) $level, (string) $message, $context);
            }
        };
    }

    /**
     * The one log record of the request; the prompt never appears in it.
     *
     * @return array{string, string, array<mixed>}
     */
    private function onlyLogRecord(string $prompt = 'A lighthouse'): array
    {
        self::assertCount(1, $this->logged);
        self::assertStringNotContainsString($prompt, (string) json_encode($this->logged[0]));

        return $this->logged[0];
    }

    private function assertRefused(ResponseInterface $response, int $status): void
    {
        self::assertSame($status, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertFalse($data['success']);
        self::assertIsString($data['error']);
        self::assertNotSame('', $data['error']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }
}
