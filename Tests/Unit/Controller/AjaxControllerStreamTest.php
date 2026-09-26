<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use Generator;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\T3Cowriter\Controller\AjaxController;
use Netresearch\T3Cowriter\Service\ContextAssemblyServiceInterface;
use Netresearch\T3Cowriter\Service\DiagnosticService;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Tests\Support\ConfigurationAccessDouble;
use Netresearch\T3Cowriter\Tests\Support\RecordingEventStream;
use Netresearch\T3Cowriter\Tests\Support\XliffLanguageServiceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\NullResponse;
use TYPO3\CMS\Core\Http\ServerRequest;

#[CoversClass(AjaxController::class)]
final class AjaxControllerStreamTest extends TestCase
{
    use XliffLanguageServiceTrait;

    private LlmServiceManagerInterface&Stub $llm;
    private LlmConfigurationRepository&Stub $configurations;
    private TaskRepository&Stub $tasks;
    private RecordingEventStream $stream;
    private LlmConfiguration&Stub $configuration;

    /** @var list<list<array<string, mixed>>> messages of each LLM call, streamed or not */
    private array $sentMessages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useXliffLanguageService();

        $this->configuration = $this->createStub(LlmConfiguration::class);
        $this->configuration->method('getIdentifier')->willReturn('default');
        $this->configuration->method('isActive')->willReturn(true);
        $this->configuration->method('getModelId')->willReturn('gpt-test');

        $this->configurations = $this->createStub(LlmConfigurationRepository::class);
        $this->configurations->method('findDefault')->willReturn($this->configuration);

        $task = $this->createStub(Task::class);
        $task->method('isActive')->willReturn(true);
        $task->method('getConfiguration')->willReturn(null);
        $this->tasks = $this->createStub(TaskRepository::class);
        $this->tasks->method('findByUid')->willReturn($task);

        $this->llm    = $this->createStub(LlmServiceManagerInterface::class);
        $this->stream = new RecordingEventStream();
    }

    #[Test]
    public function theAnswerIsStreamedAndEndsWithTheCompleteHtml(): void
    {
        $this->streamChunks(['Hello ', '**world**', '']);

        $response = $this->subject()->executeTaskStreamAction($this->request());

        self::assertInstanceOf(NullResponse::class, $response);
        self::assertSame('Hello **world**', $this->stream->streamedText());
        $done = $this->stream->events[array_key_last($this->stream->events)];
        self::assertTrue($done['done']);
        self::assertSame('gpt-test', $done['model']);
        self::assertIsString($done['content']);
        self::assertStringContainsString('<strong>world</strong>', $done['content']);
    }

    #[Test]
    public function theFirstPieceGoesOutAtOnceAndQuickFollowersAreGathered(): void
    {
        $this->streamChunks(['A', 'b', 'c', 'd']);

        $this->subject()->executeTaskStreamAction($this->request());

        self::assertSame(['A', 'bcd'], array_column(array_slice($this->stream->events, 0, -1), 'content'));
    }

    #[Test]
    public function theStreamSendsTheMessagesExecuteTaskSends(): void
    {
        $this->streamChunks(['x']);
        $this->llm->method('chatWithConfiguration')->willReturnCallback(function (array $messages): CompletionResponse {
            $this->sentMessages[] = $messages;

            return new CompletionResponse('x', 'gpt-test', new UsageStatistics(1, 1, 2), 'stop', 'test');
        });

        $this->subject()->executeTaskAction($this->request());
        $this->subject()->executeTaskStreamAction($this->request());

        self::assertCount(2, $this->sentMessages);
        self::assertSame($this->sentMessages[0], $this->sentMessages[1]);
    }

    #[Test]
    public function aRefusedConfigurationIsAJsonAnswerAndOpensNoStream(): void
    {
        $response = $this->subject(['default'])->executeTaskStreamAction($this->request());

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(403, $response->getStatusCode());
        self::assertNull($this->stream->openedWith);
    }

    #[Test]
    public function theStreamIsOpenedWithTheRateLimitHeaders(): void
    {
        $this->streamChunks(['x']);

        $this->subject()->executeTaskStreamAction($this->request());

        self::assertSame('20', $this->stream->openedWith['X-RateLimit-Limit'] ?? null);
    }

    #[Test]
    public function aProviderFailureDuringTheStreamIsAnErrorEvent(): void
    {
        $this->llm->method('streamChatWithConfiguration')->willReturnCallback(static function (): Generator {
            yield 'Partial';
            throw new ProviderException('upstream down');
        });

        $this->subject()->executeTaskStreamAction($this->request());

        self::assertSame(
            ['error' => 'LLM provider error occurred. Please try again later.'],
            $this->stream->events[array_key_last($this->stream->events)],
        );
    }

    #[Test]
    public function anUnexpectedFailureDuringTheStreamIsAnErrorEvent(): void
    {
        $this->llm->method('streamChatWithConfiguration')->willReturnCallback(static function (): Generator {
            throw new RuntimeException('boom');
            yield 'never';
        });

        $this->subject()->executeTaskStreamAction($this->request());

        self::assertSame(['error' => 'An unexpected error occurred.'], $this->stream->events[0]);
        self::assertCount(1, $this->stream->events);
    }

    /**
     * @param list<string> $chunks
     */
    private function streamChunks(array $chunks): void
    {
        $this->llm->method('streamChatWithConfiguration')->willReturnCallback(function (array $messages) use ($chunks): Generator {
            $this->sentMessages[] = $messages;
            yield from $chunks;
        });
    }

    /**
     * @param list<string> $deniedIdentifiers
     */
    private function subject(array $deniedIdentifiers = []): AjaxController
    {
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(true, 20, 19, time() + 60));
        $context = $this->createStub(Context::class);
        $context->method('getPropertyFromAspect')->willReturn(1);

        return new AjaxController(
            $this->llm,
            ConfigurationAccessDouble::selector($this->configurations, $deniedIdentifiers),
            $this->tasks,
            $rateLimiter,
            $context,
            new NullLogger(),
            $this->createStub(ContextAssemblyServiceInterface::class),
            $this->createStub(ConnectionPool::class),
            $this->createStub(BackendUriBuilder::class),
            $this->createStub(DiagnosticService::class),
            eventStream: $this->stream,
        );
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequest('https://example.com/typo3/ajax/cowriter/task-stream', 'POST'))
            ->withParsedBody([
                'taskUid'     => 1,
                'context'     => '<p>Some text</p>',
                'contextType' => 'selection',
                'instruction' => 'Improve this',
            ]);
    }
}
