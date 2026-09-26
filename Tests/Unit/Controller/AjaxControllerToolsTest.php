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
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Service\Tool\UnattendedToolFilterInterface;
use Netresearch\T3Cowriter\Controller\AjaxController;
use Netresearch\T3Cowriter\Service\ContextAssemblyServiceInterface;
use Netresearch\T3Cowriter\Service\DiagnosticService;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Service\Tool\UnattendedToolRunner;
use Netresearch\T3Cowriter\Tests\Support\ConfigurationAccessDouble;
use Netresearch\T3Cowriter\Tests\Support\RecordingEventStream;
use Netresearch\T3Cowriter\Tests\Support\XliffLanguageServiceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use Throwable;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;

#[CoversClass(AjaxController::class)]
final class AjaxControllerToolsTest extends TestCase
{
    use XliffLanguageServiceTrait;

    private LlmServiceManagerInterface&Stub $llm;

    /** @var list<list<string>|null> */
    private array $loopTools = [];

    /** @var list<string> */
    private array $unattended = ['search_content'];

    private ?Throwable $loopFailure = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useXliffLanguageService();
        $this->llm = $this->createStub(LlmServiceManagerInterface::class);
        $this->llm->method('chatWithConfiguration')->willReturn(
            new CompletionResponse('<p>Without tools</p>', 'gpt-test', new UsageStatistics(1, 1, 2), 'stop', 'test'),
        );

        $user = $this->createStub(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->user         = ['uid' => 2];
        $GLOBALS['BE_USER'] = $user;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function withToolsTheAnswerComesFromTheToolLoop(): void
    {
        $data = $this->json($this->subject()->executeTaskAction($this->request(useTools: true)));

        self::assertTrue($data['success']);
        self::assertSame('<p><strong>Two</strong> pages mention it.</p>', $data['content']);
        self::assertSame(3, $data['toolIterations']);
        self::assertSame('gpt-test', $data['model']);
        self::assertSame([['search_content']], $this->loopTools);
    }

    #[Test]
    public function withoutAnUnattendedToolTheAnswerComesWithoutTools(): void
    {
        $this->unattended = [];

        $data = $this->json($this->subject()->executeTaskAction($this->request(useTools: true)));

        self::assertSame('<p>Without tools</p>', $data['content']);
        self::assertArrayNotHasKey('toolIterations', $data);
        self::assertSame([], $this->loopTools);
    }

    #[Test]
    public function toolsAreOffUnlessTheRequestAsksForThem(): void
    {
        $data = $this->json($this->subject()->executeTaskAction($this->request(useTools: false)));

        self::assertSame('<p>Without tools</p>', $data['content']);
        self::assertSame([], $this->loopTools);
    }

    #[Test]
    public function aToolAskingForApprovalIsAConflict(): void
    {
        $this->loopFailure = ToolApprovalRequiredException::fromState(
            (new ReflectionClass(SuspendedRunState::class))->newInstanceWithoutConstructor(),
        );

        $response = $this->subject()->executeTaskAction($this->request(useTools: true));

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('approval', $this->json($response)['error']);
    }

    private function subject(): AjaxController
    {
        $configuration = $this->createStub(LlmConfiguration::class);
        $configuration->method('getIdentifier')->willReturn('default');
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('getModelId')->willReturn('gpt-test');
        $configurations = $this->createStub(LlmConfigurationRepository::class);
        $configurations->method('findDefault')->willReturn($configuration);

        $task = $this->createStub(Task::class);
        $task->method('isActive')->willReturn(true);
        $task->method('getConfiguration')->willReturn(null);
        $tasks = $this->createStub(TaskRepository::class);
        $tasks->method('findByUid')->willReturn($task);

        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(true, 20, 19, time() + 60));
        $context = $this->createStub(Context::class);
        $context->method('getPropertyFromAspect')->willReturn(1);

        $policy = $this->createStub(ToolCallPolicyInterface::class);
        $policy->method('filterOfferable')->willReturn(['search_content', 'update_record']);
        $filter = $this->createStub(UnattendedToolFilterInterface::class);
        $filter->method('unattended')->willReturnCallback(fn (): array => $this->unattended);
        $loop = $this->createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturnCallback(function (array $messages, LlmConfiguration $config, mixed $ctx, ?array $tools): ToolLoopResult {
            $this->loopTools[] = $tools;
            if ($this->loopFailure instanceof Throwable) {
                throw $this->loopFailure;
            }

            return new ToolLoopResult('**Two** pages mention it.', [], 3, false, new UsageStatistics(20, 8, 28));
        });

        return new AjaxController(
            $this->llm,
            ConfigurationAccessDouble::selector($configurations),
            $tasks,
            $rateLimiter,
            $context,
            new NullLogger(),
            $this->createStub(ContextAssemblyServiceInterface::class),
            $this->createStub(ConnectionPool::class),
            $this->createStub(BackendUriBuilder::class),
            $this->createStub(DiagnosticService::class),
            eventStream: new RecordingEventStream(),
            toolRunner: new UnattendedToolRunner($loop, $policy, $filter),
        );
    }

    private function request(bool $useTools): ServerRequestInterface
    {
        return (new ServerRequest('https://example.com/typo3/ajax/cowriter/task-execute', 'POST'))
            ->withParsedBody([
                'taskUid'     => 1,
                'context'     => '<p>Some text</p>',
                'contextType' => 'selection',
                'instruction' => 'Which pages mention the fair?',
                'useTools'    => $useTools,
            ]);
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
