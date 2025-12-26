<?php

/*
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use JsonException;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
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
        private readonly LlmServiceManagerInterface $llmServiceManager,
    ) {}

    /**
     * Handle chat requests with conversation history.
     *
     * Expects JSON body with:
     * - messages: array of {role: string, content: string}
     * - options: optional array with temperature, maxTokens, etc.
     */
    public function chatAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = json_decode(
                $request->getBody()->getContents(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON in request body'], 400);
        }

        $messages = $body['messages'] ?? [];
        $options  = $this->createChatOptions($body['options'] ?? []);

        if ($messages === []) {
            return new JsonResponse(['error' => 'Messages array is required'], 400);
        }

        try {
            $response = $this->llmServiceManager->chat($messages, $options);

            return new JsonResponse([
                'content'      => $response->content,
                'model'        => $response->model,
                'finishReason' => $response->finishReason,
            ]);
        } catch (Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle single completion requests.
     *
     * Expects JSON body with:
     * - prompt: string prompt to complete
     * - options: optional array with temperature, maxTokens, etc.
     */
    public function completeAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = json_decode(
                $request->getBody()->getContents(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON in request body'], 400);
        }

        $prompt  = $body['prompt'] ?? '';
        $options = $this->createChatOptions($body['options'] ?? []);

        if ($prompt === '') {
            return new JsonResponse(['error' => 'Prompt is required'], 400);
        }

        try {
            $response = $this->llmServiceManager->complete($prompt, $options);

            return new JsonResponse([
                'completion'   => $response->content,
                'model'        => $response->model,
                'finishReason' => $response->finishReason,
            ]);
        } catch (Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create ChatOptions from array of options.
     *
     * @param array<string, mixed> $options
     */
    private function createChatOptions(array $options): ?ChatOptions
    {
        if ($options === []) {
            return null;
        }

        return new ChatOptions(
            temperature: isset($options['temperature']) ? (float) $options['temperature'] : null,
            maxTokens: isset($options['maxTokens']) ? (int) $options['maxTokens'] : null,
            topP: isset($options['topP']) ? (float) $options['topP'] : null,
            frequencyPenalty: isset($options['frequencyPenalty']) ? (float) $options['frequencyPenalty'] : null,
            presencePenalty: isset($options['presencePenalty']) ? (float) $options['presencePenalty'] : null,
            responseFormat: $options['responseFormat'] ?? null,
            systemPrompt: $options['systemPrompt'] ?? null,
            stopSequences: $options['stopSequences'] ?? null,
            provider: $options['provider'] ?? null,
            model: $options['model'] ?? null,
        );
    }
}
