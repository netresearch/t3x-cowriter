<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Budget;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\T3Cowriter\Controller\AjaxController;
use Netresearch\T3Cowriter\Controller\BudgetStatusController;
use Netresearch\T3Cowriter\Service\BudgetRefusal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The budget status route and the budget refusal as the container wires them,
 * against nr-llm's budget and usage tables.
 */
#[CoversClass(BudgetStatusController::class)]
#[CoversClass(BudgetRefusal::class)]
final class BudgetTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/budgets_and_users.csv');

        // Usage of today: the editor has used up the five requests of the day.
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_service_usage');
        foreach ([2 => 5, 3 => 2] as $beUser => $requests) {
            $connection->insert('tx_nrllm_service_usage', [
                'be_user'        => $beUser,
                'request_date'   => time(),
                'request_count'  => $requests,
                'tokens_used'    => 100 * $requests,
                'estimated_cost' => 0.01 * $requests,
            ]);
        }
    }

    #[Test]
    public function eachEditorReadsOnlyTheirOwnUsage(): void
    {
        $this->setUpBackendUser(2);
        $editor = $this->budgetStatus();

        self::assertTrue($editor['hasPersonalLimit']);
        self::assertSame(['used' => '5', 'limit' => '5', 'limited' => true, 'percent' => 100], $editor['today']['requests']);

        $this->setUpBackendUser(3);
        $colleague = $this->budgetStatus();

        self::assertFalse($colleague['hasPersonalLimit']);
        self::assertSame('2', $colleague['today']['requests']['used']);
        self::assertSame('200', $colleague['month']['tokens']['used']);
    }

    #[Test]
    public function theContainerGivesTheControllersARefusalThatCanAskTheBudget(): void
    {
        $refusal = (new ReflectionProperty(AjaxController::class, 'budgetRefusal'))->getValue($this->get(AjaxController::class));
        self::assertInstanceOf(BudgetRefusal::class, $refusal);

        $result = $refusal->check(2, null);

        self::assertInstanceOf(BudgetCheckResult::class, $result);
        self::assertSame(BudgetCheckResult::LIMIT_DAILY_REQUESTS, $result->exceededLimit);
        self::assertNull($refusal->check(3, null));
    }

    /**
     * @return array<string, mixed>
     */
    private function budgetStatus(): array
    {
        $response = $this->get(BudgetStatusController::class)
            ->statusAction(new ServerRequest('https://example.com/typo3/ajax/cowriter/budget-status', 'GET'));
        $data = $this->json($response);
        self::assertTrue($data['success']);
        self::assertIsArray($data['status']);

        return $data['status'];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
