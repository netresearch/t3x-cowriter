<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use Generator;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Service\Tool\UnattendedToolFilterInterface;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\T3Cowriter\Controller\AjaxController;
use Netresearch\T3Cowriter\Service\BudgetRefusal;
use Netresearch\T3Cowriter\Service\Tool\UnattendedToolRunner;
use Netresearch\T3Cowriter\Tests\Support\RecordingEventStream;
use Netresearch\T3Cowriter\Tests\Support\TaskRouteControllerTrait;
use Netresearch\T3Cowriter\Tests\Support\XliffLanguageServiceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;

/**
 * A call nr-llm's budget refuses is answered with 429 and a message that names
 * the limit, on every route of AjaxController that calls the model.
 */
#[CoversClass(AjaxController::class)]
#[CoversClass(BudgetRefusal::class)]
final class AjaxControllerBudgetTest extends TestCase
{
    use TaskRouteControllerTrait;
    use XliffLanguageServiceTrait;

    private const DAILY_TOKENS_MESSAGE = 'The request was refused. This limit is used up: your daily number of tokens.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useXliffLanguageService();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('chatRoutes')]
    public function aRefusedChatCallIsA429NamingTheLimit(string $action, array $body): void
    {
        $llm = $this->createStub(LlmServiceManagerInterface::class);
        $llm->method('chatWithConfiguration')->willThrowException(self::refusal());

        $response = $this->taskRouteController($llm)->{$action}($this->request($body));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame([
            'success'        => false,
            'error'          => self::DAILY_TOKENS_MESSAGE,
            'budgetExceeded' => true,
            'exceededLimit'  => BudgetCheckResult::LIMIT_DAILY_TOKENS,
        ], $this->json($response));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function chatRoutes(): iterable
    {
        yield 'chat' => ['chatAction', ['messages' => [['role' => 'user', 'content' => 'Hello']]]];

        yield 'completion' => ['completeAction', ['prompt' => 'Write something']];

        yield 'task' => ['executeTaskAction', self::taskBody()];
    }

    #[Test]
    public function aRefusalInsideAnotherExceptionIsFoundAllTheSame(): void
    {
        $llm = $this->createStub(LlmServiceManagerInterface::class);
        $llm->method('chatWithConfiguration')->willThrowException(new RuntimeException('wrapped', 0, self::refusal()));

        $response = $this->taskRouteController($llm)->executeTaskAction($this->request(self::taskBody()));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame(self::DAILY_TOKENS_MESSAGE, $this->json($response)['error']);
    }

    #[Test]
    public function anotherFailureStaysAnUnexpectedError(): void
    {
        $llm = $this->createStub(LlmServiceManagerInterface::class);
        $llm->method('chatWithConfiguration')->willThrowException(new RuntimeException('boom'));

        $response = $this->taskRouteController($llm)->executeTaskAction($this->request(self::taskBody()));

        self::assertSame(500, $response->getStatusCode());
        self::assertArrayNotHasKey('budgetExceeded', $this->json($response));
    }

    #[Test]
    public function aRefusedCompletionStreamIsA429EventNamingTheLimit(): void
    {
        $llm = $this->createStub(LlmServiceManagerInterface::class);
        $llm->method('streamChatWithConfiguration')->willReturnCallback(static function (): Generator {
            yield ''; // nr-llm checks the budget once the stream is read
            throw self::refusal();
        });

        $response = $this->taskRouteController($llm)->streamAction($this->request(['prompt' => 'Write something']));

        self::assertSame(429, $response->getStatusCode());
        $event = json_decode(substr((string) $response->getBody(), strlen('data: ')), true);
        self::assertIsArray($event);
        self::assertSame(self::DAILY_TOKENS_MESSAGE, $event['error']);
        self::assertTrue($event['budgetExceeded']);
    }

    #[Test]
    public function aTaskStreamTheBudgetRefusesIsA429BeforeTheStreamOpens(): void
    {
        $llm = $this->createMock(LlmServiceManagerInterface::class);
        $llm->expects(self::never())->method('streamChatWithConfiguration');
        $stream = new RecordingEventStream();

        $budget = $this->createMock(BudgetServiceInterface::class);
        // The user of the session (1 in the trait), as nr-llm's pre-flight asks.
        $budget->expects(self::once())->method('check')
            ->with(1, 0.0, self::anything())
            ->willReturn(BudgetCheckResult::denied(BudgetCheckResult::LIMIT_MONTHLY_COST, 50.0, 50.0));

        $response = $this->taskRouteController($llm, eventStream: $stream, budgetRefusal: new BudgetRefusal($budget))
            ->executeTaskStreamAction($this->request(self::taskBody()));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('The request was refused. This limit is used up: your monthly cost.', $this->json($response)['error']);
        self::assertNull($stream->openedWith);
    }

    #[Test]
    public function aTaskStreamWithinTheBudgetIsStreamed(): void
    {
        $llm = $this->createStub(LlmServiceManagerInterface::class);
        $llm->method('streamChatWithConfiguration')->willReturnCallback(static function (): Generator {
            yield '<p>Hello</p>';
        });
        $stream = new RecordingEventStream();
        $budget = $this->createStub(BudgetServiceInterface::class);
        $budget->method('check')->willReturn(BudgetCheckResult::allowed());

        $this->taskRouteController($llm, eventStream: $stream, budgetRefusal: new BudgetRefusal($budget))
            ->executeTaskStreamAction($this->request(self::taskBody()));

        self::assertSame('<p>Hello</p>', $stream->streamedText());
    }

    #[Test]
    public function aRefusalDuringTheTaskStreamIsAnEventNamingTheLimit(): void
    {
        $llm = $this->createStub(LlmServiceManagerInterface::class);
        $llm->method('streamChatWithConfiguration')->willReturnCallback(static function (): Generator {
            yield ''; // nr-llm checks the budget once the stream is read
            throw self::refusal();
        });
        $stream = new RecordingEventStream();

        $this->taskRouteController($llm, eventStream: $stream)->executeTaskStreamAction($this->request(self::taskBody()));

        self::assertSame([[
            'success'        => false,
            'error'          => self::DAILY_TOKENS_MESSAGE,
            'budgetExceeded' => true,
            'exceededLimit'  => BudgetCheckResult::LIMIT_DAILY_TOKENS,
        ]], $stream->events);
    }

    #[Test]
    public function aRefusedRequestForSeveralVersionsIsA429(): void
    {
        $completion            = new FakeCompletionService();
        $completion->throwable = self::refusal();

        $response = $this->taskRouteController($this->createStub(LlmServiceManagerInterface::class), completionService: $completion)
            ->executeTaskAction($this->request(['variants' => 2] + self::taskBody()));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame(self::DAILY_TOKENS_MESSAGE, $this->json($response)['error']);
    }

    #[Test]
    public function aToolLoopTheBudgetStoppedIsA429NamingTheLimitTheBudgetReports(): void
    {
        $user = $this->createStub(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->user         = ['uid' => 1];
        $GLOBALS['BE_USER'] = $user;

        $budget = $this->createStub(BudgetServiceInterface::class);
        $budget->method('check')->willReturn(
            BudgetCheckResult::denied(BudgetCheckResult::LIMIT_CONFIGURATION_DAILY_REQUESTS, 100.0, 100.0),
        );

        $response = $this->taskRouteController(
            $this->createStub(LlmServiceManagerInterface::class),
            toolRunner: $this->budgetStoppedToolRunner(),
            budgetRefusal: new BudgetRefusal($budget),
        )->executeTaskAction($this->request(['useTools' => true] + self::taskBody()));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame(
            'The request was refused. This limit is used up: the daily number of requests of this configuration, shared by all users.',
            $this->json($response)['error'],
        );
    }

    #[Test]
    public function aToolLoopTheBudgetStoppedIsA429AlsoWhenTheLimitIsUnknown(): void
    {
        $user = $this->createStub(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->user         = ['uid' => 1];
        $GLOBALS['BE_USER'] = $user;

        $response = $this->taskRouteController(
            $this->createStub(LlmServiceManagerInterface::class),
            toolRunner: $this->budgetStoppedToolRunner(),
        )->executeTaskAction($this->request(['useTools' => true] + self::taskBody()));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame([
            'success'        => false,
            'error'          => 'The request was refused because a limit of your AI budget is used up.',
            'budgetExceeded' => true,
            'exceededLimit'  => '',
        ], $this->json($response));
    }

    private function budgetStoppedToolRunner(): UnattendedToolRunner
    {
        $policy = $this->createStub(ToolCallPolicyInterface::class);
        $policy->method('filterOfferable')->willReturn(['search_content']);
        $filter = $this->createStub(UnattendedToolFilterInterface::class);
        $filter->method('unattended')->willReturn(['search_content']);
        $loop = $this->createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturn(new ToolLoopResult(
            '',
            [],
            1,
            true,
            new UsageStatistics(0, 0, 0),
            AgentRunTerminationReason::BUDGET_EXHAUSTED,
        ));

        return new UnattendedToolRunner($loop, $policy, $filter);
    }

    private static function refusal(): BudgetExceededException
    {
        return new BudgetExceededException(
            BudgetCheckResult::denied(BudgetCheckResult::LIMIT_DAILY_TOKENS, 10000.0, 10000.0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function taskBody(): array
    {
        return ['taskUid' => 1, 'context' => '<p>Some text</p>', 'contextType' => 'selection', 'instruction' => 'Shorten it'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body): ServerRequestInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode($body, JSON_THROW_ON_ERROR));
        $stream->rewind();

        return (new ServerRequest('https://example.com/typo3/ajax/cowriter', 'POST'))
            ->withBody($stream)
            ->withParsedBody($body);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
