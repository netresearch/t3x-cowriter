<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\T3Cowriter\Domain\DTO\FieldSuggestionRequest;
use Netresearch\T3Cowriter\Service\BackendLabels;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldKind;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldProfile;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionException;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionService;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContextReader;
use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use Netresearch\T3Cowriter\Service\LlmErrorClassifier;
use Netresearch\T3Cowriter\Service\LlmErrorKind;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;

/**
 * AJAX endpoint behind the "Suggest" field control: N suggestions for one
 * field of one record. Nothing is written — the editor picks a suggestion
 * into the form and saves the record himself.
 */
final readonly class FieldSuggestionController
{
    use RateLimitedControllerTrait;

    /**
     * nr-llm's structured completion: the answer still did not match the
     * schema after its repair round-trip (completeStructured() and the
     * *ForConfiguration() variant).
     */
    private const SCHEMA_MISMATCH_CODES = [1784500001, 1784500002];

    public function __construct(
        private RecordContextReader $recordContextReader,
        private FieldSuggestionService $fieldSuggestionService,
        private LlmConfigurationRepository $configurationRepository,
        private RateLimiterInterface $rateLimiter,
        private Context $context,
        private LoggerInterface $logger,
        private LlmErrorClassifier $errorClassifier = new LlmErrorClassifier(),
        private BackendLabels $labels = new BackendLabels(),
    ) {}

    public function suggestAction(ServerRequestInterface $request): ResponseInterface
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

        $dto = FieldSuggestionRequest::fromArray($body);
        if (!$dto->isValid()) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'Table and field are required.'],
                $rateLimitResult,
                400,
            );
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'No backend user session.'],
                $rateLimitResult,
                403,
            );
        }

        try {
            $recordContext = $this->recordContextReader->read($dto, $backendUser);

            // The reader has verified that the column exists.
            $profile = FieldProfile::fromTca($dto->table, $dto->field, Tca::column($dto->table, $dto->field) ?? []);

            if ($profile->kind === FieldKind::Slug && $recordContext->isSiteRoot()) {
                throw FieldSuggestionException::siteRootSlug();
            }
        } catch (FieldSuggestionException $e) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get($e->getLabelKey())],
                $rateLimitResult,
                $e->getHttpStatus(),
            );
        }

        $configuration = $this->configurationRepository->findDefault();
        if (!$configuration instanceof LlmConfiguration) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get('error.noConfiguration')],
                $rateLimitResult,
                404,
            );
        }

        try {
            $suggestions = $this->fieldSuggestionService->suggest(
                $recordContext,
                $profile,
                $dto->currentValue,
                // The field control's configured count is the upper bound.
                min($dto->count, $recordContext->maxCount),
                $configuration,
            );
        } catch (Throwable $e) {
            // Provider messages can carry request details: log them, never return them.
            $this->logger->error('Field suggestion failed', ['exception' => $e->getMessage()]);

            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->errorMessage($e)],
                $rateLimitResult,
                502,
            );
        }

        if ($suggestions === []) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get('fieldSuggestions.error.noSuggestions')],
                $rateLimitResult,
                502,
            );
        }

        return $this->jsonResponseWithRateLimitHeaders(
            [
                'success'     => true,
                'suggestions' => $suggestions,
            ],
            $rateLimitResult,
        );
    }

    /**
     * The editor-facing message for a failed LLM call.
     */
    private function errorMessage(Throwable $exception): string
    {
        $kind = $this->errorClassifier->classify($exception);

        if ($kind === LlmErrorKind::Unknown
            && $exception instanceof InvalidArgumentException
            && in_array($exception->getCode(), self::SCHEMA_MISMATCH_CODES, true)
        ) {
            // nr-llm's structured completion: the answer did not match the
            // schema even after its repair round-trip.
            return $this->labels->get('fieldSuggestions.error.invalidFormat');
        }

        return match ($kind) {
            LlmErrorKind::Configuration  => $this->labels->get('error.checkSetupStatus', $this->labels->get('error.notConfigured')),
            LlmErrorKind::Authentication => $this->labels->get('error.providerAuthentication'),
            LlmErrorKind::RateLimit      => $this->labels->get('error.providerRateLimit'),
            LlmErrorKind::Unknown        => $this->labels->get('fieldSuggestions.error.unknown'),
        };
    }
}
