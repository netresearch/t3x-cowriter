<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use JsonException;
use Netresearch\NrLlm\Service\Feature\VisionService;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Netresearch\T3Cowriter\Domain\DTO\VisionRequest;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * AJAX controller for image analysis via nr-llm VisionService.
 *
 * @internal
 */
final readonly class VisionController
{
    public function __construct(
        private VisionService $visionService,
        private RateLimiterInterface $rateLimiter,
        private Context $context,
        private LoggerInterface $logger,
    ) {}

    public function analyzeAction(ServerRequestInterface $request): ResponseInterface
    {
        /** @var int|string $userId */
        $userId          = $this->context->getPropertyFromAspect('backend.user', 'id', 0);
        $rateLimitResult = $this->rateLimiter->checkLimit((string) $userId);

        if (!$rateLimitResult->allowed) {
            return $this->rateLimitedResponse($rateLimitResult);
        }

        $body = $this->parseJsonBody($request);
        if ($body === null) {
            return new JsonResponse(
                ['success' => false, 'error' => 'Invalid JSON in request body.'],
                400,
            );
        }

        $visionRequest = VisionRequest::fromRequestBody($body);

        if (!$visionRequest->isValid()) {
            return new JsonResponse(
                ['success' => false, 'error' => 'Invalid request: fields exceed maximum length.'],
                400,
            );
        }

        if ($visionRequest->imageUrl === '') {
            return new JsonResponse(
                ['success' => false, 'error' => 'Missing or empty imageUrl parameter.'],
                400,
            );
        }

        try {
            $options  = new VisionOptions();
            $response = $this->visionService->analyzeImageFull(
                $visionRequest->imageUrl,
                $visionRequest->prompt,
                $options,
            );

            return new JsonResponse([
                'success'    => true,
                'altText'    => $response->description,
                'model'      => $response->model,
                'confidence' => $response->confidence,
                'usage'      => [
                    'promptTokens'     => $response->usage->promptTokens,
                    'completionTokens' => $response->usage->completionTokens,
                    'totalTokens'      => $response->usage->totalTokens,
                ],
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Vision analysis failed', [
                'exception' => $e->getMessage(),
                'imageUrl'  => substr($visionRequest->imageUrl, 0, 100),
            ]);

            return new JsonResponse(
                ['success' => false, 'error' => 'Image analysis failed. Please try again.'],
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
