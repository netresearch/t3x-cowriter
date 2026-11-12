<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Service\Tool\UnattendedToolFilterInterface;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\T3Cowriter\Controller\AjaxController;
use Netresearch\T3Cowriter\Domain\DTO\GuardrailHints;
use Netresearch\T3Cowriter\Service\Tool\UnattendedToolRunner;
use Netresearch\T3Cowriter\Tests\Support\TaskRouteControllerTrait;
use Netresearch\T3Cowriter\Tests\Support\XliffLanguageServiceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(AjaxController::class)]
#[CoversClass(GuardrailHints::class)]
final class AjaxControllerToolsTest extends TestCase
{
    use TaskRouteControllerTrait;
    use XliffLanguageServiceTrait;

    private LlmServiceManagerInterface&Stub $llm;

    /** @var list<list<string>|null> */
    private array $loopTools = [];

    /** @var list<string> */
    private array $unattended = ['search_content'];

    private ?Throwable $loopFailure = null;

    /** @var (callable(): void)|null what the loop does besides answering, such as a guardrail reporting */
    private $duringLoop;

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
        GeneralUtility::purgeInstances();
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

    /**
     * The tool loop's result carries no response metadata: the hints nr-llm's
     * guardrails reported while it ran are collected around it (ADR-288 there).
     */
    #[Test]
    public function theHintsReportedDuringTheToolLoopArriveWithTheAnswer(): void
    {
        require_once __DIR__ . '/../Fixtures/GuardrailHintCollectorDouble.php';
        $collector = new (GuardrailHints::COLLECTOR)();
        GeneralUtility::addInstance(GuardrailHints::COLLECTOR, $collector);
        $hintClass        = 'Netresearch\\NrLlm\\Domain\\ValueObject\\GuardrailHint';
        $this->duringLoop = static function () use ($collector, $hintClass): void {
            $hint = class_exists($hintClass)
                ? new $hintClass('rule-set-judge', 'violation', 'Rule "Siezen": "Hallo du"')
                : (object) ['kind' => 'violation', 'message' => 'Rule "Siezen": "Hallo du"'];
            $collector->record($hint);
        };

        $data = $this->json($this->subject()->executeTaskAction($this->request(useTools: true)));

        self::assertSame([['kind' => 'violation', 'message' => 'Rule "Siezen": "Hallo du"']], $data['hints'] ?? null);
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
    public function aToolsRequestForSeveralVersionsGetsOneAnswerFromTheToolLoop(): void
    {
        $completion                   = new FakeCompletionService();
        $completion->structuredResult = ['variants' => ['<p>One</p>', '<p>Two</p>']];

        $data = $this->json($this->subject($completion)->executeTaskAction($this->request(useTools: true, variants: 2)));

        self::assertSame('<p><strong>Two</strong> pages mention it.</p>', $data['content']);
        self::assertArrayNotHasKey('variants', $data);
        self::assertSame([['search_content']], $this->loopTools);
        self::assertSame([], $completion->completeStructuredForConfigurationCalls);
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

    private function subject(?CompletionServiceInterface $completion = null): AjaxController
    {
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

            if ($this->duringLoop !== null) {
                ($this->duringLoop)();
            }

            return new ToolLoopResult('**Two** pages mention it.', [], 3, false, new UsageStatistics(20, 8, 28));
        });

        return $this->taskRouteController(
            $this->llm,
            completionService: $completion,
            toolRunner: new UnattendedToolRunner($loop, $policy, $filter),
        );
    }

    private function request(bool $useTools, int $variants = 1): ServerRequestInterface
    {
        return (new ServerRequest('https://example.com/typo3/ajax/cowriter/task-execute', 'POST'))
            ->withParsedBody([
                'taskUid'     => 1,
                'context'     => '<p>Some text</p>',
                'contextType' => 'selection',
                'instruction' => 'Which pages mention the fair?',
                'useTools'    => $useTools,
                'variants'    => $variants,
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
