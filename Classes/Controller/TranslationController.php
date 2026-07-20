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
use Netresearch\NrLlm\Service\Feature\TranslationServiceInterface;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\T3Cowriter\Domain\DTO\TranslationRequest;
use Netresearch\T3Cowriter\Service\DiagnosticService;
use Netresearch\T3Cowriter\Service\Dto\DiagnosticCheck;
use Netresearch\T3Cowriter\Service\LlmErrorClassifier;
use Netresearch\T3Cowriter\Service\LlmErrorKind;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Context\Context;

/**
 * AJAX controller for content translation via nr-llm TranslationService.
 *
 * @internal
 */
final readonly class TranslationController
{
    use RateLimitedControllerTrait;

    public function __construct(
        private TranslationServiceInterface $translationService,
        private LlmConfigurationRepository $configurationRepository,
        private RateLimiterInterface $rateLimiter,
        private Context $context,
        private LoggerInterface $logger,
        private BackendUriBuilder $backendUriBuilder,
        private DiagnosticService $diagnosticService,
        // Stateless; defaulted so manual constructors need no extra argument while
        // the Symfony container still autowires the shared service.
        private LlmErrorClassifier $errorClassifier = new LlmErrorClassifier(),
    ) {}

    public function translateAction(ServerRequestInterface $request): ResponseInterface
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

        $translationRequest = TranslationRequest::fromRequestBody($body);

        if (!$translationRequest->isValid()) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'Invalid request: fields exceed maximum length.'],
                $rateLimitResult,
                400,
            );
        }

        if ($translationRequest->text === '' || $translationRequest->targetLanguage === '') {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'Missing text or targetLanguage parameter.'],
                $rateLimitResult,
                400,
            );
        }

        try {
            $options = (new TranslationOptions(
                formality: $translationRequest->formality,
                domain: $translationRequest->domain,
            ))->withBeUserUid((int) $userId);

            // When an editor pins a stored configuration, route through the
            // per-configuration path (nr-llm 0.22, #428) so the configuration's
            // persona/tone, model and provider apply; otherwise use the plain
            // LLM translation path.
            $configuration = $this->resolveConfiguration($translationRequest->configuration);

            $result = $configuration instanceof LlmConfiguration
                ? $this->translationService->translateForConfiguration(
                    $translationRequest->text,
                    $translationRequest->targetLanguage,
                    $configuration,
                    null,
                    $options,
                )
                : $this->translationService->translate(
                    $translationRequest->text,
                    $translationRequest->targetLanguage,
                    null,
                    $options,
                );

            return $this->jsonResponseWithRateLimitHeaders([
                'success'        => true,
                'translation'    => $result->translation,
                'sourceLanguage' => $result->sourceLanguage,
                'confidence'     => $result->confidence,
                'usage'          => [
                    'promptTokens'     => $result->usage->promptTokens,
                    'completionTokens' => $result->usage->completionTokens,
                    'totalTokens'      => $result->usage->totalTokens,
                ],
            ], $rateLimitResult);
        } catch (Throwable $e) {
            $this->logger->error('Translation failed', [
                'exception'      => $e->getMessage(),
                'targetLanguage' => $translationRequest->targetLanguage,
            ]);

            $userError = $this->getUserFriendlyError($e);

            $errorData = ['success' => false, 'error' => $userError];

            if ($this->isConfigurationError($e)) {
                try {
                    $errorData['statusUrl'] = (string) $this->backendUriBuilder
                        ->buildUriFromRoute('cowriter_status');
                } catch (Throwable) {
                    // Route resolution failed — omit status URL
                }
            }

            return $this->jsonResponseWithRateLimitHeaders(
                $errorData,
                $rateLimitResult,
                500,
            );
        }
    }

    /**
     * Resolve a pinned configuration by identifier. Returns null when no
     * identifier was supplied, so translation uses the default LLM path.
     *
     * A requested-but-unknown identifier is an error, not a silent fallback:
     * falling back would apply the default persona/tone/model instead of the
     * one the editor asked for, without anyone noticing. The thrown exception
     * is caught in translateAction() and surfaced as a configuration error.
     *
     * @throws ConfigurationNotFoundException when $identifier is given but no
     *                                        matching configuration exists
     */
    private function resolveConfiguration(?string $identifier): ?LlmConfiguration
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        $configuration = $this->configurationRepository->findOneByIdentifier($identifier);
        if (!$configuration instanceof LlmConfiguration) {
            throw new ConfigurationNotFoundException(
                sprintf('LLM configuration "%s" not found.', $identifier),
                1784419200,
            );
        }

        return $configuration;
    }

    /**
     * Map a classified nr-llm failure to a user-friendly error string.
     */
    private function getUserFriendlyError(Throwable $e): string
    {
        return match ($this->errorClassifier->classify($e)) {
            LlmErrorKind::Configuration  => $this->configurationErrorMessage(),
            LlmErrorKind::Authentication => 'The LLM provider rejected the API key.'
                . ' An administrator should check the provider'
                . ' configuration in the LLM module.',
            LlmErrorKind::RateLimit => 'The LLM provider rate limit was exceeded.'
                . ' Please wait a moment and try again.',
            LlmErrorKind::Unknown => 'Translation failed.'
                . ' Check the TYPO3 system log for details.',
        };
    }

    /**
     * The configuration-missing message, enriched with the first failing setup
     * diagnostic when one is available.
     */
    private function configurationErrorMessage(): string
    {
        try {
            $failure = $this->diagnosticService->runFirst()->getFirstFailure();
        } catch (Throwable) {
            $failure = null;
        }

        if ($failure instanceof DiagnosticCheck) {
            return $failure->message
                . ' Ask an administrator to check the Cowriter Setup Status page for details.';
        }

        return 'Translation is not configured yet.'
            . ' Ask an administrator to check the Cowriter Setup Status page for details.';
    }

    /**
     * Whether the exception indicates a missing LLM configuration.
     */
    private function isConfigurationError(Throwable $exception): bool
    {
        return $this->errorClassifier->classify($exception) === LlmErrorKind::Configuration;
    }
}
