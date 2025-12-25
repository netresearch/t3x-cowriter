<?php

declare(strict_types=1);

/*
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\NrLlm\Service\LlmServiceManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * AJAX controller for LLM interactions via nr-llm extension.
 *
 * Provides backend API endpoints for chat and completion requests,
 * routing them through the centralized LlmServiceManager.
 */
final class AjaxController
{
    public function __construct(
        private readonly LlmServiceManager $llmServiceManager,
    ) {}

    /**
     * Handle chat requests with conversation history.
     *
     * Expects JSON body with:
     * - messages: array of {role: string, content: string}
     * - options: optional array of provider-specific options
     */
    public function chatAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = json_decode(
                $request->getBody()->getContents(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON in request body'], 400);
        }

        $messages = $body['messages'] ?? [];
        $options = $body['options'] ?? [];

        if ($messages === []) {
            return new JsonResponse(['error' => 'Messages array is required'], 400);
        }

        try {
            $response = $this->llmServiceManager->chat($messages, $options);
            return new JsonResponse($response);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle single completion requests.
     *
     * Expects JSON body with:
     * - prompt: string prompt to complete
     * - options: optional array of provider-specific options
     */
    public function completeAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = json_decode(
                $request->getBody()->getContents(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON in request body'], 400);
        }

        $prompt = $body['prompt'] ?? '';
        $options = $body['options'] ?? [];

        if ($prompt === '') {
            return new JsonResponse(['error' => 'Prompt is required'], 400);
        }

        try {
            $response = $this->llmServiceManager->complete($prompt, $options);
            return new JsonResponse(['completion' => $response]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
