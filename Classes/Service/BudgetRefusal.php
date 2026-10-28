<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * The answer to a call nr-llm's budget refused: HTTP 429 and a message in the
 * editor's language that names the limit that is used up.
 *
 * nr-llm refuses a call over budget with a {@see BudgetExceededException}
 * whose {@see BudgetCheckResult} names the limit as a machine key; its
 * `reason` is English and is not shown. 429, not 403: the editor's client
 * offers no "Open LLM Settings" action for a 429, and a 403 on these routes
 * means a missing backend session.
 *
 * The tool loop does not throw: it ends with `BUDGET_EXHAUSTED` and drops the
 * result. {@see self::check()} asks the budget again for the limit then.
 */
final readonly class BudgetRefusal
{
    public const HTTP_STATUS = 429;

    /**
     * The locallang_be.xlf id naming each limit nr-llm reports.
     */
    private const LIMIT_LABELS = [
        BudgetCheckResult::LIMIT_DAILY_REQUESTS               => 'budget.limit.daily_requests',
        BudgetCheckResult::LIMIT_DAILY_TOKENS                 => 'budget.limit.daily_tokens',
        BudgetCheckResult::LIMIT_DAILY_COST                   => 'budget.limit.daily_cost',
        BudgetCheckResult::LIMIT_MONTHLY_REQUESTS             => 'budget.limit.monthly_requests',
        BudgetCheckResult::LIMIT_MONTHLY_TOKENS               => 'budget.limit.monthly_tokens',
        BudgetCheckResult::LIMIT_MONTHLY_COST                 => 'budget.limit.monthly_cost',
        BudgetCheckResult::LIMIT_CONFIGURATION_DAILY_REQUESTS => 'budget.limit.configuration_daily_requests',
        BudgetCheckResult::LIMIT_CONFIGURATION_DAILY_TOKENS   => 'budget.limit.configuration_daily_tokens',
        BudgetCheckResult::LIMIT_CONFIGURATION_DAILY_COST     => 'budget.limit.configuration_daily_cost',
    ];

    public function __construct(
        // Optional so manual constructions keep working; without it a refusal
        // the tool loop reports names no limit. The container injects it.
        private ?BudgetServiceInterface $budgetService = null,
        private BackendLabels $labels = new BackendLabels(),
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * The budget refusal behind a failure, also when another exception wraps
     * it; null for any other failure.
     */
    public static function find(Throwable $error): ?BudgetCheckResult
    {
        for ($current = $error; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof BudgetExceededException) {
                return $current->result;
            }
        }

        return null;
    }

    /**
     * What the budget says about a call of this user with this configuration
     * now, as nr-llm's pre-flight asks it; null when it allows the call or no
     * budget service is available.
     */
    public function check(int $beUserUid, ?LlmConfiguration $configuration): ?BudgetCheckResult
    {
        $result = $this->budgetService?->check($beUserUid, 0.0, $configuration);

        return $result instanceof BudgetCheckResult && !$result->allowed ? $result : null;
    }

    /**
     * The refusal as JSON response, with the rate-limit headers of the request.
     */
    public function response(?BudgetCheckResult $result, RateLimitResult $rateLimitResult): JsonResponse
    {
        $response = new JsonResponse($this->payload($result), self::HTTP_STATUS);
        foreach ($rateLimitResult->getHeaders() as $name => $value) {
            $response = $response->withAddedHeader($name, $value);
        }

        return $response;
    }

    /**
     * The JSON body of a refusal. `budgetExceeded` tells the client it is a
     * refusal and not a failure; `exceededLimit` is nr-llm's key, empty when
     * the limit is not known.
     *
     * @return array{success: false, error: string, budgetExceeded: true, exceededLimit: string}
     */
    public function payload(?BudgetCheckResult $result): array
    {
        $this->logger?->notice('nr-llm budget refused the call', [
            'exceededLimit' => $result instanceof BudgetCheckResult ? $result->exceededLimit : '',
        ]);

        return [
            'success'        => false,
            'error'          => $this->message($result),
            'budgetExceeded' => true,
            'exceededLimit'  => $result instanceof BudgetCheckResult ? $result->exceededLimit : BudgetCheckResult::LIMIT_NONE,
        ];
    }

    /**
     * The editor-facing message: the limit by name, or without one when the
     * key is unknown or the limit could not be read.
     */
    public function message(?BudgetCheckResult $result): string
    {
        $limitLabel = $result instanceof BudgetCheckResult ? (self::LIMIT_LABELS[$result->exceededLimit] ?? null) : null;

        return $limitLabel === null
            ? $this->labels->get('error.budgetExceeded')
            : $this->labels->get('error.budgetExceeded.limit', $this->labels->get($limitLabel));
    }
}
