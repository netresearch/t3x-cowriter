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
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\T3Cowriter\Controller\AjaxController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use TYPO3\CMS\Core\Http\JsonResponse;

#[CoversClass(AjaxController::class)]
final class AjaxControllerTest extends TestCase
{
    private AjaxController $subject;
    private LlmServiceManagerInterface&MockObject $llmServiceManagerMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->llmServiceManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $this->subject               = new AjaxController($this->llmServiceManagerMock);
    }

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

        $request  = $this->createRequestWithBody(['messages' => $messages]);
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
        $request  = $this->createRequestWithBody(['messages' => []]);
        $response = $this->subject->chatAction($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function completeActionReturnsJsonResponse(): void
    {
        $prompt             = 'Complete this sentence';
        $completionResponse = $this->createCompletionResponse('with great content.');

        $this->llmServiceManagerMock
            ->expects($this->once())
            ->method('complete')
            ->with($prompt, null)
            ->willReturn($completionResponse);

        $request  = $this->createRequestWithBody(['prompt' => $prompt]);
        $response = $this->subject->completeAction($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function completeActionReturnsErrorForEmptyPrompt(): void
    {
        $request  = $this->createRequestWithBody(['prompt' => '']);
        $response = $this->subject->completeAction($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function completeActionReturnsErrorOnException(): void
    {
        $this->llmServiceManagerMock
            ->method('complete')
            ->willThrowException(new RuntimeException('Provider error'));

        $request  = $this->createRequestWithBody(['prompt' => 'test']);
        $response = $this->subject->completeAction($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(500, $response->getStatusCode());
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

        $request  = $this->createRequestWithBody(['messages' => $messages, 'options' => $options]);
        $response = $this->subject->chatAction($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    private function createRequestWithBody(array $data): ServerRequestInterface&MockObject
    {
        $bodyMock = $this->createMock(StreamInterface::class);
        $bodyMock->method('getContents')->willReturn(json_encode($data));

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($bodyMock);

        return $request;
    }

    private function createCompletionResponse(string $content): CompletionResponse
    {
        $usage = new UsageStatistics(
            promptTokens: 10,
            completionTokens: 20,
            totalTokens: 30,
        );

        return new CompletionResponse(
            content: $content,
            model: 'test-model',
            usage: $usage,
            finishReason: 'stop',
            provider: 'test-provider',
        );
    }
}
