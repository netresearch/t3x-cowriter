<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\T3Cowriter\Domain\DTO\InlineSuggestionRequest;
use Netresearch\T3Cowriter\Service\BackendLabels;
use Netresearch\T3Cowriter\Service\BudgetRefusal;
use Netresearch\T3Cowriter\Service\CallerSource;
use Netresearch\T3Cowriter\Service\ConfigurationSelector;
use Netresearch\T3Cowriter\Service\InlineSuggestion\InlineSuggestionConfiguration;
use Netresearch\T3Cowriter\Service\InlineSuggestion\SuggestionText;
use Netresearch\T3Cowriter\Service\LlmErrorClassifier;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\Vision\RecordLanguageResolverInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Context\Context;

/**
 * AJAX controller for inline suggestions in the rich text editor: a short
 * continuation of the text before the cursor, shown as grey text the editor
 * accepts with Tab.
 *
 * Every refusal that does not need the model comes first — rate limit, the
 * admin switch, the request, the configuration — so a refused request costs
 * nothing. The rate limit has its own window per editor (see Services.yaml),
 * so typing cannot use up the limit of the other Cowriter actions.
 *
 * @internal
 */
final readonly class InlineSuggestionController
{
    use RateLimitedControllerTrait;

    /**
     * Prefix of the rate-limit key: a window of its own next to the other
     * actions' shared one.
     */
    public const RATE_LIMIT_PREFIX = 'inline-suggestion:';

    /**
     * Upper bound for the answer. One sentence needs far fewer; the bound
     * keeps a model that does not stop from costing more.
     */
    public const MAX_TOKENS = 60;

    public const TEMPERATURE = 0.3;

    public function __construct(
        private CompletionServiceInterface $completionService,
        private InlineSuggestionConfiguration $suggestionConfiguration,
        private ConfigurationSelector $configurationSelector,
        private RecordLanguageResolverInterface $languageResolver,
        private RateLimiterInterface $rateLimiter,
        private Context $context,
        private LoggerInterface $logger,
        private BackendLabels $labels = new BackendLabels(),
        private BudgetRefusal $budgetRefusal = new BudgetRefusal(),
    ) {}

    public function suggestAction(ServerRequestInterface $request): ResponseInterface
    {
        /** @var int|string $userId */
        $userId          = $this->context->getPropertyFromAspect('backend.user', 'id', 0);
        $rateLimitResult = $this->rateLimiter->checkLimit(self::RATE_LIMIT_PREFIX . $userId);

        if (!$rateLimitResult->allowed) {
            return $this->rateLimitedResponse($rateLimitResult);
        }

        if (!$this->suggestionConfiguration->isEnabled()) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get('error.inlineSuggestion.disabled')],
                $rateLimitResult,
                403,
            );
        }

        $body = $this->parseJsonBody($request);
        if ($body === null) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'Invalid JSON in request body.'],
                $rateLimitResult,
                400,
            );
        }

        $suggestionRequest = InlineSuggestionRequest::fromRequestBody($body);

        if ($suggestionRequest->isTooLong()) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get('error.inlineSuggestion.tooLong', InlineSuggestionRequest::MAX_TEXT_LENGTH)],
                $rateLimitResult,
                400,
            );
        }

        $minChars = $this->suggestionConfiguration->minChars();
        if ($suggestionRequest->isShorterThan($minChars)) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get('error.inlineSuggestion.tooShort', $minChars)],
                $rateLimitResult,
                400,
            );
        }

        // The configuration comes from the extension settings, never from the
        // request; without one, the default configuration answers.
        $selection     = $this->configurationSelector->trySelect($this->suggestionConfiguration->configuration());
        $configuration = $selection->configuration;
        if (!$configuration instanceof LlmConfiguration) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $this->labels->get($selection->errorLabel)],
                $rateLimitResult,
                $selection->status,
            );
        }

        try {
            $response = $this->completionService->completeForConfiguration(
                $suggestionRequest->text,
                $configuration,
                $this->options($this->language($suggestionRequest), (int) $userId),
            );
        } catch (Throwable $e) {
            $refusal = BudgetRefusal::find($e);
            if ($refusal instanceof BudgetCheckResult) {
                return $this->budgetRefusal->response($refusal, $rateLimitResult);
            }

            // The editor's text is not logged: it is the record's content.
            $this->logger->error('Inline suggestion failed', ['exception' => $e->getMessage()]);

            $reason = LlmErrorClassifier::guardrailReason($e);

            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => $reason ?? $this->labels->get('error.inlineSuggestion.failed'), 'guardrailStop' => $reason !== null],
                $rateLimitResult,
                502,
            );
        }

        return $this->jsonResponseWithRateLimitHeaders(
            [
                'success'    => true,
                'suggestion' => SuggestionText::clean($response->content, $suggestionRequest->text),
            ],
            $rateLimitResult,
        );
    }

    private function options(?string $language, int $userId): ChatOptions
    {
        return (new ChatOptions(
            temperature: self::TEMPERATURE,
            maxTokens: self::MAX_TOKENS,
            systemPrompt: SuggestionText::instruction($language),
            stopSequences: ["\n"],
        ))
            ->withBeUserUid($userId)
            ->withCallerSource(CallerSource::EXTENSION, 'inlineSuggestion');
    }

    /**
     * The record's language; null without a record or when the lookup fails,
     * so a failing lookup costs the hint, not the suggestion.
     */
    private function language(InlineSuggestionRequest $request): ?string
    {
        if ($request->recordContext === null) {
            return null;
        }

        try {
            return $this->languageResolver->languageName($request->recordContext['table'], $request->recordContext['uid']);
        } catch (Throwable $e) {
            $this->logger->warning('Inline suggestion: the language of the record could not be resolved', [
                'table'     => $request->recordContext['table'],
                'uid'       => $request->recordContext['uid'],
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
