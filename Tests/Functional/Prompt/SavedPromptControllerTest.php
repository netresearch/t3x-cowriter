<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Prompt;

use Netresearch\T3Cowriter\Controller\SavedPromptController;
use Netresearch\T3Cowriter\Service\Prompt\PromptSharingConfiguration;
use Netresearch\T3Cowriter\Service\Prompt\SavedPromptRepository;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The saved-prompt endpoints against a real database and real backend users.
 */
#[CoversClass(SavedPromptController::class)]
#[CoversClass(SavedPromptRepository::class)]
#[CoversClass(PromptSharingConfiguration::class)]
final class SavedPromptControllerTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/Fixtures/prompts_and_users.csv');
    }

    #[Test]
    public function anEditorSeesOwnPromptsFirstAndApprovedSharedPromptsOfOthers(): void
    {
        $this->setUpBackendUser(2);

        $prompts = $this->json($this->subject()->listAction($this->request('GET')))['prompts'];

        self::assertSame(
            [[12, true, true, true], [11, true, false, false], [23, false, true, false]],
            array_map(static fn (array $p): array => [$p['uid'], $p['own'], $p['shared'], $p['awaitingApproval']], $prompts),
        );
        self::assertSame('Approved for everyone', $prompts[2]['instruction']);
    }

    #[Test]
    public function aHiddenOrDeletedPromptIsGoneForItsOwnerToo(): void
    {
        $this->setUpBackendUser(3);

        $prompts = $this->json($this->subject()->listAction($this->request('GET')))['prompts'];

        // 24 is deleted, 25 hidden; 12 is shared by the editor and not approved.
        self::assertSame([21, 23, 22], array_column($prompts, 'uid'));
        self::assertTrue($prompts[2]['awaitingApproval']);
    }

    #[Test]
    public function withoutRequiredApprovalEveryPromptSharedByOthersIsVisible(): void
    {
        $this->setUpBackendUser(2);

        $prompts = $this->json($this->subject(approvalRequired: false)->listAction($this->request('GET')))['prompts'];

        self::assertSame([12, 11, 23, 22], array_column($prompts, 'uid'));
        self::assertFalse($prompts[0]['awaitingApproval']);
    }

    #[Test]
    public function aSharedPromptOfAnEditorWaitsForApproval(): void
    {
        $this->setUpBackendUser(2);

        $data = $this->json($this->subject()->saveAction($this->request('POST', [
            'title'       => '  Teaser  ',
            'instruction' => 'Write a teaser of two sentences.',
            'shared'      => true,
        ])));

        self::assertTrue($data['success']);
        self::assertTrue($data['prompt']['awaitingApproval']);
        self::assertSame('Teaser', $data['prompt']['title']);
        $row = $this->row($data['prompt']['uid']);
        self::assertSame([2, 'Teaser', 1, 0, 0], [(int) $row['be_user'], $row['title'], (int) $row['shared'], (int) $row['approved'], (int) $row['pid']]);

        $this->setUpBackendUser(3);
        $seenByColleague = array_column($this->json($this->subject()->listAction($this->request('GET')))['prompts'], 'uid');
        self::assertNotContains($data['prompt']['uid'], $seenByColleague);
    }

    #[Test]
    public function aSharedPromptOfAnAdministratorIsApprovedAndVisibleToOthers(): void
    {
        $this->setUpBackendUser(1);

        $data = $this->json($this->subject()->saveAction($this->request('POST', [
            'title'       => 'House style',
            'instruction' => 'Use the house style.',
            'shared'      => true,
        ])));

        self::assertFalse($data['prompt']['awaitingApproval']);
        self::assertSame(1, (int) $this->row($data['prompt']['uid'])['approved']);

        $this->setUpBackendUser(2);
        $seenByEditor = array_column($this->json($this->subject()->listAction($this->request('GET')))['prompts'], 'uid');
        self::assertContains($data['prompt']['uid'], $seenByEditor);
    }

    #[Test]
    public function aPrivatePromptIsNeitherSharedNorApproved(): void
    {
        $this->setUpBackendUser(1);

        $data = $this->json($this->subject()->saveAction($this->request('POST', [
            'title'       => 'Mine',
            'instruction' => 'Only for me.',
        ])));

        $row = $this->row($data['prompt']['uid']);
        self::assertSame([0, 0], [(int) $row['shared'], (int) $row['approved']]);
        self::assertFalse($data['prompt']['awaitingApproval']);
    }

    #[Test]
    public function aPromptWithoutTitleOrWithATooLongTitleIsRefused(): void
    {
        $this->setUpBackendUser(2);

        foreach ([['title' => '', 'instruction' => 'x'], ['title' => str_repeat('ä', 256), 'instruction' => 'x'], ['title' => 'x', 'instruction' => '  ']] as $body) {
            $response = $this->subject()->saveAction($this->request('POST', $body));
            self::assertSame(400, $response->getStatusCode());
        }
        self::assertSame(200, $this->subject()->saveAction($this->request('POST', ['title' => str_repeat('ä', 255), 'instruction' => 'x']))->getStatusCode());
    }

    #[Test]
    public function theHundredAndFirstPromptOfAUserIsRefused(): void
    {
        $this->setUpBackendUser(2);
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(SavedPromptRepository::TABLE);
        for ($i = 0; $i < SavedPromptRepository::MAX_PER_USER - 2; ++$i) {
            $connection->insert(SavedPromptRepository::TABLE, ['pid' => 0, 'be_user' => 2, 'title' => 'P' . $i, 'instruction' => 'x']);
        }

        $body = ['title' => 'One more', 'instruction' => 'x'];
        self::assertSame(409, $this->subject()->saveAction($this->request('POST', $body))->getStatusCode());

        $this->subject()->deleteAction($this->request('POST', ['uid' => 11]));
        self::assertSame(200, $this->subject()->saveAction($this->request('POST', $body))->getStatusCode());
    }

    #[Test]
    public function anEditorDeletesAnOwnPromptOnly(): void
    {
        $this->setUpBackendUser(2);

        self::assertSame(404, $this->subject()->deleteAction($this->request('POST', ['uid' => 23]))->getStatusCode());
        self::assertSame(0, (int) $this->row(23)['deleted']);

        self::assertSame(200, $this->subject()->deleteAction($this->request('POST', ['uid' => 11]))->getStatusCode());
        self::assertSame(1, (int) $this->row(11)['deleted']);

        self::assertSame(404, $this->subject()->deleteAction($this->request('POST', ['uid' => 11]))->getStatusCode());
        self::assertSame(400, $this->subject()->deleteAction($this->request('POST', ['uid' => '11']))->getStatusCode());
    }

    private function subject(bool $approvalRequired = true): SavedPromptController
    {
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        // Without a settings.php entry the setting reads as "approval required".
        $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        if (!$approvalRequired) {
            $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
            $extensionConfiguration->method('get')->willReturn('0');
        }

        return new SavedPromptController(
            new SavedPromptRepository(
                GeneralUtility::makeInstance(ConnectionPool::class),
                new PromptSharingConfiguration($extensionConfiguration),
            ),
            $rateLimiter,
            GeneralUtility::makeInstance(Context::class),
            new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, array $body = []): ServerRequest
    {
        $request = new ServerRequest('https://example.com/typo3/ajax/cowriter/prompts', $method);
        if ($body === []) {
            return $request;
        }

        return $request->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $uid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(SavedPromptRepository::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder->select('*')->from(SavedPromptRepository::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $uid))
            ->executeQuery()->fetchAssociative();
        self::assertIsArray($row);

        return $row;
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
