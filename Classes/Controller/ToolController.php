<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use JsonException;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\T3Cowriter\Domain\DTO\ToolRequest;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Tools\ContentQueryTool;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * AJAX controller for LLM tool calling via nr-llm.
 *
 * Enables structured function calling where the LLM can invoke
 * predefined tools (e.g., content queries) during conversations.
 *
 * @internal
 */
final readonly class ToolController
{
    public function __construct(
        private LlmServiceManagerInterface $llmServiceManager,
        private RateLimiterInterface $rateLimiter,
        private Context $context,
        private LoggerInterface $logger,
    ) {}

    public function executeAction(ServerRequestInterface $request): ResponseInterface
    {
        /** @var int|string $userId */
        $userId          = $this->context->getPropertyFromAspect('backend.user', 'id', 0);
        $rateLimitResult = $this->rateLimiter->checkLimit((string) $userId);

        if (!$rateLimitResult->allowed) {
            return $this->rateLimitedResponse($rateLimitResult);
        }

        $body = $this->parseJsonBody($request);
        if ($body === null) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'Invalid JSON in request body.'],
                $rateLimitResult,
                400,
            );
        }

        $toolRequest = ToolRequest::fromRequestBody($body);

        if (!$toolRequest->isValid()) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'Invalid request: fields exceed maximum length.'],
                $rateLimitResult,
                400,
            );
        }

        if ($toolRequest->prompt === '') {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'Missing prompt parameter.'],
                $rateLimitResult,
                400,
            );
        }

        try {
            $tools = $this->resolveTools($toolRequest->enabledTools);

            $messages = [
                ['role' => 'user', 'content' => $toolRequest->prompt],
            ];

            $options  = ToolOptions::auto();
            $response = $this->llmServiceManager->chatWithTools($messages, $tools, $options);

            return $this->jsonResponseWithRateLimitHeaders([
                'success'      => true,
                'content'      => $response->content,
                'toolCalls'    => $response->toolCalls,
                'finishReason' => $response->finishReason,
                'usage'        => [
                    'promptTokens'     => $response->usage->promptTokens,
                    'completionTokens' => $response->usage->completionTokens,
                    'totalTokens'      => $response->usage->totalTokens,
                ],
            ], $rateLimitResult);
        } catch (Throwable $e) {
            $this->logger->error('Tool execution failed', [
                'exception' => $e->getMessage(),
            ]);

            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'Tool execution failed. Please try again.'],
                $rateLimitResult,
                500,
            );
        }
    }

    /**
     * Resolve enabled tools from request against the allow-list.
     *
     * Falls back to all available tools when no valid tools are requested.
     *
     * @param list<string> $enabledTools
     *
     * @return array<int, array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    private function resolveTools(array $enabledTools): array
    {
        /** @var array<string, array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}> $allTools */
        $allTools = [
            'query_content' => ContentQueryTool::definition(),
        ];

        if ($enabledTools === []) {
            return array_values($allTools);
        }

        $resolved = [];
        foreach ($enabledTools as $toolName) {
            if (isset($allTools[$toolName])) {
                $resolved[] = $allTools[$toolName];
            }
        }

        return $resolved !== [] ? $resolved : array_values($allTools);
    }

    /**
     * Parse JSON body from request, returning null on failure.
     *
     * @return array<string, mixed>|null
     */
    private function parseJsonBody(ServerRequestInterface $request): ?array
    {
        $rawBody = (string) $request->getBody();
        if ($rawBody === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Create JSON response with rate limit headers.
     *
     * @param array<string, mixed> $data
     */
    private function jsonResponseWithRateLimitHeaders(
        array $data,
        RateLimitResult $rateLimitResult,
        int $statusCode = 200,
    ): JsonResponse {
        $response = new JsonResponse($data, $statusCode);

        foreach ($rateLimitResult->getHeaders() as $name => $value) {
            $response = $response->withAddedHeader($name, $value);
        }

        return $response;
    }

    private function rateLimitedResponse(RateLimitResult $result): JsonResponse
    {
        $response = new JsonResponse(
            ['success' => false, 'error' => 'Rate limit exceeded. Please try again later.'],
            429,
        );

        foreach ($result->getHeaders() as $name => $value) {
            $response = $response->withAddedHeader($name, $value);
        }

        return $response->withAddedHeader('Retry-After', (string) $result->getRetryAfter());
    }
}
