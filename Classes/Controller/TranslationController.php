<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use JsonException;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\T3Cowriter\Domain\DTO\TranslationRequest;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * AJAX controller for content translation via nr-llm TranslationService.
 *
 * @internal
 */
final readonly class TranslationController
{
    public function __construct(
        private TranslationService $translationService,
        private RateLimiterInterface $rateLimiter,
        private Context $context,
        private LoggerInterface $logger,
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
            $options = new TranslationOptions(
                formality: $translationRequest->formality,
                domain: $translationRequest->domain,
            );

            $result = $this->translationService->translate(
                $translationRequest->text,
                $translationRequest->targetLanguage,
                $translationRequest->configuration,
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

            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'Translation failed. Please try again.'],
                $rateLimitResult,
                500,
            );
        }
    }

    /**
     * Parse JSON body from request, returning null on failure.
     *
     * @return array<string, mixed>|null
     */
    private function parseJsonBody(ServerRequestInterface $request): ?array
    {
        $rawBody = (string) $request->getBody();
        if ($rawBody === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
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

    private function rateLimitedResponse(RateLimitResult $result): JsonResponse
    {
        $response = new JsonResponse(
            ['success' => false, 'error' => 'Rate limit exceeded. Please try again later.'],
            429,
        );

        foreach ($result->getHeaders() as $name => $value) {
            $response = $response->withAddedHeader($name, $value);
        }

        return $response->withAddedHeader('Retry-After', (string) $result->getRetryAfter());
    }
}
