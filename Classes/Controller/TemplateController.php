<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\NrLlm\Domain\Model\PromptTemplate;
use Netresearch\NrLlm\Domain\Repository\PromptTemplateRepository;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * AJAX controller for prompt template management.
 *
 * Exposes available prompt templates for the CKEditor cowriter dialog.
 *
 * @internal
 */
final readonly class TemplateController
{
    public function __construct(
        private PromptTemplateRepository $templateRepository,
        private RateLimiterInterface $rateLimiter,
        private Context $context,
        private LoggerInterface $logger,
    ) {}

    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        /** @var int|string $userId */
        $userId          = $this->context->getPropertyFromAspect('backend.user', 'id', 0);
        $rateLimitResult = $this->rateLimiter->checkLimit((string) $userId);

        if (!$rateLimitResult->allowed) {
            return $this->rateLimitedResponse($rateLimitResult);
        }

        try {
            $templates = $this->templateRepository->findActive();
            $result    = [];

            foreach ($templates as $template) {
                if (!$template instanceof PromptTemplate) {
                    continue;
                }

                $result[] = [
                    'identifier'  => $template->getIdentifier(),
                    'name'        => $template->getTitle(),
                    'description' => $template->getDescription() ?? '',
                    'category'    => $template->getFeature(),
                ];
            }

            return new JsonResponse([
                'success'   => true,
                'templates' => $result,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to list prompt templates', [
                'exception' => $e->getMessage(),
            ]);

            return new JsonResponse(
                ['success' => false, 'error' => 'Failed to load templates.'],
                500,
            );
        }
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
