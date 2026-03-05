<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\NrLlm\Domain\Model\PromptTemplate;
use Netresearch\NrLlm\Domain\Repository\PromptTemplateRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
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
        private LoggerInterface $logger,
    ) {}

    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
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
}
