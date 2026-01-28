<?php

/*
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\E2E;

use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\T3Cowriter\Controller\AjaxController;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * Base class for End-to-End tests.
 *
 * E2E tests verify complete workflows from AjaxController entry point
 * through LlmServiceManager and back, using mocked LLM responses to
 * simulate external API interactions.
 *
 * Unlike unit/integration tests that mock individual methods, E2E tests
 * focus on realistic response data and complete request/response cycles.
 */
#[AllowMockObjectsWithoutExpectations]
abstract class AbstractE2ETestCase extends TestCase
{
    protected NullLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new NullLogger();
    }

    /**
     * Create a complete AjaxController stack with mocked LlmServiceManager.
     *
     * The LlmServiceManager mock will be configured to return responses
     * from the provided queue in order.
     *
     * @param array<CompletionResponse> $responseQueue Responses to return from chat() calls
     *
     * @return array{controller: AjaxController, serviceManager: LlmServiceManagerInterface&MockObject, configRepo: LlmConfigurationRepository&MockObject}
     */
    protected function createCompleteStack(array $responseQueue = []): array
    {
        // Create mocked LLM service manager
        $serviceManager = $this->createMock(LlmServiceManagerInterface::class);

        if ($responseQueue !== []) {
            $serviceManager->method('chat')
                ->willReturnOnConsecutiveCalls(...$responseQueue);
        }

        // Create configuration repository mock
        $configRepo = $this->createMock(LlmConfigurationRepository::class);

        // Create controller with mocked dependencies
        $controller = new AjaxController(
            $serviceManager,
            $configRepo,
            $this->logger,
        );

        return [
            'controller'     => $controller,
            'serviceManager' => $serviceManager,
            'configRepo'     => $configRepo,
        ];
    }

    /**
     * Create a JSON HTTP response.
     *
     * @param array<string, mixed> $data
     */
    protected function createJsonResponse(array $data, int $status = 200): ResponseInterface
    {
        return new Response(
            status: $status,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Create a CompletionResponse simulating an OpenAI-style response.
     *
     * This creates realistic response data as would come from an LLM provider.
     */
    protected function createOpenAiResponse(
        string $content,
        string $model = 'gpt-4o',
        string $finishReason = 'stop',
        int $promptTokens = 50,
        int $completionTokens = 100,
        string $provider = 'openai',
    ): CompletionResponse {
        return new CompletionResponse(
            content: $content,
            model: $model,
            usage: UsageStatistics::fromTokens($promptTokens, $completionTokens),
            finishReason: $finishReason,
            provider: $provider,
        );
    }

    /**
     * Create mock ServerRequest with JSON body.
     *
     * @param array<string, mixed> $body
     */
    protected function createJsonRequest(array $body): ServerRequestInterface
    {
        $bodyStub = self::createStub(StreamInterface::class);
        $bodyStub->method('getContents')->willReturn(json_encode($body, JSON_THROW_ON_ERROR));

        $request = self::createStub(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($bodyStub);
        $request->method('getParsedBody')->willReturn(null);

        return $request;
    }

    /**
     * Create a ChatOptions stub that properly handles withModel() chaining.
     */
    protected function createChatOptionsStub(string $model = 'gpt-4o'): ChatOptions&Stub
    {
        $chatOptions = self::createStub(ChatOptions::class);
        $chatOptions->method('getModel')->willReturn($model);
        $chatOptions->method('withModel')->willReturnCallback(
            fn (string $newModel) => $this->createChatOptionsStub($newModel),
        );

        return $chatOptions;
    }

    /**
     * Create a mock LlmConfiguration.
     */
    protected function createLlmConfiguration(
        string $identifier = 'default',
        string $name = 'Default Configuration',
        bool $isDefault = true,
        string $model = 'gpt-4o',
    ): LlmConfiguration&Stub {
        $chatOptions = $this->createChatOptionsStub($model);

        $config = self::createStub(LlmConfiguration::class);
        $config->method('getIdentifier')->willReturn($identifier);
        $config->method('getName')->willReturn($name);
        $config->method('isDefault')->willReturn($isDefault);
        $config->method('toChatOptions')->willReturn($chatOptions);

        return $config;
    }

    /**
     * Create a QueryResultInterface implementation for testing.
     *
     * @param array<LlmConfiguration> $items
     */
    protected function createQueryResultMock(array $items): QueryResultInterface
    {
        return new class ($items) implements QueryResultInterface {
            private int $position = 0;

            /** @param array<LlmConfiguration> $items */
            public function __construct(private readonly array $items) {}

            public function setQuery(\TYPO3\CMS\Extbase\Persistence\QueryInterface $query): void {}

            public function getQuery(): \TYPO3\CMS\Extbase\Persistence\QueryInterface
            {
                throw new RuntimeException('Not implemented');
            }

            public function getFirst(): ?object
            {
                return $this->items[0] ?? null;
            }

            public function toArray(): array
            {
                return $this->items;
            }

            public function count(): int
            {
                return count($this->items);
            }

            public function current(): mixed
            {
                return $this->items[$this->position] ?? null;
            }

            public function key(): int
            {
                return $this->position;
            }

            public function next(): void
            {
                ++$this->position;
            }

            public function rewind(): void
            {
                $this->position = 0;
            }

            public function valid(): bool
            {
                return isset($this->items[$this->position]);
            }

            public function offsetExists(mixed $offset): bool
            {
                return isset($this->items[$offset]);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->items[$offset] ?? null;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                throw new RuntimeException('Read-only');
            }

            public function offsetUnset(mixed $offset): void
            {
                throw new RuntimeException('Read-only');
            }
        };
    }
}
