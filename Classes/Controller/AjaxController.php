<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use JsonException;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
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
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

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
     * Maximum length of a page search query string in characters.
     */
    private const MAX_SEARCH_QUERY_LENGTH = 200;

    /**
     * Maximum number of page search results to return.
     */
    private const MAX_SEARCH_RESULTS = 20;

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
        private ConnectionPool $connectionPool,
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
                $this->buildErrorResponse('LLM provider error occurred. Please try again later.', $e),
                $rateLimitResult,
                500,
            );
        } catch (Throwable $e) {
            $this->logger->error('Chat action error', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return $this->jsonResponseWithRateLimitHeaders(
                $this->buildErrorResponse('An unexpected error occurred.', $e),
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

            return $this->jsonResponseWithRateLimitHeaders(
                $this->buildErrorResponse('LLM provider error occurred. Please try again later.', $e),
                $rateLimitResult,
                500,
            );
        } catch (Throwable $e) {
            $this->logger->error('Cowriter unexpected error', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return $this->jsonResponseWithRateLimitHeaders(
                $this->buildErrorResponse('An unexpected error occurred.', $e),
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
                'uid'            => $task->getUid(),
                'identifier'     => $task->getIdentifier(),
                'name'           => $task->getName(),
                'description'    => $task->getDescription(),
                'promptTemplate' => $task->getPromptTemplate(),
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
     * The frontend sends the fully resolved instruction (prompt template with context
     * substituted, or a custom user-written instruction). When taskUid > 0, task
     * configuration is used for LLM routing; when taskUid === 0, custom mode is used.
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

        // Resolve task (optional — taskUid=0 is custom mode)
        $task = null;
        if ($dto->taskUid > 0) {
            $task = $this->taskRepository->findByUid($dto->taskUid);
            if (!$task instanceof Task || !$task->isActive()) {
                return $this->jsonResponseWithRateLimitHeaders(
                    CompleteResponse::error('Task not found or inactive.')->jsonSerialize(),
                    $rateLimitResult,
                    404,
                );
            }
        }

        $surroundingContext = '';

        // For extended scopes, assemble surrounding context as reference
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

        // Build messages
        $messages = [];

        // Core formatting instruction — must be first system message for reliable adherence
        $messages[] = [
            'role'    => 'system',
            'content' => 'You are a writing assistant integrated into a rich text editor (CKEditor). '
                . 'Respond ONLY with the content — no explanations, no preamble, no markdown. '
                . 'Use HTML tags for formatting (e.g., <strong>, <em>, <ul>, <ol>, <h2>, <p>). '
                . 'Do NOT use markdown syntax like **bold**, *italic*, # headings, or ```code blocks```.',
        ];

        // Tell the LLM exactly what scope it is working with (when context is present)
        if (trim($dto->context) !== '') {
            $isSelection      = $dto->contextType === 'selection';
            $scopeInstruction = $isSelection
                ? 'The user selected a portion of text. '
                    . 'Return ONLY the transformed selection — '
                    . 'do not add surrounding content or change the scope of the text.'
                : 'Return the complete transformed content.';
            $messages[] = ['role' => 'system', 'content' => $scopeInstruction];
        }

        // Inject editor content as structured system message
        if (trim($dto->context) !== '') {
            $messages[] = [
                'role'    => 'system',
                'content' => "<editor_content>\n" . $dto->context . "\n</editor_content>",
            ];
        }

        // Inject surrounding context as read-only reference (BEFORE the instruction)
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
                    . '. You may use any of these in the output. '
                    . 'Use <mark> for text highlighting.',
            ];
        }

        // Instruction is the user message — it IS the full prompt
        $messages[] = ['role' => 'user', 'content' => $dto->instruction];

        // Resolve configuration: task's config → request's config → default
        $taskConfig    = $task?->getConfiguration();
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

            // Post-process: convert markdown to HTML if the model ignored the formatting instruction
            $convertedContent = $this->convertMarkdownToHtml($response->content);
            if ($convertedContent !== $response->content) {
                $response = new CompletionResponse(
                    content: $convertedContent,
                    model: $response->model,
                    usage: $response->usage,
                    finishReason: $response->finishReason,
                    provider: $response->provider,
                    toolCalls: $response->toolCalls,
                    metadata: $response->metadata,
                    thinking: $response->thinking,
                );
            }

            $responseData = CompleteResponse::success($response)->jsonSerialize();

            // Only expose raw LLM messages in TYPO3 development mode
            /** @var array{BE?: array{debug?: bool}} $typo3ConfVars */
            $typo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
            if (($typo3ConfVars['BE']['debug'] ?? false) === true) {
                $responseData['debugMessages'] = $messages;
            }

            return $this->jsonResponseWithRateLimitHeaders(
                $responseData,
                $rateLimitResult,
            );
        } catch (ProviderException $e) {
            $this->logger->error('Task execution provider error', [
                'taskUid'   => $dto->taskUid,
                'exception' => $e->getMessage(),
            ]);

            return $this->jsonResponseWithRateLimitHeaders(
                $this->buildErrorResponse('LLM provider error occurred. Please try again later.', $e),
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
                $this->buildErrorResponse('An unexpected error occurred.', $e),
                $rateLimitResult,
                500,
            );
        }
    }

    /**
     * Search pages by title or UID for the reference page picker.
     */
    public function searchPagesAction(ServerRequestInterface $request): ResponseInterface
    {
        /** @var string $rawQuery */
        $rawQuery = $request->getQueryParams()['query'] ?? '';
        $query    = mb_substr(trim((string) $rawQuery), 0, self::MAX_SEARCH_QUERY_LENGTH);
        if ($query === '') {
            return new JsonResponse(['success' => true, 'pages' => []]);
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return new JsonResponse(['success' => false, 'error' => 'No backend user session.'], 403);
        }

        try {
            $qb = $this->connectionPool->getQueryBuilderForTable('pages');
            $qb->select('uid', 'title', 'slug')
                ->from('pages')
                ->setMaxResults(self::MAX_SEARCH_RESULTS);

            $conditions = [
                $qb->expr()->like(
                    'title',
                    $qb->createNamedParameter('%' . $qb->escapeLikeWildcards($query) . '%'),
                ),
            ];

            if (ctype_digit($query)) {
                $conditions[] = $qb->expr()->eq('uid', $qb->createNamedParameter((int) $query, Connection::PARAM_INT));
            }

            $qb->where($qb->expr()->or(...$conditions));

            $permClause = $backendUser->getPagePermsClause(Permission::PAGE_SHOW);
            if ($permClause !== '') {
                $qb->andWhere($permClause);
            }

            $qb->orderBy('title', 'ASC');

            $rows = $qb->executeQuery()->fetchAllAssociative();

            /** @var list<array{uid: int|string, title: string, slug: string}> $rows */
            $pages = array_map(static fn (array $row): array => [
                'uid'   => (int) $row['uid'],
                'title' => $row['title'],
                'slug'  => $row['slug'],
            ], $rows);

            return new JsonResponse(['success' => true, 'pages' => $pages]);
        } catch (Throwable $e) {
            $this->logger->error('Page search failed', ['exception' => $e->getMessage()]);

            return new JsonResponse(
                ['success' => false, 'error' => 'Failed to search pages.'],
                500,
            );
        }
    }

    /**
     * Build an error response array, including debug details when BE.debug is enabled.
     *
     * @return array<string, mixed>
     */
    private function buildErrorResponse(string $message, Throwable $exception): array
    {
        $userMessage = $this->enrichErrorMessage($message, $exception);
        $response    = CompleteResponse::error($userMessage)->jsonSerialize();

        /** @var array{BE?: array{debug?: bool}} $typo3ConfVars */
        $typo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (($typo3ConfVars['BE']['debug'] ?? false) === true) {
            $response['debugError'] = $exception->getMessage();
        }

        return $response;
    }

    /**
     * Enrich generic error messages with user-friendly guidance for known causes.
     */
    private function enrichErrorMessage(string $fallback, Throwable $exception): string
    {
        $exMessage = $exception->getMessage();

        if (str_contains($exMessage, 'no default provider configured') || str_contains($exMessage, 'No default LLM configuration')) {
            return 'No LLM provider is configured yet. An administrator needs to set up a provider and configuration in the LLM module (Admin Tools > LLM).';
        }

        if (str_contains($exMessage, '401') || str_contains($exMessage, 'Unauthorized')) {
            return 'The LLM provider rejected the API key. Please ask an administrator to check the provider settings.';
        }

        return $fallback;
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

    /**
     * Convert markdown-formatted LLM output to HTML.
     *
     * Small LLM models (e.g., qwen3:0.6b) may output pure markdown, mixed
     * HTML+markdown, or proper HTML. This method handles all three cases:
     * - Pure markdown → full block + inline conversion
     * - Mixed (e.g. <h2> headings but **bold** inline) → inline conversion + paragraph wrapping
     * - Proper HTML → pass through unchanged
     */
    private function convertMarkdownToHtml(string $content): string
    {
        // Skip conversion for very large outputs to avoid regex performance issues
        if (mb_strlen($content) > 65536) {
            return $content;
        }

        $hasHtmlBlocks     = preg_match('/<(p|div|h[1-6]|ul|ol|li|table|blockquote)\b/i', $content) === 1;
        $hasInlineMarkdown = preg_match('/\*\*.+?\*\*/', $content) === 1
            || preg_match('/(?<!\*)\*(?!\*)([^*]+)(?<!\*)\*(?!\*)/', $content) === 1
            || preg_match('/`[^`]+`/', $content) === 1
            || preg_match('/~~.+?~~/', $content) === 1
            || preg_match('/\[.+?\]\(.+?\)/', $content) === 1;
        $hasBlockMarkdown = preg_match('/(^#{1,6}\s|^[-*]\s|^\d+\.\s)/m', $content) === 1;

        if (!$hasInlineMarkdown && !$hasBlockMarkdown) {
            return $content;
        }

        // Always convert inline markdown (even in mixed HTML+markdown content)
        $content = $this->convertInlineMarkdown($content);

        if (!$hasHtmlBlocks) {
            // Pure markdown — also convert block-level patterns
            return $this->convertBlockMarkdown($content);
        }

        // Mixed HTML + bare text — wrap unwrapped text blocks in <p> tags
        return $this->wrapBareTextBlocks($content);
    }

    /**
     * Convert block-level markdown (headings, lists, paragraphs) to HTML.
     */
    private function convertBlockMarkdown(string $content): string
    {
        $lines  = explode("\n", $content);
        $html   = [];
        $inList = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                if ($inList !== null) {
                    $html[] = '</' . $inList . '>';
                    $inList = null;
                }

                continue;
            }

            // Headings: # → h2, ## → h3 (h1 is reserved for page title)
            if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $m) === 1) {
                if ($inList !== null) {
                    $html[] = '</' . $inList . '>';
                    $inList = null;
                }

                $level  = min(strlen($m[1]) + 1, 6);
                $html[] = sprintf('<h%d>%s</h%d>', $level, $m[2], $level);

                continue;
            }

            // Unordered list: - item or * item
            if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $m) === 1) {
                if ($inList !== 'ul') {
                    if ($inList !== null) {
                        $html[] = '</' . $inList . '>';
                    }

                    $html[] = '<ul>';
                    $inList = 'ul';
                }

                $html[] = '<li>' . $m[1] . '</li>';

                continue;
            }

            // Ordered list: 1. item
            if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $m) === 1) {
                if ($inList !== 'ol') {
                    if ($inList !== null) {
                        $html[] = '</' . $inList . '>';
                    }

                    $html[] = '<ol>';
                    $inList = 'ol';
                }

                $html[] = '<li>' . $m[1] . '</li>';

                continue;
            }

            if ($inList !== null) {
                $html[] = '</' . $inList . '>';
                $inList = null;
            }

            $html[] = '<p>' . $trimmed . '</p>';
        }

        if ($inList !== null) {
            $html[] = '</' . $inList . '>';
        }

        return implode("\n", $html);
    }

    /**
     * Wrap bare text blocks in <p> tags (for mixed HTML+text content).
     */
    private function wrapBareTextBlocks(string $content): string
    {
        $blocks = preg_split('/\n{2,}/', $content);
        if ($blocks === false) {
            return $content;
        }

        $result = [];

        foreach ($blocks as $block) {
            $trimmed = trim($block);

            if ($trimmed === '') {
                continue;
            }

            // Already an HTML block element — keep as-is
            if (preg_match('/^<(p|div|h[1-6]|ul|ol|li|table|blockquote|hr)\b/i', $trimmed) === 1) {
                $result[] = $trimmed;
            } else {
                // Bare text — wrap in <p>, preserve internal line breaks
                $result[] = '<p>' . str_replace("\n", '<br>', $trimmed) . '</p>';
            }
        }

        return implode("\n", $result);
    }

    /**
     * Convert inline markdown formatting to HTML.
     */
    private function convertInlineMarkdown(string $text): string
    {
        // Bold: **text**
        $text = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        // Italic: *text* (but not inside HTML tags or already-converted strong)
        $text = (string) preg_replace('/(?<![<\w*])\*([^*]+)\*(?![>\w])/', '<em>$1</em>', $text);
        // Links: [text](url) — only allow safe URL schemes
        $text = (string) preg_replace_callback(
            '/\[(.+?)\]\((.+?)\)/',
            static function (array $m): string {
                $url = trim($m[2]);
                if (preg_match('#^(https?://|/|\#)#i', $url) === 1) {
                    return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . $m[1] . '</a>';
                }

                return $m[1]; // Strip link with dangerous scheme
            },
            $text,
        );
        // Inline code: `code`
        $text = (string) preg_replace('/`(.+?)`/', '<code>$1</code>', $text);
        // Strikethrough: ~~text~~
        $text = (string) preg_replace('/~~(.+?)~~/', '<s>$1</s>', $text);

        return $text;
    }
}
