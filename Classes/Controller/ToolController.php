<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\T3Cowriter\Domain\DTO\ToolRequest;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
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
            return new JsonResponse(
                ['success' => false, 'error' => 'Rate limit exceeded. Please try again later.'],
                429,
            );
        }

        /** @var array<string, mixed> $body */
        $body        = (array) $request->getParsedBody();
        $toolRequest = ToolRequest::fromRequestBody($body);

        if ($toolRequest->prompt === '') {
            return new JsonResponse(
                ['success' => false, 'error' => 'Missing prompt parameter.'],
                400,
            );
        }

        try {
            $tools = [ContentQueryTool::definition()];

            $messages = [
                ['role' => 'user', 'content' => $toolRequest->prompt],
            ];

            $options  = ToolOptions::auto();
            $response = $this->llmServiceManager->chatWithTools($messages, $tools, $options);

            return new JsonResponse([
                'success'      => true,
                'content'      => $response->content,
                'toolCalls'    => $response->toolCalls,
                'finishReason' => $response->finishReason,
                'usage'        => [
                    'promptTokens'     => $response->usage->promptTokens,
                    'completionTokens' => $response->usage->completionTokens,
                    'totalTokens'      => $response->usage->totalTokens,
                ],
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Tool execution failed', [
                'exception' => $e->getMessage(),
            ]);

            return new JsonResponse(
                ['success' => false, 'error' => 'Tool execution failed. Please try again.'],
                500,
            );
        }
    }
}
