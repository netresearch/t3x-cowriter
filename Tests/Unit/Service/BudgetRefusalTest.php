<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\T3Cowriter\Service\BudgetRefusal;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Tests\Support\XliffLanguageServiceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

#[CoversClass(BudgetRefusal::class)]
final class BudgetRefusalTest extends TestCase
{
    use XliffLanguageServiceTrait;

    /** @var list<mixed> */
    private array $loggedLimits = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useXliffLanguageService();
    }

    #[Test]
    public function aRefusalIsFoundDirectlyAndInsideOtherExceptions(): void
    {
        $result  = BudgetCheckResult::denied(BudgetCheckResult::LIMIT_DAILY_COST, 2.0, 2.0);
        $refusal = new BudgetExceededException($result);

        self::assertSame($result, BudgetRefusal::find($refusal));
        self::assertSame($result, BudgetRefusal::find(new RuntimeException('outer', 0, new RuntimeException('middle', 0, $refusal))));
        self::assertNull(BudgetRefusal::find(new RuntimeException('no budget here', 0, new RuntimeException('nor here'))));
    }

    #[Test]
    #[DataProvider('limits')]
    public function theMessageNamesEveryLimitNrLlmReports(string $limit, string $english, string $german): void
    {
        $result = BudgetCheckResult::denied($limit, 1.0, 1.0);

        self::assertSame('The request was refused. This limit is used up: ' . $english . '.', (new BudgetRefusal())->message($result));

        $this->useXliffLanguageService('de');
        self::assertSame('Die Anfrage wurde abgelehnt. Dieses Limit ist aufgebraucht: ' . $german . '.', (new BudgetRefusal())->message($result));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function limits(): iterable
    {
        yield 'daily requests' => [BudgetCheckResult::LIMIT_DAILY_REQUESTS, 'your daily number of requests', 'Ihre tägliche Anzahl an Anfragen'];

        yield 'daily tokens' => [BudgetCheckResult::LIMIT_DAILY_TOKENS, 'your daily number of tokens', 'Ihre tägliche Anzahl an Tokens'];

        yield 'daily cost' => [BudgetCheckResult::LIMIT_DAILY_COST, 'your daily cost', 'Ihre täglichen Kosten'];

        yield 'monthly requests' => [BudgetCheckResult::LIMIT_MONTHLY_REQUESTS, 'your monthly number of requests', 'Ihre monatliche Anzahl an Anfragen'];

        yield 'monthly tokens' => [BudgetCheckResult::LIMIT_MONTHLY_TOKENS, 'your monthly number of tokens', 'Ihre monatliche Anzahl an Tokens'];

        yield 'monthly cost' => [BudgetCheckResult::LIMIT_MONTHLY_COST, 'your monthly cost', 'Ihre monatlichen Kosten'];

        yield 'configuration daily requests' => [
            BudgetCheckResult::LIMIT_CONFIGURATION_DAILY_REQUESTS,
            'the daily number of requests of this configuration, shared by all users',
            'die tägliche Anzahl an Anfragen dieser Konfiguration, gemeinsam für alle Benutzer',
        ];

        yield 'configuration daily tokens' => [
            BudgetCheckResult::LIMIT_CONFIGURATION_DAILY_TOKENS,
            'the daily number of tokens of this configuration, shared by all users',
            'die tägliche Anzahl an Tokens dieser Konfiguration, gemeinsam für alle Benutzer',
        ];

        yield 'configuration daily cost' => [
            BudgetCheckResult::LIMIT_CONFIGURATION_DAILY_COST,
            'the daily cost of this configuration, shared by all users',
            'die täglichen Kosten dieser Konfiguration, gemeinsam für alle Benutzer',
        ];
    }

    #[Test]
    public function everyLimitNrLlmDefinesHasAName(): void
    {
        $limits = array_filter(
            (new ReflectionClass(BudgetCheckResult::class))->getConstants(),
            static fn (mixed $value, string $name): bool => str_starts_with($name, 'LIMIT_') && is_string($value) && $value !== BudgetCheckResult::LIMIT_NONE,
            ARRAY_FILTER_USE_BOTH,
        );

        self::assertNotSame([], $limits);
        foreach ($limits as $limit) {
            self::assertIsString($limit);
            self::assertStringStartsWith(
                'The request was refused. This limit is used up: ',
                (new BudgetRefusal())->message(BudgetCheckResult::denied($limit, 1.0, 1.0)),
                $limit,
            );
        }
    }

    #[Test]
    public function anUnknownOrMissingLimitGetsTheMessageWithoutName(): void
    {
        $generic = 'The request was refused because a limit of your AI budget is used up.';

        self::assertSame($generic, (new BudgetRefusal())->message(BudgetCheckResult::denied('a_future_limit', 1.0, 1.0)));
        self::assertSame($generic, (new BudgetRefusal())->message(null));
        // nr-llm's English reason is never shown.
        self::assertStringNotContainsString('AI budget exhausted', (new BudgetRefusal())->message(BudgetCheckResult::denied('a_future_limit', 1.0, 1.0)));
    }

    #[Test]
    public function theResponseIsA429WithTheFlagTheLimitAndTheRateLimitHeaders(): void
    {
        $response = (new BudgetRefusal())->response(
            BudgetCheckResult::denied(BudgetCheckResult::LIMIT_MONTHLY_TOKENS, 1.0, 1.0),
            new RateLimitResult(true, 20, 19, time() + 60),
        );

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('19', $response->getHeaderLine('X-RateLimit-Remaining'));
        self::assertSame([
            'success'        => false,
            'error'          => 'The request was refused. This limit is used up: your monthly number of tokens.',
            'budgetExceeded' => true,
            'exceededLimit'  => BudgetCheckResult::LIMIT_MONTHLY_TOKENS,
        ], json_decode((string) $response->getBody(), true));
    }

    #[Test]
    public function aRefusalIsLoggedWithItsLimit(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('notice')
            ->willReturnCallback(function (string $message, array $context): void {
                self::assertSame('nr-llm budget refused the call', $message);
                $this->loggedLimits[] = $context['exceededLimit'];
            });
        $refusal = new BudgetRefusal(logger: $logger);

        $refusal->payload(BudgetCheckResult::denied(BudgetCheckResult::LIMIT_DAILY_TOKENS, 1.0, 1.0));
        $refusal->payload(null);

        self::assertSame([BudgetCheckResult::LIMIT_DAILY_TOKENS, ''], $this->loggedLimits);
    }

    #[Test]
    public function checkAsksTheBudgetAsNrLlmsPreflightDoesAndReturnsOnlyADenial(): void
    {
        $configuration = new LlmConfiguration();
        $denied        = BudgetCheckResult::denied(BudgetCheckResult::LIMIT_DAILY_TOKENS, 1.0, 1.0);

        $budget = $this->createMock(BudgetServiceInterface::class);
        $budget->expects(self::exactly(2))->method('check')
            ->with(5, 0.0, $configuration)
            ->willReturnOnConsecutiveCalls($denied, BudgetCheckResult::allowed());

        $refusal = new BudgetRefusal($budget);
        self::assertSame($denied, $refusal->check(5, $configuration));
        self::assertNull($refusal->check(5, $configuration));
        self::assertNull((new BudgetRefusal())->check(5, $configuration));
    }
}
