<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use Netresearch\NrLlm\Service\Budget\UserBudgetStatusService;
use Netresearch\NrLlm\Service\Formatting\LocalizedNumberFormatter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * The logged-in editor's own AI usage today and this month against the limits
 * of their nr-llm budget, for the Cowriter dialog.
 *
 * The user is the one of the session; the request names none, so the route
 * cannot read anybody else's numbers. Numbers come formatted in the user's
 * locale, as nr-llm's AI Tasks module shows them.
 *
 * @internal
 */
final readonly class BudgetStatusController
{
    public function __construct(
        private UserBudgetStatusService $budgetStatus,
        private Context $context,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param ServerRequestInterface $request Required by TYPO3 AJAX action signature
     */
    public function statusAction(ServerRequestInterface $request): ResponseInterface
    {
        /** @var int|string $userId */
        $userId = $this->context->getPropertyFromAspect('backend.user', 'id', 0);

        try {
            $status = $this->budgetStatus->forUser((int) $userId, LocalizedNumberFormatter::forBackendUser());
        } catch (Throwable $e) {
            $this->logger->error('Budget status could not be read', ['exception' => $e->getMessage()]);

            return new JsonResponse(['success' => false], 500);
        }

        return new JsonResponse(['success' => true, 'status' => $status->toArray()]);
    }
}
