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
use Netresearch\T3Cowriter\Domain\DTO\ContextRequest;
use Netresearch\T3Cowriter\Domain\DTO\ExecuteTaskRequest;
use Netresearch\T3Cowriter\Service\ContextAssemblyServiceInterface;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
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
 * Returns raw data in JSON responses — no server-side HTML escaping.
 * The frontend sanitizes content via DOMParser before DOM insertion.
 */
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
        private ContextAssemblyServiceInterface $contextAssemblyService,
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

            return $this->jsonResponseWithRateLimitHeaders([
                'success'      => true,
                'content'      => $response->content ?? '',
                'model'        => $response->model ?? '',
                'finishReason' => $response->finishReason ?? '',
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
     * Response includes usage statistics.
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
                $chunks[] = 'data: ' . json_encode(['content' => $chunk], JSON_THROW_ON_ERROR) . "\n\n";
            }

            // Add final "done" event
            $chunks[] = 'data: ' . json_encode([
                'done'  => true,
                'model' => $configuration->getModelId(),
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
            if (!$task instanceof Task) {
                continue;
            }

            if (!$task->isActive()) {
                continue;
            }

            $list[] = [
                'uid'         => $task->getUid(),
                'identifier'  => $task->getIdentifier(),
                'name'        => $task->getName(),
                'description' => $task->getDescription(),
            ];
        }

        return new JsonResponse([
            'success' => true,
            'tasks'   => $list,
        ]);
    }

    /**
     * Get a lightweight context preview (word count, summary).
     *
     * Returns summary information about the content that would be assembled
     * for the given scope, without building the full context text.
     */
    public function getContextAction(ServerRequestInterface $request): ResponseInterface
    {
        $dto = ContextRequest::fromQueryParams($request);

        if (!$dto->isValid()) {
            return new JsonResponse(
                ['success' => false, 'error' => 'Invalid context request.'],
                400,
            );
        }

        try {
            $result = $this->contextAssemblyService->getContextSummary(
                $dto->table,
                $dto->uid,
                $dto->field,
                $dto->scope,
            );

            return new JsonResponse([
                'success'   => true,
                'summary'   => $result['summary'],
                'wordCount' => $result['wordCount'],
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Context preview error', [
                'exception' => $e->getMessage(),
            ]);

            return new JsonResponse(
                ['success' => false, 'error' => 'Failed to fetch context preview.'],
                500,
            );
        }
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

        // Input is ALWAYS the editor content (the text to transform)
        $input              = $dto->context;
        $surroundingContext = '';

        // For extended scopes, assemble surrounding context as reference (NOT as input)
        if (!in_array($dto->contextScope, ['', 'selection', 'text'], true)
            && $dto->recordContext !== null
        ) {
            try {
                $surroundingContext = $this->contextAssemblyService->assembleContext(
                    $dto->recordContext['table'],
                    $dto->recordContext['uid'],
                    $dto->recordContext['field'],
                    $dto->contextScope,
                    $dto->referencePages,
                );
            } catch (Throwable $e) {
                $this->logger->error('Context assembly error', [
                    'exception' => $e->getMessage(),
                ]);

                return $this->jsonResponseWithRateLimitHeaders(
                    CompleteResponse::error('Failed to assemble context.')->jsonSerialize(),
                    $rateLimitResult,
                    500,
                );
            }
        }

        // Build prompt — inject ad-hoc rules BEFORE the input content so the LLM
        // processes them as part of the instructions, not as an afterthought.
        $promptInput = $input;
        if (trim($dto->adHocRules) !== '') {
            $promptInput = "ADDITIONAL RULES (follow these exactly — they override default behavior):\n"
                . $dto->adHocRules . "\n\n"
                . "Content to transform:\n" . $input;
        }

        $prompt = $task->buildPrompt(['input' => $promptInput]);

        // Build messages
        $messages = [];

        // Tell the LLM exactly what scope it is working with
        $isSelection      = $dto->contextType === 'selection';
        $scopeInstruction = $isSelection
            ? 'The user selected a portion of text. '
                . 'Return ONLY the transformed selection — '
                . 'do not add surrounding content or change the scope of the text.'
            : 'Return the complete transformed content.';
        $messages[] = ['role' => 'system', 'content' => $scopeInstruction];

        // Inject surrounding context as read-only reference (BEFORE the prompt)
        if ($surroundingContext !== '') {
            $messages[] = [
                'role'    => 'system',
                'content' => "<reference_context>\n"
                    . 'Surrounding content from the same page for reference. '
                    . 'Use this to understand the broader context, avoid duplicating information, '
                    . 'and match the existing tone, style, and formatting patterns '
                    . '(heading levels, list styles). '
                    . "Do NOT include this content in your output.\n"
                    . $surroundingContext . "\n"
                    . '</reference_context>',
            ];
        }

        // Inject editor capabilities with concrete HTML examples
        if (trim($dto->editorCapabilities) !== '') {
            $messages[] = [
                'role'    => 'system',
                'content' => 'The rich text editor supports these formatting features: '
                    . $dto->editorCapabilities
                    . ". You may use any of these in the output.\n"
                    . 'For inline styles, use: <span style="color: #hex"> for font colors, '
                    . '<span style="background-color: #hex"> for background colors, '
                    . '<mark> for text highlighting.',
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

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
