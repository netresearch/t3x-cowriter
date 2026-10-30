<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\T3Cowriter\Domain\DTO\FieldOverviewRequest;
use Netresearch\T3Cowriter\Service\BackendLabels;
use Netresearch\T3Cowriter\Service\BudgetRefusal;
use Netresearch\T3Cowriter\Service\ConfigurationSelector;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldKind;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldOverviewService;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldProfile;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldReview;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionException;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContextReader;
use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use Netresearch\T3Cowriter\Service\LlmErrorClassifier;
use Netresearch\T3Cowriter\Service\LlmErrorKind;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\Transparency\AboutBuilder;
use Netresearch\T3Cowriter\Service\Vision\RecordLanguageResolverInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;

/**
 * AJAX endpoint behind the "Suggest values for all fields" button: one
 * suggestion or "keep", each with a reason, for every suggestion field of one
 * record, from a single model call. Nothing is written — the editor applies
 * the values he checks into the form and saves the record himself.
 *
 * The request counts once against the rate limit and is one call against the
 * nr-llm budget, however many fields it covers.
 */
final readonly class FieldOverviewController
{
    use RateLimitedControllerTrait;

    /**
     * nr-llm's structured completion: the answer still did not match the
     * schema after its repair round-trip.
     */
    private const SCHEMA_MISMATCH_CODES = [1784500001, 1784500002];

    public function __construct(
        private RecordContextReader $recordContextReader,
        private FieldOverviewService $fieldOverviewService,
        private ConfigurationSelector $configurationSelector,
        private RateLimiterInterface $rateLimiter,
        private RecordLanguageResolverInterface $languageResolver,
        private Context $context,
        private LoggerInterface $logger,
        private LlmErrorClassifier $errorClassifier = new LlmErrorClassifier(),
        private BackendLabels $labels = new BackendLabels(),
        private BudgetRefusal $budgetRefusal = new BudgetRefusal(),
        private ?AboutBuilder $about = null,
    ) {}

    public function suggestAllAction(ServerRequestInterface $request): ResponseInterface
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

        $dto = FieldOverviewRequest::fromArray($body);
        if (!$dto->isValid()) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'Table and fields are required.'],
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
            $read     = $this->recordContextReader->readFields($dto->table, array_keys($dto->fields), $dto->uid, $dto->pid, $backendUser);
            $context  = $read['context'];
            $profiles = [];
            foreach ($read['fields'] as $field) {
                // The reader has verified that the column exists.
                $profile = FieldProfile::fromTca($dto->table, $field, Tca::column($dto->table, $field) ?? []);
                // The root page of a site always has the slug "/".
                if ($profile->kind !== FieldKind::Slug || !$context->isSiteRoot()) {
                    $profiles[$field] = $profile;
                }
            }

            if ($profiles === []) {
                throw FieldSuggestionException::siteRootSlug();
            }
        } catch (FieldSuggestionException $e) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get($e->getLabelKey())],
                $rateLimitResult,
                $e->getHttpStatus(),
            );
        }

        $selection     = $this->configurationSelector->trySelect(null);
        $configuration = $selection->configuration;
        if (!$configuration instanceof LlmConfiguration) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get($selection->errorLabel)],
                $rateLimitResult,
                $selection->status,
            );
        }

        try {
            $result = $this->fieldOverviewService->review(
                $context,
                $profiles,
                $dto->fields,
                $configuration,
                $dto->uid > 0 ? $this->languageResolver->languageName($dto->table, $dto->uid) : null,
            );
        } catch (Throwable $e) {
            $refusal = BudgetRefusal::find($e);
            if ($refusal instanceof BudgetCheckResult) {
                return $this->budgetRefusal->response($refusal, $rateLimitResult);
            }

            // Provider messages can carry request details: log them, never return them.
            $this->logger->error('Field overview failed', ['exception' => $e->getMessage()]);

            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->errorMessage($e)],
                $rateLimitResult,
                502,
            );
        }

        $reviews = $result->items;
        if ($reviews === []) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get('fieldSuggestions.error.noSuggestions')],
                $rateLimitResult,
                502,
            );
        }

        $payload = [
            'success' => true,
            'fields'  => array_map(
                static fn (FieldReview $review): array => [
                    'field'     => $review->field,
                    'label'     => $profiles[$review->field]->label,
                    'kind'      => $profiles[$review->field]->kind->value,
                    'maxLength' => $profiles[$review->field]->maxLength,
                    'current'   => $dto->fields[$review->field] ?? '',
                    'action'    => $review->action,
                    'value'     => $review->value,
                    'reason'    => $review->reason,
                ],
                $reviews,
            ),
        ];
        if ($this->about instanceof AboutBuilder) {
            $payload['about'] = $this->about->fieldOverview(
                $result->modelUse,
                $result->sources,
                $this->recordContextReader->suggestionFields($dto->table, $backendUser),
                $rateLimitResult->limit,
                $backendUser,
            );
        }

        return $this->jsonResponseWithRateLimitHeaders($payload, $rateLimitResult);
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
