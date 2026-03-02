<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\T3Cowriter\Controller\AjaxController;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Tests\Support\TestQueryResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

#[CoversClass(AjaxController::class)]
#[AllowMockObjectsWithoutExpectations]
final class AjaxControllerTest extends TestCase
{
    private AjaxController $subject;
    private LlmServiceManagerInterface&MockObject $llmServiceManagerMock;
    private LlmConfigurationRepository&MockObject $configRepositoryMock;
    private TaskRepository&MockObject $taskRepositoryMock;
    private RateLimiterInterface&MockObject $rateLimiterMock;
    private Context&MockObject $contextMock;
    private LoggerInterface&MockObject $loggerMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->llmServiceManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $this->configRepositoryMock  = $this->createMock(LlmConfigurationRepository::class);
        $this->taskRepositoryMock    = $this->createMock(TaskRepository::class);
        $this->rateLimiterMock       = $this->createMock(RateLimiterInterface::class);
        $this->contextMock           = $this->createMock(Context::class);
        $this->loggerMock            = $this->createMock(LoggerInterface::class);

        // Default: rate limit allows request
        $this->rateLimiterMock
            ->method('checkLimit')
            ->willReturn(new RateLimitResult(
                allowed: true,
                limit: 20,
                remaining: 19,
                resetTime: time() + 60,
            ));

        $this->contextMock
            ->method('getPropertyFromAspect')
            ->willReturn(1);

