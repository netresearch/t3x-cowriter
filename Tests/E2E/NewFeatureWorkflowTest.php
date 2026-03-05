<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\E2E;

use Netresearch\NrLlm\Domain\Model\PromptTemplate;
use Netresearch\NrLlm\Domain\Model\TranslationResult;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\Repository\PromptTemplateRepository;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Service\Feature\VisionService;
use Netresearch\T3Cowriter\Controller\TemplateController;
use Netresearch\T3Cowriter\Controller\TranslationController;
use Netresearch\T3Cowriter\Controller\VisionController;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Tests\Support\TestQueryResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use stdClass;
use TYPO3\CMS\Core\Context\Context;

/**
 * E2E tests for Vision, Translation, and Template controller workflows.
 *
 * Tests the full path from controller entry point through service layer
 * and back, verifying correct data flow, error handling, and rate limiting.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(VisionController::class)]
#[CoversClass(TranslationController::class)]
#[CoversClass(TemplateController::class)]
final class NewFeatureWorkflowTest extends AbstractE2ETestCase
{
    // =========================================================================
    // Helper: Create Vision controller stack
    // =========================================================================

    /**
     * Create a VisionController with mocked dependencies.
     *
     * @return array{controller: VisionController, visionService: VisionService&MockObject, rateLimiter: RateLimiterInterface&MockObject, context: Context&MockObject}
     */
    private function createVisionStack(): array
    {
        $visionService = $this->createMock(VisionService::class);
        $rateLimiter   = $this->createMock(RateLimiterInterface::class);
        $context       = $this->createMock(Context::class);

        // Default: allow all requests
        $rateLimiter->method('checkLimit')->willReturn(
            new RateLimitResult(allowed: true, limit: 20, remaining: 19, resetTime: time() + 60),
        );

        // Default: return user ID 1
        $context->method('getPropertyFromAspect')->willReturn(1);

        $controller = new VisionController(
            $visionService,
            $rateLimiter,
            $context,
            $this->logger,
        );

        return [
            'controller'    => $controller,
            'visionService' => $visionService,
            'rateLimiter'   => $rateLimiter,
            'context'       => $context,
        ];
    }

    // =========================================================================
    // Helper: Create Translation controller stack
    // =========================================================================

    /**
     * Create a TranslationController with mocked dependencies.
     *
     * @return array{controller: TranslationController, translationService: TranslationService&MockObject, rateLimiter: RateLimiterInterface&MockObject, context: Context&MockObject}
     */
    private function createTranslationStack(): array
    {
        $translationService = $this->createMock(TranslationService::class);
        $rateLimiter        = $this->createMock(RateLimiterInterface::class);
        $context            = $this->createMock(Context::class);

        // Default: allow all requests
        $rateLimiter->method('checkLimit')->willReturn(
            new RateLimitResult(allowed: true, limit: 20, remaining: 19, resetTime: time() + 60),
        );

        // Default: return user ID 1
        $context->method('getPropertyFromAspect')->willReturn(1);

        $controller = new TranslationController(
            $translationService,
            $rateLimiter,
            $context,
            $this->logger,
        );

        return [
            'controller'         => $controller,
            'translationService' => $translationService,
            'rateLimiter'        => $rateLimiter,
            'context'            => $context,
        ];
    }

    // =========================================================================
    // Helper: Create Template controller stack
    // =========================================================================

    /**
     * Create a TemplateController with mocked dependencies.
     *
     * @return array{controller: TemplateController, templateRepo: PromptTemplateRepository&MockObject}
     */
    private function createTemplateStack(): array
    {
        $templateRepo = $this->createMock(PromptTemplateRepository::class);

        $controller = new TemplateController(
            $templateRepo,
            $this->logger,
        );

        return [
            'controller'   => $controller,
            'templateRepo' => $templateRepo,
        ];
    }

    // =========================================================================
    // Helper: Create a parsed-body request
    // =========================================================================

    /**
     * Create a mock ServerRequest with parsed body data.
     *
     * The new controllers use $request->getParsedBody() directly.
     *
     * @param array<string, mixed> $body
     */
    private function createParsedBodyRequest(array $body): ServerRequestInterface
    {
        $request = self::createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);

        return $request;
    }

    // =========================================================================
    // Helper: Create a PromptTemplate stub
    // =========================================================================

    /**
     * @return PromptTemplate&MockObject
     */
    private function createPromptTemplateStub(
        string $identifier,
        string $title,
        ?string $description,
        string $feature,
    ): PromptTemplate&MockObject {
        $template = $this->createMock(PromptTemplate::class);
        $template->method('getIdentifier')->willReturn($identifier);
        $template->method('getTitle')->willReturn($title);
        $template->method('getDescription')->willReturn($description);
        $template->method('getFeature')->willReturn($feature);

        return $template;
    }

    // =========================================================================
    // Vision Workflow Tests
    // =========================================================================

    #[Test]
    public function visionWorkflowGeneratesAltText(): void
    {
        // Arrange
        $stack    = $this->createVisionStack();
        $usage    = new UsageStatistics(promptTokens: 100, completionTokens: 50, totalTokens: 150);
        $response = new VisionResponse(
            description: 'A golden retriever playing fetch in a sunny park.',
            model: 'gpt-4o',
            usage: $usage,
            confidence: 0.95,
        );

        $stack['visionService']->method('analyzeImageFull')->willReturn($response);

        // Act
        $request = $this->createParsedBodyRequest([
            'imageUrl' => 'https://example.com/dog.jpg',
            'prompt'   => 'Generate alt text for this image.',
        ]);
        $result = $stack['controller']->analyzeAction($request);

        // Assert
        self::assertSame(200, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertSame('A golden retriever playing fetch in a sunny park.', $data['altText']);
        self::assertSame('gpt-4o', $data['model']);
        self::assertSame(0.95, $data['confidence']);
        self::assertSame(100, $data['usage']['promptTokens']);
        self::assertSame(50, $data['usage']['completionTokens']);
        self::assertSame(150, $data['usage']['totalTokens']);
    }

    #[Test]
    public function visionWorkflowHandlesRateLimiting(): void
    {
        // Arrange: rate limiter denies request
        $stack                = $this->createVisionStack();
        $stack['rateLimiter'] = $this->createMock(RateLimiterInterface::class);
        $stack['rateLimiter']->method('checkLimit')->willReturn(
            new RateLimitResult(allowed: false, limit: 20, remaining: 0, resetTime: time() + 60),
        );

        // Rebuild controller with rate-limited limiter
        $controller = new VisionController(
            $stack['visionService'],
            $stack['rateLimiter'],
            $stack['context'],
            $this->logger,
        );

        // Act
        $request = $this->createParsedBodyRequest([
            'imageUrl' => 'https://example.com/image.jpg',
        ]);
        $result = $controller->analyzeAction($request);

        // Assert
        self::assertSame(429, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
        self::assertStringContainsString('Rate limit', $data['error']);
    }

    #[Test]
    public function visionWorkflowHandlesMissingImageUrl(): void
    {
        // Arrange
        $stack = $this->createVisionStack();

        // Act: empty body, no imageUrl
        $request = $this->createParsedBodyRequest([]);
        $result  = $stack['controller']->analyzeAction($request);

        // Assert
        self::assertSame(400, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
        self::assertStringContainsString('imageUrl', $data['error']);
    }

    #[Test]
    public function visionWorkflowHandlesServiceFailure(): void
    {
        // Arrange: VisionService throws
        $stack = $this->createVisionStack();
        $stack['visionService']->method('analyzeImageFull')
            ->willThrowException(new RuntimeException('API connection timeout'));

        // Act
        $request = $this->createParsedBodyRequest([
            'imageUrl' => 'https://example.com/image.jpg',
        ]);
        $result = $stack['controller']->analyzeAction($request);

        // Assert
        self::assertSame(500, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
        // Error should not expose internal details
        self::assertStringNotContainsString('timeout', $data['error']);
        self::assertStringContainsString('analysis failed', strtolower($data['error']));
    }

    // =========================================================================
    // Translation Workflow Tests
    // =========================================================================

    #[Test]
    public function translationWorkflowTranslatesText(): void
    {
        // Arrange
        $stack  = $this->createTranslationStack();
        $usage  = new UsageStatistics(promptTokens: 30, completionTokens: 25, totalTokens: 55);
        $result = new TranslationResult(
            translation: 'Hallo Welt',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 0.98,
            usage: $usage,
        );

        $stack['translationService']->method('translate')->willReturn($result);

        // Act
        $request = $this->createParsedBodyRequest([
            'text'           => 'Hello World',
            'targetLanguage' => 'de',
        ]);
        $response = $stack['controller']->translateAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertSame('Hallo Welt', $data['translation']);
        self::assertSame('en', $data['sourceLanguage']);
        self::assertSame(0.98, $data['confidence']);
    }

    #[Test]
    public function translationWorkflowWithFormalityOption(): void
    {
        // Arrange
        $stack  = $this->createTranslationStack();
        $usage  = new UsageStatistics(promptTokens: 40, completionTokens: 35, totalTokens: 75);
        $result = new TranslationResult(
            translation: 'Wie geht es Ihnen?',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 0.96,
            usage: $usage,
        );

        $stack['translationService']->method('translate')->willReturn($result);

        // Act
        $request = $this->createParsedBodyRequest([
            'text'           => 'How are you?',
            'targetLanguage' => 'de',
            'formality'      => 'formal',
        ]);
        $response = $stack['controller']->translateAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertSame('Wie geht es Ihnen?', $data['translation']);
    }

    #[Test]
    public function translationWorkflowHandlesRateLimiting(): void
    {
        // Arrange: rate limiter denies request
        $stack                = $this->createTranslationStack();
        $stack['rateLimiter'] = $this->createMock(RateLimiterInterface::class);
        $stack['rateLimiter']->method('checkLimit')->willReturn(
            new RateLimitResult(allowed: false, limit: 20, remaining: 0, resetTime: time() + 60),
        );

        $controller = new TranslationController(
            $stack['translationService'],
            $stack['rateLimiter'],
            $stack['context'],
            $this->logger,
        );

        // Act
        $request = $this->createParsedBodyRequest([
            'text'           => 'Hello World',
            'targetLanguage' => 'de',
        ]);
        $response = $controller->translateAction($request);

        // Assert
        self::assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
        self::assertStringContainsString('Rate limit', $data['error']);
    }

    #[Test]
    public function translationWorkflowHandlesMissingText(): void
    {
        // Arrange
        $stack = $this->createTranslationStack();

        // Act: empty text
        $request = $this->createParsedBodyRequest([
            'text'           => '',
            'targetLanguage' => 'de',
        ]);
        $response = $stack['controller']->translateAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
        self::assertStringContainsString('text', strtolower($data['error']));
    }

    #[Test]
    public function translationWorkflowHandlesMissingTargetLanguage(): void
    {
        // Arrange
        $stack = $this->createTranslationStack();

        // Act: missing targetLanguage
        $request = $this->createParsedBodyRequest([
            'text' => 'Hello World',
        ]);
        $response = $stack['controller']->translateAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
        self::assertStringContainsString('targetlanguage', strtolower($data['error']));
    }

    #[Test]
    public function translationWorkflowHandlesServiceFailure(): void
    {
        // Arrange: TranslationService throws
        $stack = $this->createTranslationStack();
        $stack['translationService']->method('translate')
            ->willThrowException(new RuntimeException('Provider unavailable'));

        // Act
        $request = $this->createParsedBodyRequest([
            'text'           => 'Hello World',
            'targetLanguage' => 'de',
        ]);
        $response = $stack['controller']->translateAction($request);

        // Assert
        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
        self::assertStringNotContainsString('unavailable', $data['error']);
        self::assertStringContainsString('translation failed', strtolower($data['error']));
    }

    // =========================================================================
    // Template Workflow Tests
    // =========================================================================

    #[Test]
    public function templateWorkflowListsActiveTemplates(): void
    {
        // Arrange
        $stack     = $this->createTemplateStack();
        $template1 = $this->createPromptTemplateStub('improve-text', 'Improve Text', 'Improves text quality', 'writing');
        $template2 = $this->createPromptTemplateStub('summarize', 'Summarize', 'Creates summaries', 'analysis');

        $queryResult = new TestQueryResult([$template1, $template2]);
        $stack['templateRepo']->method('findActive')->willReturn($queryResult);

        // Act
        $request  = $this->createParsedBodyRequest([]);
        $response = $stack['controller']->listAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertCount(2, $data['templates']);

        self::assertSame('improve-text', $data['templates'][0]['identifier']);
        self::assertSame('Improve Text', $data['templates'][0]['name']);
        self::assertSame('Improves text quality', $data['templates'][0]['description']);
        self::assertSame('writing', $data['templates'][0]['category']);

        self::assertSame('summarize', $data['templates'][1]['identifier']);
        self::assertSame('Summarize', $data['templates'][1]['name']);
    }

    #[Test]
    public function templateWorkflowHandlesEmptyTemplateList(): void
    {
        // Arrange
        $stack       = $this->createTemplateStack();
        $queryResult = new TestQueryResult([]);
        $stack['templateRepo']->method('findActive')->willReturn($queryResult);

        // Act
        $request  = $this->createParsedBodyRequest([]);
        $response = $stack['controller']->listAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertSame([], $data['templates']);
    }

    #[Test]
    public function templateWorkflowHandlesRepositoryError(): void
    {
        // Arrange: repository throws
        $stack = $this->createTemplateStack();
        $stack['templateRepo']->method('findActive')
            ->willThrowException(new RuntimeException('Database connection lost'));

        // Act
        $request  = $this->createParsedBodyRequest([]);
        $response = $stack['controller']->listAction($request);

        // Assert
        self::assertSame(500, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
        self::assertStringNotContainsString('Database', $data['error']);
        self::assertStringContainsString('templates', strtolower($data['error']));
    }

    #[Test]
    public function templateWorkflowSkipsInvalidObjects(): void
    {
        // Arrange: mixed objects — only PromptTemplate instances should appear
        $stack         = $this->createTemplateStack();
        $validTemplate = $this->createPromptTemplateStub('valid', 'Valid Template', 'A valid one', 'writing');
        $invalidObject = new stdClass();

        $queryResult = new TestQueryResult([$validTemplate, $invalidObject, $validTemplate]);
        $stack['templateRepo']->method('findActive')->willReturn($queryResult);

        // Act
        $request  = $this->createParsedBodyRequest([]);
        $response = $stack['controller']->listAction($request);

        // Assert: only the 2 valid PromptTemplate instances appear
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertCount(2, $data['templates']);
        self::assertSame('valid', $data['templates'][0]['identifier']);
        self::assertSame('valid', $data['templates'][1]['identifier']);
    }
}
