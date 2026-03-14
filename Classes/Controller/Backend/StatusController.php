<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller\Backend;

use Netresearch\T3Cowriter\Service\DiagnosticService;
use Netresearch\T3Cowriter\Service\Dto\DiagnosticCheck;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;

/**
 * Backend module showing LLM configuration status for the Cowriter extension.
 *
 * @internal
 */
final readonly class StatusController
{
    public function __construct(
        private DiagnosticService $diagnosticService,
        private BackendUriBuilder $backendUriBuilder,
        private ModuleTemplateFactory $moduleTemplateFactory,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $result  = $this->diagnosticService->runAll();
        $fixUrls = $this->buildFixUrls($result->checks);

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->assignMultiple([
            'result'  => $result,
            'checks'  => $result->checks,
            'fixUrls' => $fixUrls,
        ]);

        return $moduleTemplate->renderResponse('Backend/Status/Index');
    }

    /**
     * Build backend URLs for fix links.
     *
     * @param list<DiagnosticCheck> $checks
     *
     * @return array<string, string>
     */
    private function buildFixUrls(array $checks): array
    {
        $urls = [];

        foreach ($checks as $check) {
            if ($check->fixRoute === null) {
                continue;
            }

            if (isset($urls[$check->fixRoute])) {
                continue;
            }

            try {
                $urls[$check->fixRoute] = (string) $this->backendUriBuilder
                    ->buildUriFromRoute($check->fixRoute);
            } catch (Throwable) {
                // Route not available — skip
            }
        }

        return $urls;
    }
}