        $this->subject = new AjaxController(
            $this->llmServiceManagerMock,
            $this->configRepositoryMock,
            $this->taskRepositoryMock,
            $this->rateLimiterMock,
            $this->contextMock,
            $this->loggerMock,
        );
    }

    // ===========================================
    // Chat Action Tests
    // ===========================================

    #[Test]
    public function chatActionReturnsJsonResponse(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Hello'],
        ];
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $completionResponse = $this->createCompletionResponse('Hi there!');

        $this->llmServiceManagerMock
            ->expects($this->once())
            ->method('chatWithConfiguration')
            ->with($messages, $config)
            ->willReturn($completionResponse);

        $request  = $this->createRequestWithJsonBody(['messages' => $messages]);
        $response = $this->subject->chatAction($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        $this->assertTrue($data['success']);
        $this->assertSame('Hi there!', $data['content']);
        // Verify model and finishReason are present (kills Coalesce/ArrayItemRemoval mutants)
        $this->assertArrayHasKey('model', $data);
        $this->assertSame('test-model', $data['model']);
        $this->assertArrayHasKey('finishReason', $data);
        $this->assertSame('stop', $data['finishReason']);
    }

    #[Test]
    public function chatActionReturnsErrorForInvalidJson(): void
    {
        $bodyMock = $this->createMock(StreamInterface::class);
        $bodyMock->method('getContents')->willReturn('invalid json');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($bodyMock);

        $response = $this->subject->chatAction($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(400, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid JSON', $data['error']);
    }

    #[Test]
    public function chatActionReturnsErrorForEmptyMessages(): void
    {
        $request  = $this->createRequestWithJsonBody(['messages' => []]);
        $response = $this->subject->chatAction($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(400, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    #[Test]
    public function chatActionRejectsInvalidMessageStructure(): void
    {
        $request  = $this->createRequestWithJsonBody(['messages' => [['invalid' => 'structure']]]);
        $response = $this->subject->chatAction($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Invalid messages', $data['error']);
    }

    #[Test]
    public function chatActionRejectsDisallowedRole(): void
    {
        $request  = $this->createRequestWithJsonBody(['messages' => [['role' => 'system', 'content' => 'Hack']]]);
        $response = $this->subject->chatAction($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Invalid messages', $data['error']);
    }

    #[Test]
    public function chatActionRejectsNonStringContent(): void
    {
        $request  = $this->createRequestWithJsonBody(['messages' => [['role' => 'user', 'content' => 42]]]);
        $response = $this->subject->chatAction($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function chatActionRejectsTooManyMessages(): void
    {
        $messages = array_fill(0, 51, ['role' => 'user', 'content' => 'Hello']);
        $request  = $this->createRequestWithJsonBody(['messages' => $messages]);
        $response = $this->subject->chatAction($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function chatActionUsesConfigurationFromRequest(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $config   = $this->createConfigurationMock('my-config');

        $this->configRepositoryMock
            ->expects($this->once())
            ->method('findOneByIdentifier')
            ->with('my-config')
            ->willReturn($config);

        $completionResponse = $this->createCompletionResponse('Response');

        $this->llmServiceManagerMock
            ->expects($this->once())
            ->method('chatWithConfiguration')
            ->with($messages, $config)
            ->willReturn($completionResponse);

        $request  = $this->createRequestWithJsonBody(['messages' => $messages, 'configuration' => 'my-config']);
        $response = $this->subject->chatAction($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function chatActionReturns404WhenNoConfigurationAvailable(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $this->configRepositoryMock->method('findDefault')->willReturn(null);

        $request  = $this->createRequestWithJsonBody(['messages' => $messages]);
        $response = $this->subject->chatAction($request);

        $this->assertSame(404, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('configuration', strtolower($data['error']));
    }

    #[Test]
    public function chatActionHandlesProviderException(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $config   = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $this->llmServiceManagerMock
            ->method('chatWithConfiguration')
            ->willThrowException(new ProviderException('API key invalid'));

        $this->loggerMock
            ->expects($this->once())
            ->method('error')
            ->with(
                'Chat provider error',
                $this->callback(static fn (array $context): bool => isset($context['exception']) && $context['exception'] === 'API key invalid'),
            );

        $request  = $this->createRequestWithJsonBody(['messages' => $messages]);
        $response = $this->subject->chatAction($request);

        $this->assertSame(500, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('provider', strtolower($data['error']));
        $this->assertStringContainsString('try again', strtolower($data['error']));
    }

    #[Test]
    public function chatActionHandlesUnexpectedException(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $config   = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $this->llmServiceManagerMock
            ->method('chatWithConfiguration')
            ->willThrowException(new RuntimeException('Unexpected error'));

        $this->loggerMock
            ->expects($this->once())
            ->method('error')
            ->with(
                'Chat action error',
                $this->callback(static fn (array $context): bool => isset($context['exception']) && $context['exception'] === 'Unexpected error'
                    && isset($context['trace']) && is_string($context['trace'])),
            );

        $request  = $this->createRequestWithJsonBody(['messages' => $messages]);
        $response = $this->subject->chatAction($request);

        $this->assertSame(500, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('unexpected', strtolower($data['error']));
        $this->assertStringEndsWith('.', $data['error']);
    }

    #[Test]
    public function chatActionReturnsRawContentWithoutEscaping(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $config   = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $completionResponse = new CompletionResponse(
            content: '<p>Hello <strong>world</strong></p>',
            model: "model's-name",
            usage: new UsageStatistics(10, 20, 30),
            finishReason: "it's done",
            provider: 'test',
        );

        $this->llmServiceManagerMock
            ->method('chatWithConfiguration')
            ->willReturn($completionResponse);

        $request  = $this->createRequestWithJsonBody(['messages' => $messages]);
        $response = $this->subject->chatAction($request);

        $data = $this->decodeJsonResponse($response);
        $this->assertTrue($data['success']);
        $this->assertSame('<p>Hello <strong>world</strong></p>', $data['content']);
        $this->assertSame("model's-name", $data['model']);
        $this->assertSame("it's done", $data['finishReason']);
    }

    // ===========================================
    // Complete Action Tests
    // ===========================================

    #[Test]
    public function completeActionReturnsSuccessForValidPrompt(): void
    {
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock
            ->method('findDefault')
            ->willReturn($config);

        $completionResponse = $this->createCompletionResponse('Improved text');
        $this->llmServiceManagerMock
            ->method('chatWithConfiguration')
            ->willReturn($completionResponse);

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Improve this']);
        $response = $this->subject->completeAction($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $data = $this->decodeJsonResponse($response);
        $this->assertTrue($data['success']);
        $this->assertSame('Improved text', $data['content']);
        $this->assertSame('stop', $data['finishReason']);
    }

    #[Test]
    public function completeActionReturnsErrorWhenNoPromptProvided(): void
    {
        $request  = $this->createRequestWithJsonBody(['prompt' => '']);
        $response = $this->subject->completeAction($request);

        $data = $this->decodeJsonResponse($response);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('prompt', strtolower($data['error']));
    }

    #[Test]
    public function completeActionRejectsTooLongPrompt(): void
    {
        // 32769 characters exceeds MAX_PROMPT_LENGTH of 32768
        $longPrompt = str_repeat('a', 32769);
        $request    = $this->createRequestWithJsonBody(['prompt' => $longPrompt]);
        $response   = $this->subject->completeAction($request);

        $data = $this->decodeJsonResponse($response);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('maximum allowed length', strtolower($data['error']));
    }

    #[Test]
    public function completeActionReportsNoPromptForWhitespaceOnlyInput(): void
    {
        // Kills UnwrapTrim mutant: trim($dto->prompt) === '' must use trim,
        // otherwise whitespace-only would not match '' and fall to 'exceeds' message
        $request  = $this->createRequestWithJsonBody(['prompt' => '   ']);
        $response = $this->subject->completeAction($request);

        $data = $this->decodeJsonResponse($response);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($data['success']);
        $this->assertSame('No prompt provided', $data['error']);
    }

    #[Test]
    public function completeActionReturnsErrorWhenNoConfigurationAvailable(): void
    {
        $this->configRepositoryMock
            ->method('findDefault')
            ->willReturn(null);

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test']);
        $response = $this->subject->completeAction($request);

        $data = $this->decodeJsonResponse($response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('configuration', strtolower($data['error']));
    }

    #[Test]
    public function completeActionReturnsRawHtmlContent(): void
    {
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock
            ->method('findDefault')
            ->willReturn($config);

        $completionResponse = $this->createCompletionResponse('<p>Improved <strong>text</strong></p>');
        $this->llmServiceManagerMock
            ->method('chatWithConfiguration')
            ->willReturn($completionResponse);

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test']);
        $response = $this->subject->completeAction($request);

        $data = $this->decodeJsonResponse($response);
        $this->assertSame('<p>Improved <strong>text</strong></p>', $data['content']);
    }

    #[Test]
    public function completeActionHandlesProviderException(): void
    {
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock
            ->method('findDefault')
            ->willReturn($config);

        $this->llmServiceManagerMock
            ->method('chatWithConfiguration')
            ->willThrowException(new ProviderException('API key invalid'));

        $this->loggerMock
            ->expects($this->once())
            ->method('error')
            ->with(
                'Cowriter provider error',
                $this->callback(static fn (array $context): bool => isset($context['exception']) && $context['exception'] === 'API key invalid'),
            );

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test']);
        $response = $this->subject->completeAction($request);

        $data = $this->decodeJsonResponse($response);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('provider', strtolower($data['error']));
    }

    #[Test]
    public function completeActionHandlesUnexpectedException(): void
    {
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock
            ->method('findDefault')
            ->willReturn($config);

        $this->llmServiceManagerMock
            ->method('chatWithConfiguration')
            ->willThrowException(new RuntimeException('Unexpected error'));

        $this->loggerMock
            ->expects($this->once())
            ->method('error')
            ->with(
                'Cowriter unexpected error',
                $this->callback(static fn (array $context): bool => isset($context['exception']) && $context['exception'] === 'Unexpected error'
                    && isset($context['trace']) && is_string($context['trace'])),
            );

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test']);
        $response = $this->subject->completeAction($request);

        $data = $this->decodeJsonResponse($response);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
        // Error message should not expose details
        $this->assertStringContainsString('unexpected', strtolower($data['error']));
    }

    #[Test]
    public function completeActionUsesConfigurationFromIdentifier(): void
    {
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock
            ->expects($this->once())
            ->method('findOneByIdentifier')
            ->with('my-config')
            ->willReturn($config);

        $completionResponse = $this->createCompletionResponse('Result');
        $this->llmServiceManagerMock
            ->method('chatWithConfiguration')
            ->willReturn($completionResponse);

        $request = $this->createRequestWithJsonBody([
            'prompt'        => 'Test',
            'configuration' => 'my-config',
        ]);
        $this->subject->completeAction($request);
    }

    #[Test]
    public function completeActionReturns404WhenConfigurationIdentifierNotFound(): void
    {
        $this->configRepositoryMock
            ->method('findOneByIdentifier')
            ->with('non-existent')
            ->willReturn(null);

        $request = $this->createRequestWithJsonBody([
            'prompt'        => 'Test',
            'configuration' => 'non-existent',
        ]);
        $response = $this->subject->completeAction($request);

        $data = $this->decodeJsonResponse($response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('configuration', strtolower($data['error']));
    }

    #[Test]
    public function completeActionSendsCorrectMessageStructure(): void
    {
        // Kills ArrayItemRemoval mutants that remove 'role' keys from messages
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock
            ->method('findDefault')
            ->willReturn($config);

        $completionResponse = $this->createCompletionResponse('Result');

        $this->llmServiceManagerMock
            ->expects($this->once())
            ->method('chatWithConfiguration')
            ->with(
                $this->callback(static function (array $messages): bool {
                    // Verify system message has both role and content
                    return count($messages) === 2
                        && $messages[0]['role'] === 'system'
                        && isset($messages[0]['content'])
                        && $messages[1]['role'] === 'user'
                        && $messages[1]['content'] === 'Test prompt';
                }),
                $this->anything(),
            )
            ->willReturn($completionResponse);

        $request = $this->createRequestWithJsonBody(['prompt' => 'Test prompt']);
        $this->subject->completeAction($request);
    }

    #[Test]
    public function completeActionIncludesUsageStatistics(): void
    {
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock
            ->method('findDefault')
            ->willReturn($config);

        $completionResponse = $this->createCompletionResponse('Result', 100, 200);
        $this->llmServiceManagerMock
            ->method('chatWithConfiguration')
            ->willReturn($completionResponse);

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test']);
        $response = $this->subject->completeAction($request);

        $data = $this->decodeJsonResponse($response);
        $this->assertTrue($data['success']);
        $this->assertSame(100, $data['usage']['promptTokens']);
        $this->assertSame(200, $data['usage']['completionTokens']);
        $this->assertSame(300, $data['usage']['totalTokens']);
    }

    #[Test]
    #[DataProvider('invalidPromptProvider')]
    public function completeActionRejectsInvalidPrompts(mixed $prompt): void
    {
        $body     = is_string($prompt) || is_null($prompt) ? ['prompt' => $prompt] : $prompt;
        $request  = $this->createRequestWithJsonBody($body);
        $response = $this->subject->completeAction($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidPromptProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'whitespace only' => ['   '];
        yield 'null' => [null];
        yield 'missing prompt key' => [[]];
    }

    // ===========================================
    // Get Configurations Action Tests
    // ===========================================

    #[Test]
    public function getConfigurationsActionReturnsActiveConfigurations(): void
    {
        $config1 = $this->createConfigurationMock('config-1', 'Config 1', true);
        $config2 = $this->createConfigurationMock('config-2', 'Config 2', false);

        $queryResult = $this->createQueryResultMock([$config1, $config2]);

        $this->configRepositoryMock
            ->method('findActive')
            ->willReturn($queryResult);

        $request  = $this->createRequestWithJsonBody([]);
        $response = $this->subject->getConfigurationsAction($request);

        $data = $this->decodeJsonResponse($response);
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['configurations']);
        $this->assertSame('config-1', $data['configurations'][0]['identifier']);
        $this->assertTrue($data['configurations'][0]['isDefault']);
        $this->assertSame('config-2', $data['configurations'][1]['identifier']);
        $this->assertFalse($data['configurations'][1]['isDefault']);
    }

    #[Test]
    public function getConfigurationsActionReturnsEmptyListWhenNoConfigurations(): void
    {
        $queryResult = $this->createQueryResultMock([]);

        $this->configRepositoryMock
            ->method('findActive')
            ->willReturn($queryResult);

        $request  = $this->createRequestWithJsonBody([]);
        $response = $this->subject->getConfigurationsAction($request);

        $data = $this->decodeJsonResponse($response);
        $this->assertTrue($data['success']);
        $this->assertSame([], $data['configurations']);
    }

    #[Test]
    public function getConfigurationsActionFiltersNonLlmConfigurationObjects(): void
    {
        $validConfig = $this->createConfigurationMock('valid-config', 'Valid Config', true);

        // Create a mixed result that includes non-LlmConfiguration objects
        $queryResult = $this->createQueryResultMockWithMixedTypes([
            $validConfig,
            new stdClass(),  // Should be filtered out
            'not an object',  // Should be filtered out
        ]);

        $this->configRepositoryMock
            ->method('findActive')
            ->willReturn($queryResult);

        $request  = $this->createRequestWithJsonBody([]);
        $response = $this->subject->getConfigurationsAction($request);

        $data = $this->decodeJsonResponse($response);
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['configurations']);
        $this->assertSame('valid-config', $data['configurations'][0]['identifier']);
    }

    // ===========================================
    // Stream Action Tests
    // ===========================================

    #[Test]
    public function streamActionRejectsEmptyPrompt(): void
    {
        $request  = $this->createRequestWithJsonBody(['prompt' => '']);
        $response = $this->subject->streamAction($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        $response->getBody()->rewind();
        $body = $response->getBody()->getContents();
        $this->assertStringContainsString('No prompt provided', $body);
    }

    #[Test]
    public function streamActionRejectsTooLongPrompt(): void
    {
        // 32769 characters exceeds MAX_PROMPT_LENGTH of 32768
        $longPrompt = str_repeat('a', 32769);
        $request    = $this->createRequestWithJsonBody(['prompt' => $longPrompt]);
        $response   = $this->subject->streamAction($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        $response->getBody()->rewind();
        $body = $response->getBody()->getContents();
        $this->assertStringContainsString('Prompt exceeds maximum allowed length', $body);
    }

    #[Test]
    public function streamActionReportsNoPromptForWhitespaceOnlyInput(): void
    {
        // Kills UnwrapTrim mutant on line 291 for streamAction
        $request  = $this->createRequestWithJsonBody(['prompt' => '   ']);
        $response = $this->subject->streamAction($request);

        $this->assertSame(400, $response->getStatusCode());
        $response->getBody()->rewind();
        $body = $response->getBody()->getContents();
        $this->assertStringContainsString('No prompt provided', $body);
    }

    #[Test]
    public function streamActionReturnsRateLimitedResponse(): void
    {
        $this->rateLimiterMock = $this->createMock(RateLimiterInterface::class);
        $this->rateLimiterMock
            ->method('checkLimit')
            ->willReturn(new RateLimitResult(
                allowed: false,
                limit: 20,
                remaining: 0,
                resetTime: time() + 30,
            ));

        $this->subject = new AjaxController(
            $this->llmServiceManagerMock,
            $this->configRepositoryMock,
            $this->taskRepositoryMock,
            $this->rateLimiterMock,
            $this->contextMock,
            $this->loggerMock,
        );

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test']);
        $response = $this->subject->streamAction($request);

        $this->assertSame(429, $response->getStatusCode());
    }

    #[Test]
    public function streamActionReturns404WhenNoConfiguration(): void
    {
        $this->configRepositoryMock->method('findDefault')->willReturn(null);

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test prompt']);
        $response = $this->subject->streamAction($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function streamActionReturnsSseResponseWhenStreamingSupported(): void
    {
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        // Return a generator that yields chunks
        $this->llmServiceManagerMock
            ->method('streamChatWithConfiguration')
            ->willReturnCallback(function () {
                yield 'Hello ';
                yield 'World';
            });

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test']);
        $response = $this->subject->streamAction($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        $this->assertSame('no-cache', $response->getHeaderLine('Cache-Control'));

        // Verify rate limit headers are present
        $this->assertTrue($response->hasHeader('X-RateLimit-Limit'));
    }

    #[Test]
    public function streamActionReturnsRawContentInSseChunks(): void
    {
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $this->llmServiceManagerMock
            ->method('streamChatWithConfiguration')
            ->willReturnCallback(function () {
                yield '<p>Hello</p>';
            });

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test']);
        $response = $this->subject->streamAction($request);

        $response->getBody()->rewind();
        $body = $response->getBody()->getContents();

        // Raw HTML is preserved in SSE data (json_encode escapes / as \/)
        $events = array_filter(explode("\n\n", $body), static fn (string $s): bool => $s !== '');
        $firstEvent = json_decode(substr(reset($events), 6), true);
        $this->assertSame('<p>Hello</p>', $firstEvent['content']);
    }

    #[Test]
    public function streamActionPreservesSpecialCharactersInSseChunks(): void
    {
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $this->llmServiceManagerMock
            ->method('streamChatWithConfiguration')
            ->willReturnCallback(function () {
                yield "It's a test";
            });

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test']);
        $response = $this->subject->streamAction($request);

        $response->getBody()->rewind();
        $body = $response->getBody()->getContents();

        // Single quotes preserved as-is (no HTML encoding)
        $this->assertStringContainsString("It's a test", $body);
    }

    #[Test]
    public function streamActionSseEventsEndWithDoubleNewline(): void
    {
        // Kills ConcatOperandRemoval mutant that removes "\n\n" from done event
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $this->llmServiceManagerMock
            ->method('streamChatWithConfiguration')
            ->willReturnCallback(function () {
                yield 'Hello';
            });

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test']);
        $response = $this->subject->streamAction($request);

        $response->getBody()->rewind();
        $body = $response->getBody()->getContents();

        // Every SSE event must end with "\n\n"
        $this->assertStringEndsWith("\n\n", $body);

        // Each "data: " line must be followed by "\n\n"
        $this->assertSame(substr_count($body, 'data: '), substr_count($body, "\n\n"));
    }

    #[Test]
    public function streamActionSseResponseIsReadableFromStart(): void
    {
        // Kills MethodCallRemoval on $stream->rewind() in streamAction
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $this->llmServiceManagerMock
            ->method('streamChatWithConfiguration')
            ->willReturnCallback(function () {
                yield 'chunk';
            });

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test']);
        $response = $this->subject->streamAction($request);

        // Read body WITHOUT rewinding - if rewind() was removed, body would be empty
        // because the stream pointer would be at the end after writing
        $body = $response->getBody()->getContents();
        $this->assertNotEmpty($body);
        $this->assertStringContainsString('data: ', $body);
    }

    #[Test]
    public function streamActionSseDoneEventIncludesModelName(): void
    {
        // Verify model name from configuration is included in done event
        // and BitwiseOr on the model encoding
        $config = $this->createMock(LlmConfiguration::class);
        $config->method('getIdentifier')->willReturn('default');
        $config->method('getName')->willReturn('Default Config');
        $config->method('isDefault')->willReturn(true);
        $config->method('getModelId')->willReturn("test-model's");
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $this->llmServiceManagerMock
            ->method('streamChatWithConfiguration')
            ->willReturnCallback(function () {
                yield 'Hello';
            });

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test']);
        $response = $this->subject->streamAction($request);

        $response->getBody()->rewind();
        $body = $response->getBody()->getContents();

        // Parse the done event (last SSE event)
        $events    = array_filter(explode("\n\n", $body), static fn (string $s): bool => $s !== '');
        $lastEvent = json_decode(substr(end($events), 6), true);
        $this->assertTrue($lastEvent['done']);
        // Model preserved as-is (no HTML encoding)
        $this->assertSame("test-model's", $lastEvent['model']);
    }

    #[Test]
    public function streamActionHandlesProviderException(): void
    {
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $this->llmServiceManagerMock
            ->method('streamChatWithConfiguration')
            ->willThrowException(new ProviderException('Stream failed'));

        $this->loggerMock
            ->expects($this->once())
            ->method('error')
            ->with(
                'Cowriter streaming provider error',
                $this->callback(static fn (array $context): bool => isset($context['exception']) && $context['exception'] === 'Stream failed'),
            );

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test']);
        $response = $this->subject->streamAction($request);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        $response->getBody()->rewind();
        $body = $response->getBody()->getContents();
        $this->assertStringContainsString('provider error', strtolower($body));
    }

    // ===========================================
    // Security Tests
    // ===========================================

    #[Test]
    public function chatActionReturnsRateLimitHeaders(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $config   = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $completionResponse = $this->createCompletionResponse('Hi');

        $this->llmServiceManagerMock
            ->method('chatWithConfiguration')
            ->willReturn($completionResponse);

        $request  = $this->createRequestWithJsonBody(['messages' => $messages]);
        $response = $this->subject->chatAction($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('X-RateLimit-Limit'));
        $this->assertTrue($response->hasHeader('X-RateLimit-Remaining'));
        $this->assertTrue($response->hasHeader('X-RateLimit-Reset'));
        $this->assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
    }

    #[Test]
    public function chatActionReturns429WhenRateLimited(): void
    {
        $this->rateLimiterMock = $this->createMock(RateLimiterInterface::class);
        $this->rateLimiterMock
            ->method('checkLimit')
            ->willReturn(new RateLimitResult(
                allowed: false,
                limit: 20,
                remaining: 0,
                resetTime: time() + 30,
            ));

        $this->subject = new AjaxController(
            $this->llmServiceManagerMock,
            $this->configRepositoryMock,
            $this->taskRepositoryMock,
            $this->rateLimiterMock,
            $this->contextMock,
            $this->loggerMock,
        );

        $request  = $this->createRequestWithJsonBody(['messages' => [['role' => 'user', 'content' => 'Hello']]]);
        $response = $this->subject->chatAction($request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('Retry-After'));
        // Kills Foreach_ mutant: rate limit headers must be present
        $this->assertTrue($response->hasHeader('X-RateLimit-Limit'));
        $this->assertTrue($response->hasHeader('X-RateLimit-Remaining'));
        $this->assertTrue($response->hasHeader('X-RateLimit-Reset'));
        $this->assertSame('20', $response->getHeaderLine('X-RateLimit-Limit'));
        $this->assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function completeActionReturns429WhenRateLimited(): void
    {
        $this->rateLimiterMock = $this->createMock(RateLimiterInterface::class);
        $this->rateLimiterMock
            ->method('checkLimit')
            ->willReturn(new RateLimitResult(
                allowed: false,
                limit: 20,
                remaining: 0,
                resetTime: time() + 30,
            ));

        $this->subject = new AjaxController(
            $this->llmServiceManagerMock,
            $this->configRepositoryMock,
            $this->taskRepositoryMock,
            $this->rateLimiterMock,
            $this->contextMock,
            $this->loggerMock,
        );

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test']);
        $response = $this->subject->completeAction($request);

        $this->assertSame(429, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('retryAfter', $data);
    }

    #[Test]
    public function chatActionRejectsMessageContentExceedingMaxLength(): void
    {
        // 32769 characters = over the 32768 limit
        $longContent = str_repeat('a', 32769);
        $request     = $this->createRequestWithJsonBody(['messages' => [['role' => 'user', 'content' => $longContent]]]);
        $response    = $this->subject->chatAction($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function chatActionUsesMultiByteStringLengthForContentCheck(): void
    {
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);
        // Kills MBString mutant (mb_strlen → strlen)
        // Multi-byte characters: each emoji is 4 bytes but 1 character
        // 32768 emojis = 32768 chars (within limit) but 131072 bytes (over limit with strlen)
        // Use a string that is EXACTLY at the char limit but well over the byte limit
        $emoji   = '💡'; // 4 bytes in UTF-8
        $content = str_repeat($emoji, 32768); // 32768 chars, 131072 bytes

        $completionResponse = $this->createCompletionResponse('Response');
        $this->llmServiceManagerMock
            ->method('chatWithConfiguration')
            ->willReturn($completionResponse);

        $request  = $this->createRequestWithJsonBody(['messages' => [['role' => 'user', 'content' => $content]]]);
        $response = $this->subject->chatAction($request);

        // With mb_strlen: 32768 chars = at limit, should pass (200)
        // With strlen: 131072 bytes = over limit, would fail (400)
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function chatActionAcceptsMessageContentAtExactMaxLength(): void
    {
        // Exactly 32768 characters = at the limit
        $exactContent = str_repeat('a', 32768);
        $config       = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $completionResponse = $this->createCompletionResponse('Response');

        $this->llmServiceManagerMock
            ->method('chatWithConfiguration')
            ->willReturn($completionResponse);

        $request  = $this->createRequestWithJsonBody(['messages' => [['role' => 'user', 'content' => $exactContent]]]);
        $response = $this->subject->chatAction($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function streamActionHandlesGenericThrowable(): void
    {
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $this->llmServiceManagerMock
            ->method('streamChatWithConfiguration')
            ->willThrowException(new RuntimeException('Unexpected failure'));

        $this->loggerMock
            ->expects($this->once())
            ->method('error')
            ->with(
                'Cowriter streaming unexpected error',
                $this->callback(static fn (array $context): bool => isset($context['exception']) && $context['exception'] === 'Unexpected failure'
                    && isset($context['trace']) && is_string($context['trace'])),
            );

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test']);
        $response = $this->subject->streamAction($request);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        $response->getBody()->rewind();
        $body = $response->getBody()->getContents();
        $this->assertStringContainsString('unexpected', strtolower($body));
    }

    #[Test]
    public function sseErrorResponseFallsBackOnJsonEncodeFailure(): void
    {
        // Use reflection to call the private sseErrorResponse method
        // with a string that causes json_encode to fail
        $method = new ReflectionMethod(AjaxController::class, 'sseErrorResponse');

        // Invalid UTF-8 sequence causes json_encode to fail with JSON_THROW_ON_ERROR
        $invalidUtf8 = "Error: \xB1\x31";

        $response = $method->invoke($this->subject, $invalidUtf8, 500);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('text/event-stream', $response->getHeaderLine('Content-Type'));
        $response->getBody()->rewind();
        $body = $response->getBody()->getContents();
        // Should fall back to the hardcoded error JSON
        self::assertStringContainsString('An error occurred', $body);
        // Must be valid SSE format: "data: JSON\n\n"
        self::assertStringStartsWith('data: ', $body);
        self::assertStringEndsWith("\n\n", $body);
    }

    #[Test]
    public function sseErrorResponseProducesValidSseFormat(): void
    {
        // Kills Concat, ConcatOperandRemoval, and MethodCallRemoval (rewind) mutants
        $method = new ReflectionMethod(AjaxController::class, 'sseErrorResponse');

        $response = $method->invoke($this->subject, 'Test error message', 400);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-cache', $response->getHeaderLine('Cache-Control'));

        // Read body WITHOUT rewinding first - kills MethodCallRemoval on $stream->rewind()
        // If rewind() was removed, the stream pointer would be at end and getContents() returns empty
        $body = $response->getBody()->getContents();
        self::assertNotEmpty($body, 'SSE body must be readable without manual rewind');

        // Verify exact SSE format: "data: {JSON}\n\n"
        self::assertStringStartsWith('data: ', $body);
        self::assertStringEndsWith("\n\n", $body);

        // Extract JSON payload and verify it's valid
        $jsonPart = substr($body, 6, -2); // Remove "data: " prefix and "\n\n" suffix
        $decoded  = json_decode($jsonPart, true);
        self::assertIsArray($decoded);
        self::assertSame('Test error message', $decoded['error']);
    }

    #[Test]
    public function streamActionSendsCorrectMessageStructure(): void
    {
        // Kills ArrayItemRemoval/ArrayItem mutants on streaming message construction
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $this->llmServiceManagerMock
            ->expects($this->once())
            ->method('streamChatWithConfiguration')
            ->with(
                $this->callback(static function (array $messages): bool {
                    // Verify system message has role and content
                    return count($messages) === 2
                        && $messages[0]['role'] === 'system'
                        && isset($messages[0]['content'])
                        && $messages[1]['role'] === 'user'
                        && $messages[1]['content'] === 'Test prompt';
                }),
                $this->anything(),
            )
            ->willReturnCallback(function () {
                yield 'Hello';
            });

        $request = $this->createRequestWithJsonBody(['prompt' => 'Test prompt']);
        $this->subject->streamAction($request);
    }

    #[Test]
    public function getConfigurationsActionReturnsRawNames(): void
    {
        $config = $this->createConfigurationMock(
            'config-with-<special>',
            'Config & Name',
            true,
        );

        $queryResult = $this->createQueryResultMock([$config]);

        $this->configRepositoryMock
            ->method('findActive')
            ->willReturn($queryResult);

        $request  = $this->createRequestWithJsonBody([]);
        $response = $this->subject->getConfigurationsAction($request);

        $data = $this->decodeJsonResponse($response);
        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['configurations']);
        // Raw values preserved — no HTML encoding in JSON responses
        $this->assertSame('config-with-<special>', $data['configurations'][0]['identifier']);
        $this->assertSame('Config & Name', $data['configurations'][0]['name']);
    }

    #[Test]
    public function getConfigurationsActionFiltersContinueCorrectly(): void
    {
        // Tests that 'continue' in the foreach works correctly:
        // With non-LlmConfiguration objects, valid configs AFTER them should still be included
        $config1 = $this->createConfigurationMock('first', 'First', true);
        $config2 = $this->createConfigurationMock('second', 'Second', false);

        // Mixed array with a non-LlmConfiguration in the middle
        $queryResult = $this->createQueryResultMockWithMixedTypes([
            $config1,
            new stdClass(),  // Should be skipped by 'continue'
            $config2,        // Should still be included after the continue
        ]);

        $this->configRepositoryMock
            ->method('findActive')
            ->willReturn($queryResult);

        $request  = $this->createRequestWithJsonBody([]);
        $response = $this->subject->getConfigurationsAction($request);

        $data = $this->decodeJsonResponse($response);
        $this->assertTrue($data['success']);
        // Both valid configs should be present (stdClass filtered by continue)
        $this->assertCount(2, $data['configurations']);
        $this->assertSame('first', $data['configurations'][0]['identifier']);
        $this->assertSame('second', $data['configurations'][1]['identifier']);
    }

    // ===========================================
    // Cycle 31: Edge Case Coverage Tests
    // ===========================================

    #[Test]
    public function chatActionRejectsMessageWithExplicitNullRole(): void
    {
        $request  = $this->createRequestWithJsonBody(['messages' => [['role' => null, 'content' => 'Hello']]]);
        $response = $this->subject->chatAction($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        $this->assertFalse($data['success']);
    }

    #[Test]
    public function chatActionRejectsMessageWithExplicitNullContent(): void
    {
        $request  = $this->createRequestWithJsonBody(['messages' => [['role' => 'user', 'content' => null]]]);
        $response = $this->subject->chatAction($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        $this->assertFalse($data['success']);
    }

    #[Test]
    public function chatActionRejectsNonArrayMessage(): void
    {
        $request  = $this->createRequestWithJsonBody(['messages' => ['just a string']]);
        $response = $this->subject->chatAction($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function chatActionAcceptsExactly50Messages(): void
    {
        $messages = array_fill(0, 50, ['role' => 'user', 'content' => 'Hello']);
        $config   = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $completionResponse = $this->createCompletionResponse('Response');

        $this->llmServiceManagerMock
            ->method('chatWithConfiguration')
            ->willReturn($completionResponse);

        $request  = $this->createRequestWithJsonBody(['messages' => $messages]);
        $response = $this->subject->chatAction($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function streamActionSseOutputContainsDoneEvent(): void
    {
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $this->llmServiceManagerMock
            ->method('streamChatWithConfiguration')
            ->willReturnCallback(function () {
                yield 'chunk1';
                yield 'chunk2';
            });

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test']);
        $response = $this->subject->streamAction($request);

        $response->getBody()->rewind();
        $body = $response->getBody()->getContents();

        // Verify SSE structure: each event starts with "data: " and ends with "\n\n"
        $events = array_filter(explode("\n\n", $body), static fn (string $s): bool => $s !== '');
        $this->assertCount(3, $events); // 2 content chunks + 1 done event

        // Verify content chunks are valid SSE data lines
        foreach ($events as $event) {
            $this->assertStringStartsWith('data: ', $event);
            $json = json_decode(substr($event, 6), true);
            $this->assertIsArray($json);
        }

        // Verify last event has done:true
        $lastEvent = json_decode(substr(end($events), 6), true);
        $this->assertTrue($lastEvent['done']);

        // Verify first two have content
        $firstEvent = json_decode(substr($events[0], 6), true);
        $this->assertArrayHasKey('content', $firstEvent);
        $this->assertSame('chunk1', $firstEvent['content']);
    }

    #[Test]
    public function chatActionHandlesNonArrayBodyGracefully(): void
    {
        $bodyMock = $this->createMock(StreamInterface::class);
        $bodyMock->method('getContents')->willReturn('"just a string"');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($bodyMock);

        $response = $this->subject->chatAction($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Invalid JSON structure', $data['error']);
    }

    #[Test]
    public function chatActionTreatsNonArrayMessagesAsEmpty(): void
    {
        // Kills LogicalAnd mutant (isset && is_array → isset || is_array)
        // If messages is a string instead of array, it should be treated as []
        $request  = $this->createRequestWithJsonBody(['messages' => 'not an array']);
        $response = $this->subject->chatAction($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        $this->assertFalse($data['success']);
        // Should get "Messages array is required" (empty array case)
        $this->assertStringContainsString('Messages array is required', $data['error']);
    }

    // ===========================================
    // Helper Methods
    // ===========================================

    private function createRequestWithJsonBody(array $data): ServerRequestInterface
    {
        $bodyStub = $this->createStub(StreamInterface::class);
        $bodyStub->method('getContents')->willReturn(json_encode($data));

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($bodyStub);
        $request->method('getParsedBody')->willReturn(null);

        return $request;
    }

    private function createCompletionResponse(
        string $content,
        int $promptTokens = 10,
        int $completionTokens = 20,
    ): CompletionResponse {
        $usage = new UsageStatistics(
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $promptTokens + $completionTokens,
        );

        return new CompletionResponse(
            content: $content,
            model: 'test-model',
            usage: $usage,
            finishReason: 'stop',
            provider: 'test-provider',
        );
    }

    // ===========================================
    // getTasksAction Tests
    // ===========================================

    #[Test]
    public function getTasksActionReturnsEmptyListWhenNoTasks(): void
    {
        $this->taskRepositoryMock
            ->method('findByCategory')
            ->with('content')
            ->willReturn(new TestQueryResult([]));

        $response = $this->subject->getTasksAction($this->createMock(ServerRequestInterface::class));

        self::assertInstanceOf(JsonResponse::class, $response);
        $data = $this->decodeJsonResponse($response);
        self::assertTrue($data['success']);
        self::assertSame([], $data['tasks']);
    }

    #[Test]
    public function getTasksActionReturnsActiveTasks(): void
    {
        $task1 = $this->createTaskMock(1, 'improve', 'Improve Text', 'Enhance readability', true);
        $task2 = $this->createTaskMock(2, 'summarize', 'Summarize', 'Create summary', true);

        $this->taskRepositoryMock
            ->method('findByCategory')
            ->with('content')
            ->willReturn(new TestQueryResult([$task1, $task2]));

        $response = $this->subject->getTasksAction($this->createMock(ServerRequestInterface::class));

        $data = $this->decodeJsonResponse($response);
        self::assertTrue($data['success']);
        self::assertCount(2, $data['tasks']);
        self::assertSame(1, $data['tasks'][0]['uid']);
        self::assertSame('improve', $data['tasks'][0]['identifier']);
        self::assertSame('Improve Text', $data['tasks'][0]['name']);
        self::assertSame('Enhance readability', $data['tasks'][0]['description']);
    }

    #[Test]
    public function getTasksActionFiltersInactiveTasks(): void
    {
        $activeTask   = $this->createTaskMock(1, 'improve', 'Improve', 'desc', true);
        $inactiveTask = $this->createTaskMock(2, 'old', 'Old Task', 'desc', false);

        $this->taskRepositoryMock
            ->method('findByCategory')
            ->willReturn(new TestQueryResult([$activeTask, $inactiveTask]));

        $response = $this->subject->getTasksAction($this->createMock(ServerRequestInterface::class));

        $data = $this->decodeJsonResponse($response);
        self::assertCount(1, $data['tasks']);
        self::assertSame('improve', $data['tasks'][0]['identifier']);
    }

    #[Test]
    public function getTasksActionReturnsRawTaskFields(): void
    {
        $task = $this->createTaskMock(1, 'fix<test>', 'Fix Grammar & Spelling', 'Desc with "quotes"', true);

        $this->taskRepositoryMock
            ->method('findByCategory')
            ->willReturn(new TestQueryResult([$task]));

        $response = $this->subject->getTasksAction($this->createMock(ServerRequestInterface::class));

        $data = $this->decodeJsonResponse($response);
        // Raw values preserved — no HTML encoding in JSON responses
        self::assertSame('fix<test>', $data['tasks'][0]['identifier']);
        self::assertSame('Fix Grammar & Spelling', $data['tasks'][0]['name']);
        self::assertSame('Desc with "quotes"', $data['tasks'][0]['description']);
    }

    // ===========================================
    // executeTaskAction Tests
    // ===========================================

    #[Test]
    public function executeTaskActionReturnsErrorForInvalidRequest(): void
    {
        $request = $this->createRequestWithJsonBody(['taskUid' => 0]);

        $response = $this->subject->executeTaskAction($request);

        self::assertSame(400, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        self::assertFalse($data['success']);
        self::assertStringContainsString('Invalid', $data['error']);
    }

    #[Test]
    public function executeTaskActionReturnsErrorForMissingTask(): void
    {
        $this->taskRepositoryMock->method('findByUid')->willReturn(null);

        $request = $this->createRequestWithJsonBody([
            'taskUid'     => 999,
            'context'     => 'some text',
            'contextType' => 'selection',
        ]);

        $response = $this->subject->executeTaskAction($request);

        self::assertSame(404, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        self::assertFalse($data['success']);
        self::assertStringContainsString('not found', $data['error']);
    }

    #[Test]
    public function executeTaskActionReturnsErrorForInactiveTask(): void
    {
        $task = $this->createTaskMock(1, 'old', 'Old', 'desc', false);
        $this->taskRepositoryMock->method('findByUid')->willReturn($task);

        $request = $this->createRequestWithJsonBody([
            'taskUid'     => 1,
            'context'     => 'some text',
            'contextType' => 'selection',
        ]);

        $response = $this->subject->executeTaskAction($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function executeTaskActionSuccessfulExecution(): void
    {
        $task = $this->createTaskMock(1, 'improve', 'Improve', 'desc', true);
        $task->method('buildPrompt')
            ->with(['input' => 'Hello world'])
            ->willReturn('Improve: Hello world');
        $task->method('getConfiguration')->willReturn(null);

        $this->taskRepositoryMock->method('findByUid')->willReturn($task);

        $config = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $completionResponse = $this->createCompletionResponse('Improved: Hello world');
        $this->llmServiceManagerMock
            ->expects($this->once())
            ->method('chatWithConfiguration')
            ->willReturn($completionResponse);

        $request = $this->createRequestWithJsonBody([
            'taskUid'     => 1,
            'context'     => 'Hello world',
            'contextType' => 'selection',
        ]);

        $response = $this->subject->executeTaskAction($request);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        self::assertTrue($data['success']);
        self::assertStringContainsString('Improved', $data['content']);
    }

    #[Test]
    public function executeTaskActionAppendsAdHocRules(): void
    {
        $task = $this->createTaskMock(1, 'improve', 'Improve', 'desc', true);
        $task->method('buildPrompt')->willReturn('Improve: text');
        $task->method('getConfiguration')->willReturn(null);

        $this->taskRepositoryMock->method('findByUid')->willReturn($task);

        $config = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $completionResponse = $this->createCompletionResponse('Result');

        $this->llmServiceManagerMock
            ->expects($this->once())
            ->method('chatWithConfiguration')
            ->with(
                $this->callback(static function (array $messages): bool {
                    return count($messages) === 2
                        && $messages[1]['content'] === 'Additional instructions: Be formal';
                }),
                $config,
            )
            ->willReturn($completionResponse);

        $request = $this->createRequestWithJsonBody([
            'taskUid'     => 1,
            'context'     => 'text',
            'contextType' => 'selection',
            'adHocRules'  => 'Be formal',
        ]);

        $this->subject->executeTaskAction($request);
    }

    #[Test]
    public function executeTaskActionInjectsEditorCapabilitiesIntoPrompt(): void
    {
        $task = $this->createTaskMock(1, 'improve', 'Improve', 'desc', true);
        $task->method('buildPrompt')->willReturn('Improve: text');
        $task->method('getConfiguration')->willReturn(null);

        $this->taskRepositoryMock->method('findByUid')->willReturn($task);

        $config = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $completionResponse = $this->createCompletionResponse('Result');

        $this->llmServiceManagerMock
            ->expects($this->once())
            ->method('chatWithConfiguration')
            ->with(
                $this->callback(static function (array $messages): bool {
                    return count($messages) === 2
                        && $messages[0]['role'] === 'system'
                        && str_contains($messages[0]['content'], 'bold')
                        && str_contains($messages[0]['content'], 'tables');
                }),
                $config,
            )
            ->willReturn($completionResponse);

        $request = $this->createRequestWithJsonBody([
            'taskUid'            => 1,
            'context'            => 'text',
            'contextType'        => 'selection',
            'editorCapabilities' => 'bold, tables, lists',
        ]);

        $this->subject->executeTaskAction($request);
    }

    #[Test]
    public function executeTaskActionSkipsCapabilitiesWhenEmpty(): void
    {
        $task = $this->createTaskMock(1, 'improve', 'Improve', 'desc', true);
        $task->method('buildPrompt')->willReturn('Improve: text');
        $task->method('getConfiguration')->willReturn(null);

        $this->taskRepositoryMock->method('findByUid')->willReturn($task);

        $config = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $completionResponse = $this->createCompletionResponse('Result');

        $this->llmServiceManagerMock
            ->expects($this->once())
            ->method('chatWithConfiguration')
            ->with(
                $this->callback(static function (array $messages): bool {
                    // Only the user message, no system message for capabilities
                    return count($messages) === 1
                        && $messages[0]['role'] === 'user';
                }),
                $config,
            )
            ->willReturn($completionResponse);

        $request = $this->createRequestWithJsonBody([
            'taskUid'     => 1,
            'context'     => 'text',
            'contextType' => 'selection',
        ]);

        $this->subject->executeTaskAction($request);
    }

    #[Test]
    public function executeTaskActionUsesTaskConfiguration(): void
    {
        $taskConfig = $this->createConfigurationMock('task-config', 'Task Config', false);

        $task = $this->createTaskMock(1, 'improve', 'Improve', 'desc', true);
        $task->method('buildPrompt')->willReturn('Prompt');
        $task->method('getConfiguration')->willReturn($taskConfig);

        $this->taskRepositoryMock->method('findByUid')->willReturn($task);

        $completionResponse = $this->createCompletionResponse('Result');
        $this->llmServiceManagerMock
            ->expects($this->once())
            ->method('chatWithConfiguration')
            ->with($this->anything(), $taskConfig)
            ->willReturn($completionResponse);

        $request = $this->createRequestWithJsonBody([
            'taskUid'     => 1,
            'context'     => 'text',
            'contextType' => 'selection',
        ]);

        $this->subject->executeTaskAction($request);
    }

    #[Test]
    public function executeTaskActionReturnsErrorOnProviderException(): void
    {
        $task = $this->createTaskMock(1, 'improve', 'Improve', 'desc', true);
        $task->method('buildPrompt')->willReturn('Prompt');
        $task->method('getConfiguration')->willReturn(null);

        $this->taskRepositoryMock->method('findByUid')->willReturn($task);
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $this->llmServiceManagerMock
            ->method('chatWithConfiguration')
            ->willThrowException(new ProviderException('API error'));

        $request = $this->createRequestWithJsonBody([
            'taskUid'     => 1,
            'context'     => 'text',
            'contextType' => 'selection',
        ]);

        $response = $this->subject->executeTaskAction($request);

        self::assertSame(500, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        self::assertFalse($data['success']);
        self::assertStringContainsString('provider error', $data['error']);
    }

    #[Test]
    public function executeTaskActionReturnsErrorOnUnexpectedException(): void
    {
        $task = $this->createTaskMock(1, 'improve', 'Improve', 'desc', true);
        $task->method('buildPrompt')->willReturn('Prompt');
        $task->method('getConfiguration')->willReturn(null);

        $this->taskRepositoryMock->method('findByUid')->willReturn($task);
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock->method('findDefault')->willReturn($config);

        $this->llmServiceManagerMock
            ->method('chatWithConfiguration')
            ->willThrowException(new RuntimeException('Unexpected'));

        $request = $this->createRequestWithJsonBody([
            'taskUid'     => 1,
            'context'     => 'text',
            'contextType' => 'selection',
        ]);

        $response = $this->subject->executeTaskAction($request);

        self::assertSame(500, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        self::assertFalse($data['success']);
        self::assertStringContainsString('unexpected error', $data['error']);
    }

    #[Test]
    public function executeTaskActionRateLimited(): void
    {
        // Override rate limiter to deny
        $this->rateLimiterMock = $this->createMock(RateLimiterInterface::class);
        $this->rateLimiterMock
            ->method('checkLimit')
            ->willReturn(new RateLimitResult(
                allowed: false,
                limit: 20,
                remaining: 0,
                resetTime: time() + 60,
            ));

        $this->subject = new AjaxController(
            $this->llmServiceManagerMock,
            $this->configRepositoryMock,
            $this->taskRepositoryMock,
            $this->rateLimiterMock,
            $this->contextMock,
            $this->loggerMock,
        );

        $request = $this->createRequestWithJsonBody([
            'taskUid'     => 1,
            'context'     => 'text',
            'contextType' => 'selection',
        ]);

        $response = $this->subject->executeTaskAction($request);

        self::assertSame(429, $response->getStatusCode());
    }

    #[Test]
    public function executeTaskActionReturnsErrorWhenNoConfiguration(): void
    {
        $task = $this->createTaskMock(1, 'improve', 'Improve', 'desc', true);
        $task->method('buildPrompt')->willReturn('Prompt');
        $task->method('getConfiguration')->willReturn(null);

        $this->taskRepositoryMock->method('findByUid')->willReturn($task);
        $this->configRepositoryMock->method('findDefault')->willReturn(null);

        $request = $this->createRequestWithJsonBody([
            'taskUid'     => 1,
            'context'     => 'text',
            'contextType' => 'selection',
        ]);

        $response = $this->subject->executeTaskAction($request);

        self::assertSame(404, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        self::assertFalse($data['success']);
        self::assertStringContainsString('configuration', $data['error']);
    }

    // ===========================================
    // Helper Methods
    // ===========================================

    private function createTaskMock(
        int $uid,
        string $identifier,
        string $name,
        string $description,
        bool $isActive,
    ): Task&MockObject {
        $mock = $this->createMock(Task::class);
        $mock->method('getUid')->willReturn($uid);
        $mock->method('getIdentifier')->willReturn($identifier);
        $mock->method('getName')->willReturn($name);
        $mock->method('getDescription')->willReturn($description);
        $mock->method('isActive')->willReturn($isActive);

        return $mock;
    }

    private function createConfigurationMock(
        string $identifier = 'default',
        string $name = 'Default Config',
        bool $isDefault = true,
    ): LlmConfiguration&MockObject {
        $mock = $this->createMock(LlmConfiguration::class);
        $mock->method('getIdentifier')->willReturn($identifier);
        $mock->method('getName')->willReturn($name);
        $mock->method('isDefault')->willReturn($isDefault);
        $mock->method('getModelId')->willReturn('');

        return $mock;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(JsonResponse $response): array
    {
        $response->getBody()->rewind();
        $data = json_decode($response->getBody()->getContents(), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Create a query result stub that properly iterates over items.
     *
     * @param array<LlmConfiguration&MockObject> $items
     *
     * @return QueryResultInterface<LlmConfiguration>
     */
    private function createQueryResultMock(array $items): QueryResultInterface
    {
        return new TestQueryResult($items);
    }

    /**
     * Create a query result stub with mixed types for testing filtering.
     *
     * @param array<mixed> $items
     *
     * @return QueryResultInterface<object>
     */
    private function createQueryResultMockWithMixedTypes(array $items): QueryResultInterface
    {
        // Filter to objects only for type safety
        $objects = array_filter($items, static fn (mixed $item): bool => is_object($item));

        return new TestQueryResult(array_values($objects));
    }
}
