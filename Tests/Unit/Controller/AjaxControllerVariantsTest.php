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
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;

#[CoversClass(AjaxController::class)]
final class AjaxControllerVariantsTest extends TestCase
{
    use XliffLanguageServiceTrait;

    private LlmServiceManagerInterface&Stub $llm;

    /** @var list<array{prompt: string, schema: array<string, mixed>, system: string}> */
    private array $structuredCalls = [];

    /** @var array<string, mixed> */
    private array $structuredAnswer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useXliffLanguageService();
        $this->llm = $this->createStub(LlmServiceManagerInterface::class);
    }

    #[Test]
    public function twoVersionsComeBackAsHtmlFromOneStructuredCall(): void
    {
        $this->structuredAnswer = ['variants' => ['**First**', '<p>Second</p>']];

        $data = $this->json($this->subject()->executeTaskAction($this->request(2)));

        self::assertTrue($data['success']);
        self::assertSame(['<p><strong>First</strong></p>', '<p>Second</p>'], $data['variants']);
        self::assertSame($data['variants'][0], $data['content']);
        self::assertCount(1, $this->structuredCalls);
        self::assertSame('Improve this', $this->structuredCalls[0]['prompt']);
        self::assertSame(2, $this->structuredCalls[0]['schema']['properties']['variants']['minItems']);
        self::assertSame(2, $this->structuredCalls[0]['schema']['properties']['variants']['maxItems']);
        self::assertStringContainsString('Give exactly 2 different versions', $this->structuredCalls[0]['system']);
        self::assertStringContainsString('<editor_content>', $this->structuredCalls[0]['system']);
    }

    #[Test]
    public function moreThanThreeVersionsIsAnInvalidRequest(): void
    {
        $response = $this->subject()->executeTaskAction($this->request(4));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->structuredCalls);
    }

    #[Test]
    public function withoutTheCompletionServiceOneAnswerIsReturned(): void
    {
        $this->llm->method('chatWithConfiguration')->willReturn(
            new CompletionResponse('<p>Only</p>', 'gpt-test', new UsageStatistics(1, 1, 2), 'stop', 'test'),
        );

        $data = $this->json($this->subject(withCompletion: false)->executeTaskAction($this->request(2)));

        self::assertSame('<p>Only</p>', $data['content']);
        self::assertArrayNotHasKey('variants', $data);
    }

    #[Test]
    public function anAnswerWithoutVersionsIsAnError(): void
    {
        $this->structuredAnswer = ['variants' => ['', '   ']];

        $response = $this->subject()->executeTaskAction($this->request(2));

        self::assertSame(500, $response->getStatusCode());
        self::assertFalse($this->json($response)['success']);
    }

    private function subject(bool $withCompletion = true): AjaxController
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

        $completion = null;
        if ($withCompletion) {
            $completion = $this->createStub(CompletionServiceInterface::class);
            $completion->method('completeStructuredForConfiguration')->willReturnCallback(
                function (string $prompt, LlmConfiguration $configuration, array $schema, ?ChatOptions $options): array {
                    $this->structuredCalls[] = [
                        'prompt' => $prompt,
                        'schema' => $schema,
                        'system' => (string) $options?->getSystemPrompt(),
                    ];

                    return $this->structuredAnswer;
                },
            );
        }

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
            completionService: $completion,
        );
    }

    private function request(int $variants): ServerRequestInterface
    {
        return (new ServerRequest('https://example.com/typo3/ajax/cowriter/task-execute', 'POST'))
            ->withParsedBody([
                'taskUid'     => 1,
                'context'     => '<p>Some text</p>',
                'contextType' => 'selection',
                'instruction' => 'Improve this',
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
