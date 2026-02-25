<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Integration;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\T3Cowriter\Tests\Support\TestQueryResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * Base class for integration tests.
 *
 * Provides utilities for testing controller flows with realistic
 * LLM response data and request/response simulation.
 */
#[AllowMockObjectsWithoutExpectations]
abstract class AbstractIntegrationTestCase extends TestCase
{
    /**
     * Create a mock ServerRequest with JSON body.
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
     * Create a mock ServerRequest with form data.
     *
     * @param array<string, mixed> $formData
     */
    protected function createFormRequest(array $formData): ServerRequestInterface
    {
        $bodyStub = self::createStub(StreamInterface::class);
        $bodyStub->method('getContents')->willReturn('');

        $request = self::createStub(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($bodyStub);
        $request->method('getParsedBody')->willReturn($formData);

        return $request;
    }

    /**
     * Create a CompletionResponse with realistic data.
     */
    protected function createCompletionResponse(
        string $content = 'This is improved text.',
        string $model = 'gpt-4o',
        int $promptTokens = 50,
        int $completionTokens = 100,
        string $finishReason = 'stop',
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
     * Create a mock LlmConfiguration.
     *
     * The returned ChatOptions stub properly handles withModel() chaining:
     * calling withModel('new-model') creates a new stub that returns 'new-model' from getModel().
     */
    protected function createLlmConfiguration(
        string $identifier = 'default',
        string $name = 'Default Configuration',
        bool $isDefault = true,
    ): LlmConfiguration&Stub {
        $chatOptions = $this->createChatOptionsStub('gpt-4o');

        $config = self::createStub(LlmConfiguration::class);
        $config->method('getIdentifier')->willReturn($identifier);
        $config->method('getName')->willReturn($name);
        $config->method('isDefault')->willReturn($isDefault);
        $config->method('toChatOptions')->willReturn($chatOptions);

        return $config;
    }

    /**
     * Create a ChatOptions stub that properly handles withModel() chaining.
     */
    private function createChatOptionsStub(string $model): ChatOptions&Stub
    {
        $chatOptions = self::createStub(ChatOptions::class);
        $chatOptions->method('getModel')->willReturn($model);

        // When withModel is called, return a new stub with the new model
        $chatOptions->method('withModel')->willReturnCallback(
            fn (string $newModel) => $this->createChatOptionsStub($newModel),
        );

        return $chatOptions;
    }

    /**
     * Get realistic OpenAI-style response content variations.
     *
     * Note: Avoid apostrophes in test data since they get HTML-escaped to &apos;
     *
     * @return array{original: string, improved: string}
     */
    protected function getTextImprovementPair(): array
    {
        $pairs = [
            [
                'original' => 'The product is good and works well.',
                'improved' => 'Our premium product delivers exceptional performance and reliability, exceeding customer expectations.',
            ],
            [
                'original' => 'We sell things online.',
                'improved' => 'We are a leading e-commerce platform offering a curated selection of quality products with fast, reliable delivery.',
            ],
            [
                'original' => 'Contact us if you have questions.',
                'improved' => 'Have questions? Our dedicated support team is here to help. Reach out anytime and we will respond promptly.',
            ],
        ];

        return $pairs[array_rand($pairs)];
    }

    /**
     * Create XSS attack payload for security testing.
     *
     * @return array<string, string>
     */
    protected function getXssPayloads(): array
    {
        return [
            'script_tag'      => '<script>alert("xss")</script>',
            'img_onerror'     => '<img src=x onerror=alert("xss")>',
            'svg_onload'      => '<svg onload=alert("xss")>',
            'javascript_href' => '<a href="javascript:alert(\'xss\')">click</a>',
            'event_handler'   => '<div onmouseover="alert(\'xss\')">hover me</div>',
        ];
    }

    /**
     * Assert response is successful JSON.
     *
     * @return array<string, mixed>
     */
    protected function assertSuccessfulJsonResponse(\Psr\Http\Message\ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['success'] ?? false);

        return $data;
    }

    /**
     * Assert response is error JSON with specific status code.
     *
     * @return array<string, mixed>
     */
    protected function assertErrorJsonResponse(
        \Psr\Http\Message\ResponseInterface $response,
        int $expectedStatusCode,
    ): array {
        self::assertSame($expectedStatusCode, $response->getStatusCode());
        $body = (string) $response->getBody();
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['success'] ?? true);
        self::assertArrayHasKey('error', $data);

        return $data;
    }

    /**
     * Create a QueryResultInterface implementation for testing.
     *
     * @param array<LlmConfiguration> $items
     *
     * @return QueryResultInterface<LlmConfiguration>
     */
    protected function createQueryResultMock(array $items): QueryResultInterface
    {
        return new TestQueryResult($items);
    }
}
