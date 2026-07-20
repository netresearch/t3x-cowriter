<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\NrLlm\Service\Feature\VisionServiceInterface;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Netresearch\T3Cowriter\Domain\DTO\VisionRequest;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Context\Context;

/**
 * AJAX controller for image analysis via nr-llm VisionService.
 *
 * @internal
 */
final readonly class VisionController
{
    use RateLimitedControllerTrait;

    public function __construct(
        private VisionServiceInterface $visionService,
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
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'Invalid JSON in request body.'],
                $rateLimitResult,
                400,
            );
        }

        $visionRequest = VisionRequest::fromRequestBody($body);

        if (!$visionRequest->isValid()) {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'Invalid request: fields exceed maximum length.'],
                $rateLimitResult,
                400,
            );
        }

        if ($visionRequest->imageUrl === '') {
            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'Missing or empty imageUrl parameter.'],
                $rateLimitResult,
                400,
            );
        }

        try {
            // altText() preset (detail 'low', maxTokens 100, temperature 0.5)
            // matches this alt-text endpoint; analyzeImageFull() still returns the
            // model/confidence/usage metadata the response surfaces.
            $options  = VisionOptions::altText()->withBeUserUid((int) $userId);
            $response = $this->visionService->analyzeImageFull(
                $visionRequest->imageUrl,
                $visionRequest->prompt,
                $options,
            );

            return $this->jsonResponseWithRateLimitHeaders([
                'success'    => true,
                'altText'    => $response->description,
                'model'      => $response->model,
                'confidence' => $response->confidence,
                'usage'      => [
                    'promptTokens'     => $response->usage->promptTokens,
                    'completionTokens' => $response->usage->completionTokens,
                    'totalTokens'      => $response->usage->totalTokens,
                ],
            ], $rateLimitResult);
        } catch (Throwable $e) {
            $this->logger->error('Vision analysis failed', [
                'exception' => $e->getMessage(),
                'imageUrl'  => substr($visionRequest->imageUrl, 0, 100),
            ]);

            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'Image analysis failed. Please try again.'],
                $rateLimitResult,
                500,
            );
        }
    }
}
