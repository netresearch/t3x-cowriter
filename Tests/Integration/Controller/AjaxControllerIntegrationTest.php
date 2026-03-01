<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Integration\Controller;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\T3Cowriter\Controller\AjaxController;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Tests\Integration\AbstractIntegrationTestCase;
use Override;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * Integration tests for AjaxController.
 *
 * Tests complete request/response flows through the controller
 * with mocked LLM service responses.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(AjaxController::class)]
final class AjaxControllerIntegrationTest extends AbstractIntegrationTestCase
{
    private AjaxController $subject;
    private LlmServiceManagerInterface&MockObject $llmServiceMock;
    private LlmConfigurationRepository&MockObject $configRepoMock;
    private RateLimiterInterface&MockObject $rateLimiterMock;
    private Context&MockObject $contextMock;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->llmServiceMock  = $this->createMock(LlmServiceManagerInterface::class);
        $this->configRepoMock  = $this->createMock(LlmConfigurationRepository::class);
        $this->rateLimiterMock = $this->createMock(RateLimiterInterface::class);
        $this->contextMock     = $this->createMock(Context::class);

        // Default: rate limiter allows requests
        $this->rateLimiterMock->method('checkLimit')->willReturn(
            new RateLimitResult(allowed: true, limit: 20, remaining: 19, resetTime: time() + 60),
        );

        // Default: context returns user ID
        $this->contextMock->method('getPropertyFromAspect')->willReturn(1);

