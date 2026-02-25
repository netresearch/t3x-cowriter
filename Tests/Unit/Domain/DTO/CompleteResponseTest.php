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
        $this->assertNotNull($response->usage);
        $this->assertNull($response->error);
        $this->assertNull($response->retryAfter);
    }

    #[Test]
    public function successEscapesHtmlInContent(): void
    {
        $completionResponse = $this->createCompletionResponse('<script>alert("xss")</script>');

        $response = CompleteResponse::success($completionResponse);

        $this->assertStringNotContainsString('<script>', $response->content);
        $this->assertStringContainsString('&lt;script&gt;', $response->content);
    }

    #[Test]
    #[DataProvider('htmlEscapingProvider')]
    public function successProperlyEscapesVariousHtmlPatterns(string $input, string $expectedContent): void
    {
        $completionResponse = $this->createCompletionResponse($input);

        $response = CompleteResponse::success($completionResponse);

        $this->assertSame($expectedContent, $response->content);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function htmlEscapingProvider(): iterable
    {
        yield 'script tag' => [
            '<script>alert(1)</script>',
            '&lt;script&gt;alert(1)&lt;/script&gt;',
        ];
        yield 'img onerror' => [
            '<img src=x onerror=alert(1)>',
            '&lt;img src=x onerror=alert(1)&gt;',
        ];
        yield 'single quotes' => [
            "Hello 'world'",
            'Hello &apos;world&apos;',
        ];
        yield 'double quotes' => [
            'Hello "world"',
            'Hello &quot;world&quot;',
        ];
        yield 'ampersand' => [
            'A & B',
            'A &amp; B',
        ];
        yield 'plain text unchanged' => [
            'Just plain text',
            'Just plain text',
        ];
    }

    #[Test]
    public function errorCreatesErrorResponse(): void
    {
        $response = CompleteResponse::error('Something went wrong');

        $this->assertFalse($response->success);
        $this->assertNull($response->content);
        $this->assertNull($response->model);
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
        $this->assertArrayNotHasKey('content', $json);
        $this->assertArrayNotHasKey('model', $json);
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
