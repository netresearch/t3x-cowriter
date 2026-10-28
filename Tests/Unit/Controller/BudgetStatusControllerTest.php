<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Controller;

use Netresearch\NrLlm\Domain\Model\UserBudget;
use Netresearch\NrLlm\Domain\Repository\UserBudgetRepository;
use Netresearch\NrLlm\Service\Budget\BudgetUsageWindowsInterface;
use Netresearch\NrLlm\Service\Budget\UserBudgetStatusService;
use Netresearch\T3Cowriter\Controller\BudgetStatusController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\Locale;

#[CoversClass(BudgetStatusController::class)]
final class BudgetStatusControllerTest extends TestCase
{
    private const SESSION_USER = 7;

    /** @var list<int> the users whose usage was read */
    private array $readUsers = [];

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function theStatusIsTheOneOfTheSessionUserWhateverTheRequestNames(): void
    {
        $budget = new UserBudget();
        $budget->setBeUser(self::SESSION_USER);
        $budget->setIsActive(true);
        $budget->setMaxRequestsPerDay(50);

        $request = (new ServerRequest('https://example.com/typo3/ajax/cowriter/budget-status', 'GET'))
            ->withQueryParams(['uid' => '3', 'beUser' => '3'])
            ->withParsedBody(['uid' => 3]);

        $data = $this->json($this->subject($budget)->statusAction($request));

        self::assertSame([self::SESSION_USER], $this->readUsers);
        self::assertTrue($data['success']);
        self::assertTrue($data['status']['hasPersonalLimit']);
        self::assertSame(['used' => '12', 'limit' => '50', 'limited' => true, 'percent' => 24], $data['status']['today']['requests']);
    }

    #[Test]
    public function withoutABudgetRecordTheStatusSaysNoPersonalLimit(): void
    {
        $data = $this->json($this->subject(null)->statusAction(new ServerRequest('https://example.com/', 'GET')));

        self::assertFalse($data['status']['hasPersonalLimit']);
        self::assertFalse($data['status']['month']['tokens']['limited']);
        self::assertSame('3,456', $data['status']['month']['tokens']['used']);
    }

    #[Test]
    public function numbersAreFormattedInTheLocaleOfTheBackendUser(): void
    {
        $languageService = $this->createStub(LanguageService::class);
        $languageService->method('getLocale')->willReturn(new Locale('de'));
        $GLOBALS['LANG'] = $languageService;

        $data = $this->json($this->subject(null)->statusAction(new ServerRequest('https://example.com/', 'GET')));

        self::assertSame('3.456', $data['status']['month']['tokens']['used']);
        // ICU puts a no-break space before the sign.
        self::assertSame("1,25\u{a0}$", $data['status']['today']['cost']['used']);
    }

    #[Test]
    public function aFailureReadingTheStatusIsA500WithoutNumbers(): void
    {
        $repository = $this->createStub(UserBudgetRepository::class);
        $usage      = $this->createStub(BudgetUsageWindowsInterface::class);
        $usage->method('aggregate')->willThrowException(new RuntimeException('database gone'));
        $context = $this->createStub(Context::class);
        $context->method('getPropertyFromAspect')->willReturn(self::SESSION_USER);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('Budget status could not be read', ['exception' => 'database gone']);

        $response = (new BudgetStatusController(new UserBudgetStatusService($repository, $usage), $context, $logger))
            ->statusAction(new ServerRequest('https://example.com/', 'GET'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['success' => false], $this->json($response));
    }

    private function subject(?UserBudget $budget): BudgetStatusController
    {
        $repository = $this->createStub(UserBudgetRepository::class);
        $repository->method('findOneByBeUser')->willReturn($budget);
        $usage = $this->createStub(BudgetUsageWindowsInterface::class);
        $usage->method('aggregate')->willReturnCallback(function (int $beUserUid): array {
            $this->readUsers[] = $beUserUid;

            return [
                'daily'   => ['requests' => 12, 'tokens' => 1234, 'cost' => 1.25],
                'monthly' => ['requests' => 40, 'tokens' => 3456, 'cost' => 4.5],
            ];
        });
        $context = $this->createStub(Context::class);
        $context->method('getPropertyFromAspect')->willReturnMap([
            ['backend.user', 'id', 0, self::SESSION_USER],
        ]);

        return new BudgetStatusController(new UserBudgetStatusService($repository, $usage), $context, new NullLogger());
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
