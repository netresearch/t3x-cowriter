<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\T3Cowriter\Domain\DTO\ToolRequest;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Context\Context;

/**
 * AJAX controller for LLM tool calling via nr-llm.
 *
 * Runs the model through nr-llm's bounded tool-calling loop so registered
 * tools (e.g. the content query) are actually executed server-side, against a
 * resolved LLM configuration.
 *
 * @internal
 */
final readonly class ToolController
{
    use RateLimitedControllerTrait;

    public function __construct(
        private ToolLoopServiceInterface $toolLoopService,
        private LlmConfigurationRepository $configurationRepository,
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
            $configuration = $this->resolveConfiguration($toolRequest->configuration);
        } catch (ConfigurationNotFoundException $e) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $e->getMessage()],
                $rateLimitResult,
                400,
            );
        }

        if (!$configuration instanceof LlmConfiguration) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'No LLM configuration available. Please configure the nr_llm extension.'],
                $rateLimitResult,
                404,
            );
        }

        try {
            $messages = [
                ['role' => 'user', 'content' => $toolRequest->prompt],
            ];

            // An empty request list means "all globally-enabled tools" (null);
            // a non-empty list restricts to those names (intersected with the
            // enabled set by the loop). Never pass [] — that offers no tools.
            $allowedToolNames = $toolRequest->enabledTools !== [] ? $toolRequest->enabledTools : null;

            $result = $this->toolLoopService->runLoop(
                $messages,
                $configuration,
                $allowedToolNames,
                ToolOptions::auto(),
            );

            return $this->jsonResponseWithRateLimitHeaders([
                'success'    => true,
                'content'    => $result->finalContent,
                'iterations' => $result->iterations,
                'truncated'  => $result->truncated,
                'usage'      => [
                    'promptTokens'     => $result->usage->promptTokens,
                    'completionTokens' => $result->usage->completionTokens,
                    'totalTokens'      => $result->usage->totalTokens,
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
     * Resolve the LLM configuration to run the tool loop against.
     *
     * A requested-but-unknown identifier is an error (surfaced to the user),
     * never a silent fallback. When no identifier is requested the default
     * configuration is used; null means none is configured at all.
     *
     * @throws ConfigurationNotFoundException when $identifier is given but no
     *                                        matching configuration exists
     */
    private function resolveConfiguration(?string $identifier): ?LlmConfiguration
    {
        if ($identifier === null || $identifier === '') {
            return $this->configurationRepository->findDefault();
        }

        $configuration = $this->configurationRepository->findOneByIdentifier($identifier);
        if (!$configuration instanceof LlmConfiguration) {
            throw new ConfigurationNotFoundException(
                sprintf('LLM configuration "%s" not found.', $identifier),
                1784592000,
            );
        }

        return $configuration;
    }
}
