<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use JsonException;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\T3Cowriter\Domain\DTO\CompleteRequest;
use Netresearch\T3Cowriter\Domain\DTO\CompleteResponse;
use Netresearch\T3Cowriter\Domain\DTO\ExecuteTaskRequest;
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
     * Maximum number of messages in a chat conversation.
     */
    private const MAX_MESSAGES = 50;

    /**
     * Maximum content length per message in characters (matches CompleteRequest::MAX_PROMPT_LENGTH).
     */
    private const MAX_MESSAGE_CONTENT_LENGTH = 32768;

    /**
     * Allowed message roles. The 'system' role is controlled server-side only.
     */
    private const ALLOWED_ROLES = ['user', 'assistant'];

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
        private LlmServiceManagerInterface $llmServiceManager,
        private LlmConfigurationRepository $configurationRepository,
        private TaskRepository $taskRepository,
        private RateLimiterInterface $rateLimiter,
        private Context $context,
        private LoggerInterface $logger,
    ) {}

    /**
     * Handle chat requests with conversation history.
     *
     * Expects JSON body with:
     * - messages: array of {role: string, content: string}
     * - configuration: optional configuration identifier
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
            return new JsonResponse(['success' => false, 'error' => 'Invalid JSON in request body'], 400);
        }

        if (!is_array($body)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid JSON structure'], 400);
        }

        $rawMessages = isset($body['messages']) && is_array($body['messages']) ? $body['messages'] : [];

        if ($rawMessages === []) {
            return new JsonResponse(['success' => false, 'error' => 'Messages array is required'], 400);
        }

        // Validate and sanitize messages: enforce structure, roles, count, and content length
        $messages = $this->validateMessages($rawMessages);
        if ($messages === null) {
            return new JsonResponse(
                ['success' => false, 'error' => 'Invalid messages: each message must have a valid role (user/assistant) and string content'],
                400,
            );
        }

        // Resolve configuration from request or fall back to default
        $configIdentifier = isset($body['configuration']) && is_string($body['configuration']) ? $body['configuration'] : null;
        $configuration    = $this->resolveConfiguration($configIdentifier);
        if (!$configuration instanceof LlmConfiguration) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'No LLM configuration available. Please configure the nr_llm extension.'],
                $rateLimitResult,
                404,
            );
        }

        try {
            $response = $this->llmServiceManager->chatWithConfiguration($messages, $configuration);

            // Escape HTML to prevent XSS attacks (defense in depth for all string values)
            return $this->jsonResponseWithRateLimitHeaders([
                'success'      => true,
                'content'      => htmlspecialchars($response->content, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'model'        => htmlspecialchars($response->model ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'finishReason' => htmlspecialchars($response->finishReason ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ], $rateLimitResult);
        } catch (ProviderException $e) {
            $this->logger->error('Chat provider error', ['exception' => $e->getMessage()]);

            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'LLM provider error occurred. Please try again later.'],
                $rateLimitResult,
                500,
            );
        } catch (Throwable $e) {
            $this->logger->error('Chat action error', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'An unexpected error occurred.'],
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
        if (!$configuration instanceof LlmConfiguration) {
            return $this->jsonResponseWithRateLimitHeaders(
                CompleteResponse::error(
                    'No LLM configuration available. Please configure the nr_llm extension.',
                )->jsonSerialize(),
                $rateLimitResult,
                404,
            );
        }

        return $this->executeCompletion($dto, $configuration, $rateLimitResult);
    }

    /**
     * Execute the completion request (shared by completeAction and streamAction fallback).
     *
     * Separated to avoid double rate-limiting and consumed request body
     * when streamAction falls back to non-streaming mode.
     */
    private function executeCompletion(
        CompleteRequest $dto,
        LlmConfiguration $configuration,
        RateLimitResult $rateLimitResult,
    ): ResponseInterface {
        try {
            $messages = [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $dto->prompt],
            ];

            $response = $this->llmServiceManager->chatWithConfiguration($messages, $configuration);

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
        if (!$configuration instanceof LlmConfiguration) {
            return $this->sseErrorResponse(
                'No LLM configuration available. Please configure the nr_llm extension.',
                404,
            );
        }

        // Build the streaming response using a generator
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => $dto->prompt],
        ];

        // Collect all chunks and return as SSE-formatted response
        // Note: True streaming requires output buffering disabled which isn't always possible in TYPO3
        // This implementation collects chunks and returns them in SSE format for compatibility
        try {
            $chunks    = [];
            $generator = $this->llmServiceManager->streamChatWithConfiguration($messages, $configuration);

            foreach ($generator as $chunk) {
                $sanitizedChunk = htmlspecialchars($chunk, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $chunks[]       = 'data: ' . json_encode(['content' => $sanitizedChunk], JSON_THROW_ON_ERROR) . "\n\n";
            }

            // Add final "done" event
            $chunks[] = 'data: ' . json_encode([
                'done'  => true,
                'model' => htmlspecialchars($configuration->getModelId(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
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
                'identifier' => htmlspecialchars($config->getIdentifier(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'name'       => htmlspecialchars($config->getName(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'isDefault'  => $config->isDefault(),
            ];
        }

        return new JsonResponse([
            'success'        => true,
            'configurations' => $list,
        ]);
    }

    /**
     * Get available cowriter tasks for the frontend dialog.
     *
     * Returns active tasks in the 'content' category for the cowriter dialog.
     *
     * @param ServerRequestInterface $request Required by TYPO3 AJAX action signature
     */
    public function getTasksAction(ServerRequestInterface $request): ResponseInterface
    {
        $tasks = $this->taskRepository->findByCategory('content');

        $list = [];
        foreach ($tasks as $task) {
            if (!$task instanceof Task || !$task->isActive()) {
                continue;
            }

            $list[] = [
                'uid'         => $task->getUid(),
                'identifier'  => htmlspecialchars($task->getIdentifier(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'name'        => htmlspecialchars($task->getName(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'description' => htmlspecialchars($task->getDescription(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ];
        }

        return new JsonResponse([
            'success' => true,
            'tasks'   => $list,
        ]);
    }

    /**
     * Execute a cowriter task with context.
     *
     * Loads the task, builds the prompt from its template + context,
     * optionally appends ad-hoc rules, and executes via LLM.
     */
    public function executeTaskAction(ServerRequestInterface $request): ResponseInterface
    {
        $rateLimitResult = $this->checkRateLimit();
        if (!$rateLimitResult->allowed) {
            return $this->rateLimitedResponse($rateLimitResult);
        }

        $dto = ExecuteTaskRequest::fromRequest($request);

        if (!$dto->isValid()) {
            return $this->jsonResponseWithRateLimitHeaders(
                CompleteResponse::error('Invalid task execution request.')->jsonSerialize(),
                $rateLimitResult,
                400,
            );
        }

        // Load and validate the task
        $task = $this->taskRepository->findByUid($dto->taskUid);
        if (!$task instanceof Task || !$task->isActive()) {
            return $this->jsonResponseWithRateLimitHeaders(
                CompleteResponse::error('Task not found or inactive.')->jsonSerialize(),
                $rateLimitResult,
                404,
            );
        }

        // Build prompt from task template
        $prompt = $task->buildPrompt(['input' => $dto->context]);

        // Build messages
        $messages = [
            ['role' => 'user', 'content' => $prompt],
        ];

        // Append ad-hoc rules as additional instruction if provided
        if (trim($dto->adHocRules) !== '') {
            $messages[] = [
                'role'    => 'user',
                'content' => 'Additional instructions: ' . $dto->adHocRules,
            ];
        }

        // Resolve configuration: task's config → request's config → default
        $taskConfig    = $task->getConfiguration();
        $configuration = $taskConfig instanceof LlmConfiguration
            ? $taskConfig
            : $this->resolveConfiguration($dto->configuration);

        if (!$configuration instanceof LlmConfiguration) {
            return $this->jsonResponseWithRateLimitHeaders(
                CompleteResponse::error(
                    'No LLM configuration available. Please configure the nr_llm extension.',
                )->jsonSerialize(),
                $rateLimitResult,
                404,
            );
        }

        try {
            $response = $this->llmServiceManager->chatWithConfiguration($messages, $configuration);

            return $this->jsonResponseWithRateLimitHeaders(
                CompleteResponse::success($response)->jsonSerialize(),
                $rateLimitResult,
            );
        } catch (ProviderException $e) {
            $this->logger->error('Task execution provider error', [
                'taskUid'   => $dto->taskUid,
                'exception' => $e->getMessage(),
            ]);

            return $this->jsonResponseWithRateLimitHeaders(
                CompleteResponse::error('LLM provider error occurred. Please try again later.')->jsonSerialize(),
                $rateLimitResult,
                500,
            );
        } catch (Throwable $e) {
            $this->logger->error('Task execution unexpected error', [
                'taskUid'   => $dto->taskUid,
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
     * Validate and sanitize chat messages.
     *
     * Enforces structure, allowed roles, count limit, and content length.
     *
     * @param array<mixed> $rawMessages
     *
     * @return array<int, array{role: string, content: string}>|null Validated messages or null on failure
     */
    private function validateMessages(array $rawMessages): ?array
    {
        if (count($rawMessages) > self::MAX_MESSAGES) {
            return null;
        }

        $validated = [];
        foreach ($rawMessages as $message) {
            if (!is_array($message)) {
                return null;
            }

            $role    = $message['role'] ?? null;
            $content = $message['content'] ?? null;

            if (!is_string($role) || !in_array($role, self::ALLOWED_ROLES, true)) {
                return null;
            }

            if (!is_string($content)) {
                return null;
            }

            if (mb_strlen($content, 'UTF-8') > self::MAX_MESSAGE_CONTENT_LENGTH) {
                return null;
            }

            $validated[] = ['role' => $role, 'content' => $content];
        }

        return $validated;
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
        try {
            $json = json_encode(['error' => $message], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $json = '{"error":"An error occurred"}';
        }

        $body = 'data: ' . $json . "\n\n";

        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();

        return new Response($stream, $statusCode, [
            'Content-Type'  => 'text/event-stream',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
