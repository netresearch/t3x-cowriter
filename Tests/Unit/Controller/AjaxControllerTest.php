<?php

/*
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
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
    private RateLimiterInterface&MockObject $rateLimiterMock;
    private Context&MockObject $contextMock;
    private LoggerInterface&MockObject $loggerMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->llmServiceManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $this->configRepositoryMock  = $this->createMock(LlmConfigurationRepository::class);
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
        $completionResponse = $this->createCompletionResponse('Hi there!');

        $this->llmServiceManagerMock
            ->expects($this->once())
            ->method('chat')
            ->with($messages, null)
            ->willReturn($completionResponse);

        $request  = $this->createRequestWithJsonBody(['messages' => $messages]);
        $response = $this->subject->chatAction($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
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
    }

    #[Test]
    public function chatActionReturnsErrorForEmptyMessages(): void
    {
        $request  = $this->createRequestWithJsonBody(['messages' => []]);
        $response = $this->subject->chatAction($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function chatActionPassesOptionsToService(): void
    {
        $messages           = [['role' => 'user', 'content' => 'Hello']];
        $options            = ['temperature' => 0.7, 'maxTokens' => 1000];
        $completionResponse = $this->createCompletionResponse('Response');

        $this->llmServiceManagerMock
            ->expects($this->once())
            ->method('chat')
            ->with($messages, $this->isInstanceOf(ChatOptions::class))
            ->willReturn($completionResponse);

        $request  = $this->createRequestWithJsonBody(['messages' => $messages, 'options' => $options]);
        $response = $this->subject->chatAction($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function chatActionClampsTemperatureToValidRange(): void
    {
        $messages           = [['role' => 'user', 'content' => 'Hello']];
        $options            = ['temperature' => 5.0]; // Out of range (max is 2.0)
        $completionResponse = $this->createCompletionResponse('Response');

        $capturedOptions = null;
        $this->llmServiceManagerMock
            ->expects($this->once())
            ->method('chat')
            ->with(
                $messages,
                $this->callback(function (?ChatOptions $options) use (&$capturedOptions): bool {
                    $capturedOptions = $options;

                    return $options !== null;
                }),
            )
            ->willReturn($completionResponse);

        $request = $this->createRequestWithJsonBody(['messages' => $messages, 'options' => $options]);
        $this->subject->chatAction($request);

        $this->assertNotNull($capturedOptions);
        $this->assertSame(2.0, $capturedOptions->getTemperature());
    }

    #[Test]
    public function chatActionClampsTopPToValidRange(): void
    {
        $messages           = [['role' => 'user', 'content' => 'Hello']];
        $options            = ['topP' => -0.5]; // Out of range (min is 0.0)
        $completionResponse = $this->createCompletionResponse('Response');

        $capturedOptions = null;
        $this->llmServiceManagerMock
            ->expects($this->once())
            ->method('chat')
            ->with(
                $messages,
                $this->callback(function (?ChatOptions $options) use (&$capturedOptions): bool {
                    $capturedOptions = $options;

                    return $options !== null;
                }),
            )
            ->willReturn($completionResponse);

        $request = $this->createRequestWithJsonBody(['messages' => $messages, 'options' => $options]);
        $this->subject->chatAction($request);

        $this->assertNotNull($capturedOptions);
        $this->assertSame(0.0, $capturedOptions->getTopP());
    }

    #[Test]
    public function chatActionEnforcesMinimumMaxTokens(): void
    {
        $messages           = [['role' => 'user', 'content' => 'Hello']];
        $options            = ['maxTokens' => -100]; // Invalid (must be >= 1)
        $completionResponse = $this->createCompletionResponse('Response');

        $capturedOptions = null;
        $this->llmServiceManagerMock
            ->expects($this->once())
            ->method('chat')
            ->with(
                $messages,
                $this->callback(function (?ChatOptions $options) use (&$capturedOptions): bool {
                    $capturedOptions = $options;

                    return $options !== null;
                }),
            )
            ->willReturn($completionResponse);

        $request = $this->createRequestWithJsonBody(['messages' => $messages, 'options' => $options]);
        $this->subject->chatAction($request);

        $this->assertNotNull($capturedOptions);
        $this->assertSame(1, $capturedOptions->getMaxTokens());
    }

    #[Test]
    public function chatActionClampsPenaltiesToValidRange(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $options  = [
            'frequencyPenalty' => 5.0,  // Out of range (max is 2.0)
            'presencePenalty'  => -5.0,  // Out of range (min is -2.0)
        ];
        $completionResponse = $this->createCompletionResponse('Response');

        $capturedOptions = null;
        $this->llmServiceManagerMock
            ->expects($this->once())
            ->method('chat')
            ->with(
                $messages,
                $this->callback(function (?ChatOptions $options) use (&$capturedOptions): bool {
                    $capturedOptions = $options;

                    return $options !== null;
                }),
            )
            ->willReturn($completionResponse);

        $request = $this->createRequestWithJsonBody(['messages' => $messages, 'options' => $options]);
        $this->subject->chatAction($request);

        $this->assertNotNull($capturedOptions);
        $this->assertSame(2.0, $capturedOptions->getFrequencyPenalty());
        $this->assertSame(-2.0, $capturedOptions->getPresencePenalty());
    }

    #[Test]
    public function chatActionHandlesProviderException(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];

        $this->llmServiceManagerMock
            ->method('chat')
            ->willThrowException(new ProviderException('API key invalid'));

        $this->loggerMock
            ->expects($this->once())
            ->method('error')
            ->with('Chat provider error', $this->anything());

        $request  = $this->createRequestWithJsonBody(['messages' => $messages]);
        $response = $this->subject->chatAction($request);

        $this->assertSame(500, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        $this->assertStringContainsString('provider', strtolower($data['error']));
    }

    #[Test]
    public function chatActionHandlesUnexpectedException(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];

        $this->llmServiceManagerMock
            ->method('chat')
            ->willThrowException(new RuntimeException('Unexpected error'));

        $this->loggerMock
            ->expects($this->once())
            ->method('error')
            ->with('Chat action error', $this->anything());

        $request  = $this->createRequestWithJsonBody(['messages' => $messages]);
        $response = $this->subject->chatAction($request);

        $this->assertSame(500, $response->getStatusCode());
        $data = $this->decodeJsonResponse($response);
        $this->assertStringContainsString('unexpected', strtolower($data['error']));
    }

    #[Test]
    public function chatActionEscapesAllStringFieldsInResponse(): void
    {
        $messages           = [['role' => 'user', 'content' => 'Hello']];
        $completionResponse = new CompletionResponse(
            content: '<script>alert("xss")</script>',
            model: '<img src=x onerror=alert(1)>',
            usage: new UsageStatistics(10, 20, 30),
            finishReason: '<div onclick="hack()">stop</div>',
            provider: 'test',
        );

        $this->llmServiceManagerMock
            ->method('chat')
            ->willReturn($completionResponse);

        $request  = $this->createRequestWithJsonBody(['messages' => $messages]);
        $response = $this->subject->chatAction($request);

        $data = $this->decodeJsonResponse($response);
        $this->assertStringNotContainsString('<script>', $data['content']);
        $this->assertStringNotContainsString('<img', $data['model']);
        $this->assertStringNotContainsString('<div', $data['finishReason']);
        $this->assertStringContainsString('&lt;script&gt;', $data['content']);
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
            ->method('chat')
            ->willReturn($completionResponse);

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Improve this']);
        $response = $this->subject->completeAction($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $data = $this->decodeJsonResponse($response);
        $this->assertTrue($data['success']);
        $this->assertSame('Improved text', $data['content']);
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
    public function completeActionEscapesHtmlInResponse(): void
    {
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock
            ->method('findDefault')
            ->willReturn($config);

        $completionResponse = $this->createCompletionResponse('<script>alert("xss")</script>');
        $this->llmServiceManagerMock
            ->method('chat')
            ->willReturn($completionResponse);

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test']);
        $response = $this->subject->completeAction($request);

        $data = $this->decodeJsonResponse($response);
        $this->assertStringNotContainsString('<script>', $data['content']);
        $this->assertStringContainsString('&lt;script&gt;', $data['content']);
    }

    #[Test]
    public function completeActionHandlesProviderException(): void
    {
        $config = $this->createConfigurationMock();
        $this->configRepositoryMock
            ->method('findDefault')
            ->willReturn($config);

        $this->llmServiceManagerMock
            ->method('chat')
            ->willThrowException(new ProviderException('API key invalid'));

        $this->loggerMock
            ->expects($this->once())
            ->method('error');

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test']);
        $response = $this->subject->completeAction($request);

        $data = $this->decodeJsonResponse($response);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($data['success']);
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
            ->method('chat')
            ->willThrowException(new RuntimeException('Unexpected error'));

        $this->loggerMock
            ->expects($this->once())
            ->method('error');

        $request  = $this->createRequestWithJsonBody(['prompt' => 'Test']);
        $response = $this->subject->completeAction($request);

        $data = $this->decodeJsonResponse($response);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($data['success']);
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
            ->method('chat')
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
    public function completeActionAppliesModelOverride(): void
    {
        $config      = $this->createConfigurationMock();
        $chatOptions = new ChatOptions();
        $config->method('toChatOptions')->willReturn($chatOptions);

        $this->configRepositoryMock
            ->method('findDefault')
            ->willReturn($config);

        $completionResponse = $this->createCompletionResponse('Result');

        // Verify that chat is called with options that have the model set
        $this->llmServiceManagerMock
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function (array $messages) {
                    // Verify the prompt has prefix stripped
                    return $messages[1]['content'] === 'Improve this text';
                }),
                $this->callback(function (?ChatOptions $options) {
                    // Verify model override is applied
                    return $options !== null && $options->getModel() === 'gpt-4o';
                }),
            )
            ->willReturn($completionResponse);

        $request = $this->createRequestWithJsonBody([
            'prompt' => '#cw:gpt-4o Improve this text',
        ]);
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
            ->method('chat')
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

    private function createConfigurationMock(
        string $identifier = 'default',
        string $name = 'Default Config',
        bool $isDefault = true,
    ): LlmConfiguration&MockObject {
        $mock = $this->createMock(LlmConfiguration::class);
        $mock->method('getIdentifier')->willReturn($identifier);
        $mock->method('getName')->willReturn($name);
        $mock->method('isDefault')->willReturn($isDefault);
        $mock->method('toChatOptions')->willReturn(new ChatOptions());

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
