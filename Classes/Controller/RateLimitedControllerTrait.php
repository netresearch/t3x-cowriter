<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use JsonException;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Shared request-parsing and rate-limit response plumbing for the cowriter
 * AJAX controllers. These helpers were byte-identical copies across the
 * controllers; keeping them in one trait removes the duplication (and the
 * SonarCloud new-code duplication it triggered).
 *
 * A controller that needs a different rate-limited body (e.g. AjaxController,
 * which serialises a CompleteResponse) simply declares its own
 * {@see self::rateLimitedResponse()} — a class method overrides the trait's.
 */
trait RateLimitedControllerTrait
{
    /**
     * Parse the JSON request body, returning null on malformed input and an
     * empty array on an empty body.
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
     * Create a JSON response carrying the current rate-limit headers.
     *
     * @param array<string, mixed> $data
     */
    private function jsonResponseWithRateLimitHeaders(
        array $data,
        RateLimitResult $rateLimitResult,
        int $statusCode = 200,
    ): JsonResponse {
        return $this->addRateLimitHeaders(new JsonResponse($data, $statusCode), $rateLimitResult);
    }

    /**
     * The standard 429 response for a denied request, including rate-limit and
     * Retry-After headers.
     */
    private function rateLimitedResponse(RateLimitResult $result): JsonResponse
    {
        $response = new JsonResponse(
            ['success' => false, 'error' => 'Rate limit exceeded. Please try again later.'],
            429,
        );

        return $this->addRateLimitHeaders($response, $result)
            ->withAddedHeader('Retry-After', (string) $result->getRetryAfter());
    }

    /**
     * Copy the rate-limit headers from the result onto a response.
     */
    private function addRateLimitHeaders(JsonResponse $response, RateLimitResult $result): JsonResponse
    {
        foreach ($result->getHeaders() as $name => $value) {
            $response = $response->withAddedHeader($name, $value);
        }

        return $response;
    }
}
