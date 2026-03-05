<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Domain\DTO;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\T3Cowriter\Domain\DTO\CompleteResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompleteResponse::class)]
final class CompleteResponseTest extends TestCase
{
    #[Test]
    public function successCreatesSuccessResponse(): void
    {
        $completionResponse = $this->createCompletionResponse('Generated text');

        $response = CompleteResponse::success($completionResponse);

        $this->assertTrue($response->success);
        $this->assertSame('Generated text', $response->content);
        $this->assertSame('test-model', $response->model);
        $this->assertSame('stop', $response->finishReason);
        $this->assertNotNull($response->usage);
        $this->assertNull($response->error);
        $this->assertNull($response->retryAfter);
    }

    #[Test]
    public function successPreservesRawHtmlContent(): void
    {
        $completionResponse = $this->createCompletionResponse('<p>Hello <strong>world</strong></p>');

        $response = CompleteResponse::success($completionResponse);

        $this->assertSame('<p>Hello <strong>world</strong></p>', $response->content);
    }

    #[Test]
    #[DataProvider('rawContentProvider')]
    public function successPreservesRawContent(string $input): void
    {
        $completionResponse = $this->createCompletionResponse($input);

        $response = CompleteResponse::success($completionResponse);

        $this->assertSame($input, $response->content);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rawContentProvider(): iterable
    {
        yield 'HTML tags preserved' => ['<p>Hello <strong>world</strong></p>'];
        yield 'single quotes preserved' => ["Hello 'world'"];
        yield 'double quotes preserved' => ['Hello "world"'];
        yield 'ampersand preserved' => ['A & B'];
        yield 'plain text unchanged' => ['Just plain text'];
    }

    #[Test]
    public function errorCreatesErrorResponse(): void
    {
        $response = CompleteResponse::error('Something went wrong');

        $this->assertFalse($response->success);
        $this->assertNull($response->content);
        $this->assertNull($response->model);
        $this->assertNull($response->finishReason);
        $this->assertNull($response->usage);
        $this->assertSame('Something went wrong', $response->error);
        $this->assertNull($response->retryAfter);
    }

    #[Test]
    public function rateLimitedCreatesRateLimitResponse(): void
    {
        $response = CompleteResponse::rateLimited(60);

        $this->assertFalse($response->success);
        $this->assertNull($response->content);
        $this->assertNull($response->model);
        $this->assertNull($response->finishReason);
        $this->assertNull($response->usage);
        $this->assertStringContainsString('rate limit', strtolower($response->error ?? ''));
        $this->assertSame(60, $response->retryAfter);
    }

    #[Test]
    public function jsonSerializeFormatsSuccessCorrectly(): void
    {
        $completionResponse = $this->createCompletionResponse('Result', 100, 200);
        $response           = CompleteResponse::success($completionResponse);

        $json = $response->jsonSerialize();

        $this->assertTrue($json['success']);
        $this->assertSame('Result', $json['content']);
        $this->assertSame('test-model', $json['model']);
        $this->assertSame('stop', $json['finishReason']);
        $this->assertIsArray($json['usage']);
        $this->assertSame(100, $json['usage']['promptTokens']);
        $this->assertSame(200, $json['usage']['completionTokens']);
        $this->assertSame(300, $json['usage']['totalTokens']);
        $this->assertArrayNotHasKey('error', $json);
        $this->assertArrayNotHasKey('retryAfter', $json);
    }

    #[Test]
    public function jsonSerializeFormatsErrorCorrectly(): void
    {
        $response = CompleteResponse::error('Test error');

        $json = $response->jsonSerialize();

        $this->assertFalse($json['success']);
        $this->assertSame('Test error', $json['error']);
        $this->assertFalse($json['wasTruncated']);
        $this->assertFalse($json['wasFiltered']);
        $this->assertArrayNotHasKey('content', $json);
        $this->assertArrayNotHasKey('model', $json);
        $this->assertArrayNotHasKey('finishReason', $json);
        $this->assertArrayNotHasKey('usage', $json);
        $this->assertArrayNotHasKey('retryAfter', $json);
    }

    #[Test]
    public function jsonSerializeIncludesRetryAfterForRateLimit(): void
    {
        $response = CompleteResponse::rateLimited(120);

        $json = $response->jsonSerialize();

        $this->assertFalse($json['success']);
        $this->assertSame(120, $json['retryAfter']);
    }

    // ===========================================
    // Cycle 32: Edge Case Coverage Tests
    // ===========================================

    #[Test]
    public function successHandlesEmptyModelAndFinishReason(): void
    {
        $usage = new UsageStatistics(
            promptTokens: 10,
            completionTokens: 20,
            totalTokens: 30,
        );

        $completionResponse = new CompletionResponse(
            content: 'Result',
            model: '',
            usage: $usage,
            finishReason: '',
            provider: 'test',
        );

        $response = CompleteResponse::success($completionResponse);

        $this->assertTrue($response->success);
        $this->assertSame('Result', $response->content);
        $this->assertSame('', $response->model);
        $this->assertSame('', $response->finishReason);
    }

    #[Test]
    public function jsonSerializeOmitsRetryAfterWhenNull(): void
    {
        $response = CompleteResponse::error('Some error');

        $json = $response->jsonSerialize();

        $this->assertArrayNotHasKey('retryAfter', $json);
        $this->assertNull($response->retryAfter);
    }

    #[Test]
    public function rateLimitedWithZeroRetryAfter(): void
    {
        $response = CompleteResponse::rateLimited(0);

        $json = $response->jsonSerialize();

        $this->assertFalse($json['success']);
        $this->assertSame(0, $json['retryAfter']);
    }

    #[Test]
    public function successPreservesRawFinishReason(): void
    {
        $usage = new UsageStatistics(10, 20, 30);

        $completionResponse = new CompletionResponse(
            content: 'text',
            model: 'model',
            usage: $usage,
            finishReason: 'stop<script>',
            provider: 'test',
        );

        $response = CompleteResponse::success($completionResponse);

        $this->assertSame('stop<script>', $response->finishReason);
    }

    #[Test]
    public function successPreservesRawModelAndFinishReason(): void
    {
        $usage = new UsageStatistics(10, 20, 30);

        $completionResponse = new CompletionResponse(
            content: "It's content",
            model: "model's-name",
            usage: $usage,
            finishReason: "it's done",
            provider: 'test',
        );

        $response = CompleteResponse::success($completionResponse);

        $this->assertSame("model's-name", $response->model);
        $this->assertSame("it's done", $response->finishReason);
    }

    #[Test]
    public function successDetectsWasTruncated(): void
    {
        $usage = new UsageStatistics(10, 20, 30);

        $completionResponse = new CompletionResponse(
            content: 'Partial text...',
            model: 'test-model',
            usage: $usage,
            finishReason: 'length',
            provider: 'test',
        );

        $response = CompleteResponse::success($completionResponse);

        self::assertTrue($response->wasTruncated);
        self::assertFalse($response->wasFiltered);
    }

    #[Test]
    public function successDetectsWasFiltered(): void
    {
        $usage = new UsageStatistics(10, 20, 30);

        $completionResponse = new CompletionResponse(
            content: '',
            model: 'test-model',
            usage: $usage,
            finishReason: 'content_filter',
            provider: 'test',
        );

        $response = CompleteResponse::success($completionResponse);

        self::assertFalse($response->wasTruncated);
        self::assertTrue($response->wasFiltered);
    }

    #[Test]
    public function successNormalCompletionNotTruncatedOrFiltered(): void
    {
        $completionResponse = $this->createCompletionResponse('Complete text');

        $response = CompleteResponse::success($completionResponse);

        self::assertFalse($response->wasTruncated);
        self::assertFalse($response->wasFiltered);
    }

    #[Test]
    public function jsonSerializeIncludesTruncationAndFilterFlags(): void
    {
        $usage = new UsageStatistics(10, 20, 30);

        $completionResponse = new CompletionResponse(
            content: 'Partial...',
            model: 'test-model',
            usage: $usage,
            finishReason: 'length',
            provider: 'test',
        );

        $response = CompleteResponse::success($completionResponse);
        $json     = $response->jsonSerialize();

        self::assertTrue($json['wasTruncated']);
        self::assertFalse($json['wasFiltered']);
    }

    #[Test]
    public function errorResponseHasFalseTruncationFlags(): void
    {
        $response = CompleteResponse::error('Something went wrong');

        self::assertFalse($response->wasTruncated);
        self::assertFalse($response->wasFiltered);
    }

    // ===========================================
    // Thinking extraction and fallback tests
    // ===========================================

    #[Test]
    public function successIncludesThinkingWhenPresent(): void
    {
        $usage              = new UsageStatistics(10, 20, 30);
        $completionResponse = new CompletionResponse(
            content: '<ul><li>Item 1</li></ul>',
            model: 'test-model',
            usage: $usage,
            finishReason: 'stop',
            provider: 'test-provider',
            thinking: 'I will convert this to a list.',
        );

        $response = CompleteResponse::success($completionResponse);

        $this->assertSame('I will convert this to a list.', $response->thinking);
        $this->assertSame(
            '<ul><li>Item 1</li></ul>',
            $response->content,
        );
    }

    #[Test]
    public function successFallsBackToThinkingWhenContentIsEmpty(): void
    {
        $usage              = new UsageStatistics(10, 20, 30);
        $completionResponse = new CompletionResponse(
            content: '',
            model: 'test-model',
            usage: $usage,
            finishReason: 'stop',
            provider: 'test-provider',
            thinking: '<ul><li>Item 1</li><li>Item 2</li></ul>',
        );

        $response = CompleteResponse::success($completionResponse);

        // Thinking content becomes the response content
        $this->assertSame(
            '<ul><li>Item 1</li><li>Item 2</li></ul>',
            $response->content,
        );
        // Thinking is cleared since it was promoted to content
        $this->assertNull($response->thinking);
    }

    #[Test]
    public function successFallsBackToThinkingWhenContentIsFragment(): void
    {
        $usage              = new UsageStatistics(10, 20, 30);
        $completionResponse = new CompletionResponse(
            content: '</',
            model: 'test-model',
            usage: $usage,
            finishReason: 'stop',
            provider: 'test-provider',
            thinking: '<ul><li>Real answer</li></ul>',
        );

        $response = CompleteResponse::success($completionResponse);

        // Fragment is replaced by thinking content
        $this->assertSame(
            '<ul><li>Real answer</li></ul>',
            $response->content,
        );
        $this->assertNull($response->thinking);
    }

    #[Test]
    public function successKeepsShortContentWhenNoThinking(): void
    {
        $completionResponse = $this->createCompletionResponse('OK');

        $response = CompleteResponse::success($completionResponse);

        $this->assertSame('OK', $response->content);
        $this->assertNull($response->thinking);
    }

    #[Test]
    public function successOmitsThinkingFromJsonWhenNull(): void
    {
        $completionResponse = $this->createCompletionResponse('Result');

        $json = CompleteResponse::success($completionResponse)->jsonSerialize();

        $this->assertArrayNotHasKey('thinking', $json);
    }

    #[Test]
    public function successIncludesThinkingInJsonWhenPresent(): void
    {
        $usage              = new UsageStatistics(10, 20, 30);
        $completionResponse = new CompletionResponse(
            content: 'Long enough content here',
            model: 'test-model',
            usage: $usage,
            finishReason: 'stop',
            provider: 'test-provider',
            thinking: 'My reasoning process',
        );

        $json = CompleteResponse::success($completionResponse)->jsonSerialize();

        $this->assertArrayHasKey('thinking', $json);
        $this->assertSame('My reasoning process', $json['thinking']);
    }

    #[Test]
    public function successPreservesRawHtmlInThinking(): void
    {
        $usage              = new UsageStatistics(10, 20, 30);
        $completionResponse = new CompletionResponse(
            content: 'Long enough content here',
            model: 'test-model',
            usage: $usage,
            finishReason: 'stop',
            provider: 'test-provider',
            thinking: '<script>alert("xss")</script>',
        );

        $response = CompleteResponse::success($completionResponse);

        // Raw content — frontend sanitizes via DOMParser
        $this->assertSame(
            '<script>alert("xss")</script>',
            $response->thinking,
        );
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
}
