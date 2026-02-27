<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use JsonException;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\T3Cowriter\Domain\DTO\CompleteRequest;
use Netresearch\T3Cowriter\Domain\DTO\CompleteResponse;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

/**
 * AJAX controller for LLM interactions via nr-llm extension.
 *
 * Provides backend API endpoints for chat and completion requests,
 * routing them through the centralized LlmServiceManager.
 *
 * SECURITY: All LLM output is HTML-escaped to prevent XSS attacks.
 */
#[AsController]
final readonly class AjaxController
{
    /**
     * System prompt for the cowriter assistant.
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are a professional writing assistant integrated into a CMS editor.
        Your task is to improve, enhance, or generate text based on the user's request.
        Respond ONLY with the improved/generated text, without any explanations,
        markdown formatting, or additional commentary.
        PROMPT;

    public function __construct(
        private readonly LlmServiceManagerInterface $llmServiceManager,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly Context $context,
        private readonly LoggerInterface $logger,
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
        // Check rate limit
        $rateLimitResult = $this->checkRateLimit();
        if (!$rateLimitResult->allowed) {
            return $this->rateLimitedResponse($rateLimitResult);
        }

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

        if (!is_array($body)) {
            return new JsonResponse(['error' => 'Invalid JSON structure'], 400);
        }

        /** @var array<int, array{role: string, content: string}> $messages */
        $messages = isset($body['messages']) && is_array($body['messages']) ? $body['messages'] : [];
        /** @var array<string, mixed> $optionsData */
        $optionsData = isset($body['options']) && is_array($body['options']) ? $body['options'] : [];
        $options     = $this->createChatOptions($optionsData);

        if ($messages === []) {
            return new JsonResponse(['error' => 'Messages array is required'], 400);
        }

        try {
            $response = $this->llmServiceManager->chat($messages, $options);

            // Escape HTML to prevent XSS attacks (defense in depth for all string values)
            return $this->jsonResponseWithRateLimitHeaders([
                'content'      => htmlspecialchars($response->content, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'model'        => htmlspecialchars($response->model ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'finishReason' => htmlspecialchars($response->finishReason ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ], $rateLimitResult);
        } catch (ProviderException $e) {
            $this->logger->error('Chat provider error', ['exception' => $e->getMessage()]);

            return $this->jsonResponseWithRateLimitHeaders(
                ['error' => 'LLM provider error occurred'],
                $rateLimitResult,
                500,
            );
        } catch (Throwable $e) {
            $this->logger->error('Chat action error', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return $this->jsonResponseWithRateLimitHeaders(
                ['error' => 'An unexpected error occurred'],
                $rateLimitResult,
                500,
            );
        }
    }

    /**
     * Handle single completion requests with DTO-based processing.
     *
     * Expects JSON body with:
     * - prompt: string prompt to complete (supports #cw:model prefix)
     * - configuration: optional configuration identifier
     * - options: optional array with temperature, maxTokens, etc.
     *
     * Response includes usage statistics and properly escaped content.
     */
    public function completeAction(ServerRequestInterface $request): ResponseInterface
    {
        // Check rate limit
        $rateLimitResult = $this->checkRateLimit();
        if (!$rateLimitResult->allowed) {
            return $this->rateLimitedResponse($rateLimitResult);
        }

        $dto = CompleteRequest::fromRequest($request);

        if (!$dto->isValid()) {
            $errorMessage = trim($dto->prompt) === ''
                ? 'No prompt provided'
                : 'Prompt exceeds maximum allowed length';

            return $this->jsonResponseWithRateLimitHeaders(
                CompleteResponse::error($errorMessage)->jsonSerialize(),
                $rateLimitResult,
                400,
            );
        }

        // Resolve configuration (from identifier or default)
        $configuration = $this->resolveConfiguration($dto->configuration);
        if ($configuration === null) {
            return $this->jsonResponseWithRateLimitHeaders(
                CompleteResponse::error(
                    'No LLM configuration available. Please configure the nr_llm extension.',
                )->jsonSerialize(),
                $rateLimitResult,
                404,
            );
        }

        try {
            $messages = [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $dto->prompt],
            ];

            $options = $configuration->toChatOptions();

            // Apply model override if specified via #cw:model-name prefix
            if ($dto->modelOverride !== null) {
                $options = $options->withModel($dto->modelOverride);
            }

            $response = $this->llmServiceManager->chat($messages, $options);

            // CompleteResponse.success() handles HTML escaping
            return $this->jsonResponseWithRateLimitHeaders(
                CompleteResponse::success($response)->jsonSerialize(),
                $rateLimitResult,
            );
        } catch (ProviderException $e) {
            $this->logger->error('Cowriter provider error', [
                'exception' => $e->getMessage(),
            ]);

            // Don't expose provider details to client - log them instead
            return $this->jsonResponseWithRateLimitHeaders(
                CompleteResponse::error('LLM provider error occurred. Please try again later.')->jsonSerialize(),
                $rateLimitResult,
                500,
            );
        } catch (Throwable $e) {
            $this->logger->error('Cowriter unexpected error', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return $this->jsonResponseWithRateLimitHeaders(
                CompleteResponse::error('An unexpected error occurred.')->jsonSerialize(),
                $rateLimitResult,
                500,
            );
        }
    }

    /**
     * Handle streaming completion requests using Server-Sent Events.
     *
     * Expects JSON body with:
     * - prompt: string prompt to complete
     * - configuration: optional configuration identifier
     *
     * Returns: Server-Sent Events stream with incremental content chunks.
     * Event format: data: {"content": "chunk text"}\n\n
     * Final event: data: {"done": true, "model": "model-name"}\n\n
     */
    public function streamAction(ServerRequestInterface $request): ResponseInterface
    {
        // Check rate limit
        $rateLimitResult = $this->checkRateLimit();
        if (!$rateLimitResult->allowed) {
            return $this->rateLimitedResponse($rateLimitResult);
        }

        $dto = CompleteRequest::fromRequest($request);

        if (!$dto->isValid()) {
            $errorMessage = trim($dto->prompt) === ''
                ? 'No prompt provided'
                : 'Prompt exceeds maximum allowed length';

            return $this->sseErrorResponse($errorMessage, 400);
        }

        // Resolve configuration (from identifier or default)
        $configuration = $this->resolveConfiguration($dto->configuration);
        if ($configuration === null) {
            return $this->sseErrorResponse(
                'No LLM configuration available. Please configure the nr_llm extension.',
                404,
            );
        }

        // Check if streaming is supported
        if (!$this->llmServiceManager->supportsFeature('streaming')) {
            // Fall back to non-streaming response if streaming is not supported
            return $this->completeAction($request);
        }

        // Build the streaming response using a generator
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => $dto->prompt],
        ];

        $options = $configuration->toChatOptions();

        // Apply model override if specified via #cw:model-name prefix
        if ($dto->modelOverride !== null) {
            $options = $options->withModel($dto->modelOverride);
        }

        // Collect all chunks and return as SSE-formatted response
        // Note: True streaming requires output buffering disabled which isn't always possible in TYPO3
        // This implementation collects chunks and returns them in SSE format for compatibility
        try {
            $chunks    = [];
            $generator = $this->llmServiceManager->streamChat($messages, $options);

            foreach ($generator as $chunk) {
                $sanitizedChunk = htmlspecialchars($chunk, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $chunks[]       = 'data: ' . json_encode(['content' => $sanitizedChunk], JSON_THROW_ON_ERROR) . "\n\n";
            }

            // Add final "done" event
            $chunks[] = 'data: ' . json_encode([
                'done'  => true,
                'model' => htmlspecialchars($options->getModel() ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ], JSON_THROW_ON_ERROR) . "\n\n";

            $body = implode('', $chunks);

            $stream = new Stream('php://temp', 'rw');
            $stream->write($body);
            $stream->rewind();

            $response = new Response($stream, 200, [
                'Content-Type'      => 'text/event-stream',
                'Cache-Control'     => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ]);

            foreach ($rateLimitResult->getHeaders() as $name => $value) {
                $response = $response->withAddedHeader($name, $value);
            }

            return $response;
        } catch (ProviderException $e) {
            $this->logger->error('Cowriter streaming provider error', [
                'exception' => $e->getMessage(),
            ]);

            return $this->sseErrorResponse('LLM provider error occurred. Please try again later.', 500);
        } catch (Throwable $e) {
            $this->logger->error('Cowriter streaming unexpected error', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return $this->sseErrorResponse('An unexpected error occurred.', 500);
        }
    }

    /**
     * Get available LLM configurations for the frontend.
     *
     * Returns list of active configurations with identifier, name, and default flag.
     *
     * @param ServerRequestInterface $request Required by TYPO3 AJAX action signature
     */
    public function getConfigurationsAction(ServerRequestInterface $request): ResponseInterface
    {
        $configurations = $this->configurationRepository->findActive();

        $list = [];
        foreach ($configurations as $config) {
            if (!$config instanceof LlmConfiguration) {
                continue;
            }
            $list[] = [
                'identifier' => $config->getIdentifier(),
                'name'       => $config->getName(),
                'isDefault'  => $config->isDefault(),
            ];
        }

        return new JsonResponse([
            'success'        => true,
            'configurations' => $list,
        ]);
    }

    /**
     * Resolve configuration by identifier or return default.
     */
    private function resolveConfiguration(?string $identifier): ?LlmConfiguration
    {
        if ($identifier !== null && $identifier !== '') {
            return $this->configurationRepository->findOneByIdentifier($identifier);
        }

        return $this->configurationRepository->findDefault();
    }

    /**
     * Create ChatOptions from array of options with range validation.
     *
     * Validates and clamps numeric parameters to their valid ranges:
     * - temperature: 0.0 to 2.0
     * - topP: 0.0 to 1.0
     * - frequencyPenalty: -2.0 to 2.0
     * - presencePenalty: -2.0 to 2.0
     * - maxTokens: minimum 1
     *
     * @param array<string, mixed> $options
     */
    private function createChatOptions(array $options): ?ChatOptions
    {
        if ($options === []) {
            return null;
        }

        $temperature = isset($options['temperature']) && is_numeric($options['temperature'])
            ? $this->clampFloat((float) $options['temperature'], 0.0, 2.0)
            : null;
        $maxTokens = isset($options['maxTokens']) && is_numeric($options['maxTokens'])
            ? max(1, (int) $options['maxTokens'])
            : null;
        $topP = isset($options['topP']) && is_numeric($options['topP'])
            ? $this->clampFloat((float) $options['topP'], 0.0, 1.0)
            : null;
        $frequencyPenalty = isset($options['frequencyPenalty']) && is_numeric($options['frequencyPenalty'])
            ? $this->clampFloat((float) $options['frequencyPenalty'], -2.0, 2.0)
            : null;
        $presencePenalty = isset($options['presencePenalty']) && is_numeric($options['presencePenalty'])
            ? $this->clampFloat((float) $options['presencePenalty'], -2.0, 2.0)
            : null;
        $responseFormat = isset($options['responseFormat']) && is_string($options['responseFormat'])
            ? $options['responseFormat']
            : null;
        $systemPrompt = isset($options['systemPrompt']) && is_string($options['systemPrompt'])
            ? $options['systemPrompt']
            : null;
        /** @var array<int, string>|null $stopSequences */
        $stopSequences = isset($options['stopSequences']) && is_array($options['stopSequences'])
            ? $options['stopSequences']
            : null;
        $provider = isset($options['provider']) && is_string($options['provider'])
            ? $options['provider']
            : null;
        $model = isset($options['model']) && is_string($options['model'])
            ? $options['model']
            : null;

        return new ChatOptions(
            temperature: $temperature,
            maxTokens: $maxTokens,
            topP: $topP,
            frequencyPenalty: $frequencyPenalty,
            presencePenalty: $presencePenalty,
            responseFormat: $responseFormat,
            systemPrompt: $systemPrompt,
            stopSequences: $stopSequences,
            provider: $provider,
            model: $model,
        );
    }

    /**
     * Clamp a float value to a range.
     */
    private function clampFloat(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    /**
     * Check rate limit for current backend user.
     */
    private function checkRateLimit(): RateLimitResult
    {
        /** @var int|string $userId */
        $userId = $this->context->getPropertyFromAspect('backend.user', 'id', 0);

        return $this->rateLimiter->checkLimit((string) $userId);
    }

    /**
     * Create a rate-limited error response.
     */
    private function rateLimitedResponse(RateLimitResult $result): JsonResponse
    {
        $response = new JsonResponse(
            CompleteResponse::rateLimited($result->getRetryAfter())->jsonSerialize(),
            429,
        );

        foreach ($result->getHeaders() as $name => $value) {
            $response = $response->withAddedHeader($name, $value);
        }

        return $response->withAddedHeader('Retry-After', (string) $result->getRetryAfter());
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

    /**
     * Create an SSE-formatted error response.
     */
    private function sseErrorResponse(string $message, int $statusCode): Response
    {
        $body = 'data: ' . json_encode(['error' => $message], JSON_THROW_ON_ERROR) . "\n\n";

        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();

        return new Response($stream, $statusCode, [
            'Content-Type'  => 'text/event-stream',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
