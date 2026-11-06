<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\T3Cowriter\Service\LlmErrorClassifier;
use Netresearch\T3Cowriter\Service\LlmErrorKind;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * What the field controls that create a content element next to the edited
 * one share (tabs or accordion, audience variant): the refusal answer, the
 * links to the new element, the message for a failed model call and the
 * session user.
 *
 * The using class provides the properties `$uriBuilder` (backend UriBuilder),
 * `$context` (TYPO3 Context), `$labels` (BackendLabels), `$rateLimiter`
 * (RateLimiterInterface) and `$errorClassifier` (LlmErrorClassifier), and
 * uses RateLimitedControllerTrait.
 *
 * @internal
 */
trait ContentElementActionTrait
{
    private const INVALID_REQUEST = 'Invalid request.';

    private const NO_BACKEND_USER = 'No backend user session.';

    /**
     * The checks a route that may call a model makes before anything else, so
     * a refused request costs nothing: the rate limit, the feature's admin
     * switch and a JSON body.
     *
     * @return array{RateLimitResult, array<string, mixed>}|ResponseInterface the rate limit result and the body, or the refusal
     */
    private function admitModelRequest(ServerRequestInterface $request, bool $enabled, string $disabledKey): array|ResponseInterface
    {
        $rateLimitResult = $this->rateLimiter->checkLimit((string) $this->userId());
        if (!$rateLimitResult->allowed) {
            return $this->rateLimitedResponse($rateLimitResult);
        }

        if (!$enabled) {
            return $this->failure($this->labels->get($disabledKey), $rateLimitResult, 403);
        }

        $body = $this->parseJsonBody($request);

        return $body === null ? $this->failure(self::INVALID_REQUEST, $rateLimitResult, 400) : [$rateLimitResult, $body];
    }

    private function failure(string $message, RateLimitResult $rateLimitResult, int $status): ResponseInterface
    {
        return $this->jsonResponseWithRateLimitHeaders(['success' => false, 'error' => $message], $rateLimitResult, $status);
    }

    /**
     * The edit form of the new element; closing it leads to $returnUrl.
     */
    private function editUrl(int $uid, string $returnUrl): string
    {
        $parameters = ['edit' => ['tt_content' => [$uid => 'edit']]];
        if ($returnUrl !== '') {
            $parameters['returnUrl'] = $returnUrl;
        }

        try {
            return (string) $this->uriBuilder->buildUriFromRoute('record_edit', $parameters);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * The Page module for the element's page and language, at the new
     * element: the page module renders each element with the id
     * "element-tt_content-<uid>" (TYPO3 13.4 and 14.3). 13.4 reads the
     * language from "language", 14.3 from "languages".
     */
    private function pageUrl(int $pageUid, int $languageId, int $uid): string
    {
        try {
            $url = (string) $this->uriBuilder->buildUriFromRoute(
                'web_layout',
                ['id' => $pageUid, 'language' => $languageId, 'languages' => [$languageId]],
            );
        } catch (Throwable) {
            return '';
        }

        return $url . '#element-tt_content-' . $uid;
    }

    /**
     * The editor-facing message for a failed model call; $fallbackKey names
     * the feature's own message for an unknown failure.
     */
    private function modelErrorMessage(Throwable $exception, string $fallbackKey): string
    {
        return match ($this->errorClassifier->classify($exception)) {
            LlmErrorKind::Configuration    => $this->labels->get('error.checkSetupStatus', $this->labels->get('error.notConfigured')),
            LlmErrorKind::Authentication   => $this->labels->get('error.providerAuthentication'),
            LlmErrorKind::RateLimit        => $this->labels->get('error.providerRateLimit'),
            LlmErrorKind::GuardrailRefusal => LlmErrorClassifier::guardrailReason($exception) ?? $this->labels->get($fallbackKey),
            LlmErrorKind::Unknown          => $this->labels->get($fallbackKey),
        };
    }

    private function userId(): int
    {
        $userId = $this->context->getPropertyFromAspect('backend.user', 'id', 0);

        return is_numeric($userId) ? (int) $userId : 0;
    }
}