        $this->subject = new AjaxController(
            $this->llmServiceMock,
            $this->configRepoMock,
            $this->rateLimiterMock,
            $this->contextMock,
            new NullLogger(),
        );
    }

    // =========================================================================
    // Complete Flow Tests
    // =========================================================================

    #[Test]
    public function completeFlowWithDefaultConfiguration(): void
    {
        // Arrange: Setup default configuration
        $config = $this->createLlmConfiguration();
        $this->configRepoMock->method('findDefault')->willReturn($config);

        // Arrange: Setup LLM response
        $pair     = $this->getTextImprovementPair();
        $response = $this->createCompletionResponse($pair['improved']);
        $this->llmServiceMock->method('chatWithConfiguration')->willReturn($response);

        // Act: Send completion request
        $request = $this->createJsonRequest(['prompt' => $pair['original']]);
        $result  = $this->subject->completeAction($request);

        // Assert: Response is successful with improved text
        $data = $this->assertSuccessfulJsonResponse($result);
        self::assertSame($pair['improved'], $data['content']);
        self::assertSame('gpt-4o', $data['model']);
        self::assertArrayHasKey('usage', $data);
    }

    #[Test]
    public function completeFlowWithSpecificConfiguration(): void
    {
        // Arrange: Setup specific configuration
        $config = $this->createLlmConfiguration('claude-config', 'Claude Configuration', false);
        $this->configRepoMock
            ->expects(self::once())
            ->method('findOneByIdentifier')
            ->with('claude-config')
            ->willReturn($config);

        // Arrange: Setup LLM response
        $response = $this->createCompletionResponse(
            'Professional content from Claude.',
            'claude-sonnet-4-20250514',
            30,
            80,
            'end_turn',
            'anthropic',
        );
        $this->llmServiceMock->method('chatWithConfiguration')->willReturn($response);

        // Act
        $request = $this->createJsonRequest([
            'prompt'        => 'Improve this text',
            'configuration' => 'claude-config',
        ]);
        $result = $this->subject->completeAction($request);

        // Assert
        $data = $this->assertSuccessfulJsonResponse($result);
        self::assertSame('Professional content from Claude.', $data['content']);
        self::assertSame('claude-sonnet-4-20250514', $data['model']);
    }

    #[Test]
    public function completeFlowWithModelPrefix(): void
    {
        // Arrange: Model override prefix is now handled at prompt level (stripped),
        // but model comes from configuration
        $config = $this->createLlmConfiguration();
        $this->configRepoMock->method('findDefault')->willReturn($config);

        $response = $this->createCompletionResponse('Gemini response', 'gemini-2.0-flash');
        $this->llmServiceMock->method('chatWithConfiguration')->willReturn($response);

        // Act: Use model override prefix (prefix stripped from prompt, model from config)
        $request = $this->createJsonRequest([
            'prompt' => '#cw:gemini-2.0-flash Make this better',
        ]);
        $result = $this->subject->completeAction($request);

        // Assert: Response is successful
        $data = $this->assertSuccessfulJsonResponse($result);
        self::assertSame('Gemini response', $data['content']);
        self::assertSame('gemini-2.0-flash', $data['model']);
    }

    // =========================================================================
    // Security Tests (XSS Prevention)
    // =========================================================================

    #[Test]
    #[DataProvider('xssPayloadProvider')]
    public function completeActionEscapesXssPayloadsInContent(string $payload, string $description): void
    {
        // Arrange: LLM returns malicious content
        $config = $this->createLlmConfiguration();
        $this->configRepoMock->method('findDefault')->willReturn($config);

        $response = $this->createCompletionResponse($payload);
        $this->llmServiceMock->method('chatWithConfiguration')->willReturn($response);

        // Act
        $request = $this->createJsonRequest(['prompt' => 'Test prompt']);
        $result  = $this->subject->completeAction($request);

        // Assert: XSS payload is HTML-escaped (angle brackets become entities)
        $data = $this->assertSuccessfulJsonResponse($result);
        // Check that < and > are escaped to &lt; and &gt;
        self::assertStringNotContainsString('<', $data['content'], "Unescaped < in: {$description}");
        self::assertStringNotContainsString('>', $data['content'], "Unescaped > in: {$description}");
        // Verify the escaped versions are present
        self::assertStringContainsString('&lt;', $data['content'], "Missing escaped < in: {$description}");
        self::assertStringContainsString('&gt;', $data['content'], "Missing escaped > in: {$description}");
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function xssPayloadProvider(): iterable
    {
        yield 'script_tag' => ['<script>alert("xss")</script>', 'Script tag injection'];
        yield 'img_onerror' => ['<img src=x onerror=alert("xss")>', 'Image onerror handler'];
        yield 'svg_onload' => ['<svg onload=alert("xss")>', 'SVG onload handler'];
        yield 'javascript_href' => ['<a href="javascript:alert(\'xss\')">click</a>', 'JavaScript href'];
        yield 'event_handler' => ['<div onmouseover="alert(\'xss\')">hover</div>', 'Event handler'];
        yield 'mixed_case' => ['<ScRiPt>alert("xss")</sCrIpT>', 'Mixed case script tag'];
    }

    // =========================================================================
    // Error Handling Tests
    // =========================================================================

    #[Test]
    public function completeActionReturns404WhenNoConfigurationAvailable(): void
    {
        // Arrange: No configuration available
        $this->configRepoMock->method('findDefault')->willReturn(null);

        // Act
        $request = $this->createJsonRequest(['prompt' => 'Test prompt']);
        $result  = $this->subject->completeAction($request);

        // Assert
        $data = $this->assertErrorJsonResponse($result, 404);
        self::assertStringContainsString('configuration', strtolower($data['error']));
    }

    #[Test]
    public function completeActionReturns400ForEmptyPrompt(): void
    {
        // Act
        $request = $this->createJsonRequest(['prompt' => '']);
        $result  = $this->subject->completeAction($request);

        // Assert
        $data = $this->assertErrorJsonResponse($result, 400);
        self::assertStringContainsString('prompt', strtolower($data['error']));
    }

    #[Test]
    public function completeActionReturns500ForProviderError(): void
    {
        // Arrange
        $config = $this->createLlmConfiguration();
        $this->configRepoMock->method('findDefault')->willReturn($config);
        $this->llmServiceMock->method('chatWithConfiguration')
            ->willThrowException(new ProviderException('API key invalid'));

        // Act
        $request = $this->createJsonRequest(['prompt' => 'Test prompt']);
        $result  = $this->subject->completeAction($request);

        // Assert
        $data = $this->assertErrorJsonResponse($result, 500);
        // Error should not expose internal details
        self::assertStringNotContainsString('API key', $data['error']);
    }

    // =========================================================================
    // Chat Action Tests
    // =========================================================================

    #[Test]
    public function chatFlowWithMultipleMessages(): void
    {
        // Arrange
        $config = $this->createLlmConfiguration();
        $this->configRepoMock->method('findDefault')->willReturn($config);

        $messages = [
            ['role' => 'user', 'content' => 'Hello!'],
            ['role' => 'assistant', 'content' => 'Hi there!'],
            ['role' => 'user', 'content' => 'How are you?'],
        ];

        $response = $this->createCompletionResponse(
            'I am doing great, thanks for asking!', // Avoid apostrophe for simpler test
            'gpt-4o',
            100,
            20,
        );
        $this->llmServiceMock->method('chatWithConfiguration')->willReturn($response);

        // Act
        $request = $this->createJsonRequest(['messages' => $messages]);
        $result  = $this->subject->chatAction($request);

        // Assert
        self::assertSame(200, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('I am doing great, thanks for asking!', $data['content']);
    }

    #[Test]
    public function chatActionEscapesXssInAllFields(): void
    {
        // Arrange: Setup configuration
        $config = $this->createLlmConfiguration();
        $this->configRepoMock->method('findDefault')->willReturn($config);

        // Arrange: Response with XSS in all fields
        $response = new CompletionResponse(
            content: '<script>alert(1)</script>',
            model: '<img onerror=alert(2)>',
            usage: UsageStatistics::fromTokens(10, 20),
            finishReason: '<svg onload=alert(3)>',
            provider: 'test',
        );
        $this->llmServiceMock->method('chatWithConfiguration')->willReturn($response);

        // Act
        $request = $this->createJsonRequest([
            'messages' => [['role' => 'user', 'content' => 'Test']],
        ]);
        $result = $this->subject->chatAction($request);

        // Assert: All fields are escaped (< and > become &lt; and &gt;)
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertStringNotContainsString('<', $data['content']);
        self::assertStringNotContainsString('<', $data['model']);
        self::assertStringNotContainsString('<', $data['finishReason']);
    }

    // =========================================================================
    // Configuration List Tests
    // =========================================================================

    #[Test]
    public function getConfigurationsReturnsAllActiveConfigs(): void
    {
        // Arrange
        $config1 = $this->createLlmConfiguration('openai-default', 'OpenAI Default', true);
        $config2 = $this->createLlmConfiguration('claude-fast', 'Claude Fast', false);
        $config3 = $this->createLlmConfiguration('gemini-pro', 'Gemini Pro', false);

        // Use QueryResultInterface mock
        $queryResult = $this->createQueryResultMock([$config1, $config2, $config3]);
        $this->configRepoMock->method('findActive')->willReturn($queryResult);

        // Act
        $request = $this->createJsonRequest([]);
        $result  = $this->subject->getConfigurationsAction($request);

        // Assert
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertCount(3, $data['configurations']);

        // Verify structure
        self::assertSame('openai-default', $data['configurations'][0]['identifier']);
        self::assertSame('OpenAI Default', $data['configurations'][0]['name']);
        self::assertTrue($data['configurations'][0]['isDefault']);

        self::assertSame('claude-fast', $data['configurations'][1]['identifier']);
        self::assertFalse($data['configurations'][1]['isDefault']);
    }

    // =========================================================================
    // Usage Statistics Tests
    // =========================================================================

    #[Test]
    public function completeActionIncludesCorrectUsageStatistics(): void
    {
        // Arrange
        $config = $this->createLlmConfiguration();
        $this->configRepoMock->method('findDefault')->willReturn($config);

        $response = $this->createCompletionResponse(
            'Improved text',
            'gpt-4o',
            promptTokens: 75,
            completionTokens: 150,
        );
        $this->llmServiceMock->method('chatWithConfiguration')->willReturn($response);

        // Act
        $request = $this->createJsonRequest(['prompt' => 'Test prompt']);
        $result  = $this->subject->completeAction($request);

        // Assert
        $data = $this->assertSuccessfulJsonResponse($result);
        self::assertArrayHasKey('usage', $data);
        self::assertSame(75, $data['usage']['promptTokens']);
        self::assertSame(150, $data['usage']['completionTokens']);
        self::assertSame(225, $data['usage']['totalTokens']);
    }

    // =========================================================================
    // Form Data Fallback Tests
    // =========================================================================

    #[Test]
    public function completeActionAcceptsFormData(): void
    {
        // Arrange
        $config = $this->createLlmConfiguration();
        $this->configRepoMock->method('findDefault')->willReturn($config);

        $response = $this->createCompletionResponse('Form response');
        $this->llmServiceMock->method('chatWithConfiguration')->willReturn($response);

        // Act: Use form data instead of JSON (no configuration = uses default)
        $request = $this->createFormRequest([
            'prompt' => 'Improve this via form',
        ]);
        $result = $this->subject->completeAction($request);

        // Assert
        $data = $this->assertSuccessfulJsonResponse($result);
        self::assertSame('Form response', $data['content']);
    }
}
