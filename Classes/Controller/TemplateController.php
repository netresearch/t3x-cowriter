<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Context\Context;

/**
 * AJAX controller for prompt template management.
 *
 * Exposes available prompt templates for the CKEditor cowriter dialog.
 *
 * @internal
 */
final readonly class TemplateController
{
    use RateLimitedControllerTrait;

    public function __construct(
        private TaskRepository $templateRepository,
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
                if (!$template instanceof Task) {
                    continue;
                }

                $result[] = [
                    'identifier'  => $template->getIdentifier(),
                    'name'        => $template->getName(),
                    'description' => $template->getDescription(),
                    'category'    => $template->getCategory(),
                ];
            }

            return $this->jsonResponseWithRateLimitHeaders([
                'success'   => true,
                'templates' => $result,
            ], $rateLimitResult);
        } catch (Throwable $e) {
            $this->logger->error('Failed to list prompt templates', [
                'exception' => $e->getMessage(),
            ]);

            return $this->jsonResponseWithRateLimitHeaders(
                ['success' => false, 'error' => 'Failed to load templates.'],
                $rateLimitResult,
                500,
            );
        }
    }
}
