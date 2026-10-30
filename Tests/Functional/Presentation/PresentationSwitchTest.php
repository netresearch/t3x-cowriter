<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Presentation;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\T3Cowriter\Controller\PresentationController;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\Presentation\PresentationAccess;
use Netresearch\T3Cowriter\Service\Presentation\PresentationConfiguration;
use Netresearch\T3Cowriter\Service\Presentation\PresentationElementReader;
use Netresearch\T3Cowriter\Service\Presentation\PresentationSwitcher;
use Netresearch\T3Cowriter\Service\Presentation\PresentationSwitchToken;
use Netresearch\T3Cowriter\Service\Presentation\PresentationWriter;
use Netresearch\T3Cowriter\Service\Presentation\SectionProposalService;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Tests\Support\ConfigurationAccessDouble;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Create, then show the new element and hide the original, through both
 * routes with bootstrap_package's TCA and the real DataHandler, live and in a
 * workspace; and the refusals for any other pair and without the right on
 * the hidden field.
 */
#[CoversClass(PresentationController::class)]
#[CoversClass(PresentationSwitcher::class)]
#[CoversClass(PresentationSwitchToken::class)]
#[CoversClass(PresentationAccess::class)]
final class PresentationSwitchTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor', 'seo', 'workspaces', 'extensionmanager', 'install'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
        'bk2k/bootstrap-package',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            't3_cowriter' => [
                'presentation' => ['enable' => '1'],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/elements.csv');
    }

    private function editor(int $uid, int $workspace = 0): BackendUserAuthentication
    {
        $backendUser = $this->setUpBackendUser($uid);
        if ($workspace > 0) {
            $backendUser->setWorkspace($workspace);
        }

        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        return $backendUser;
    }

    private function finder(): RecordFinder
    {
        return new RecordFinder(GeneralUtility::makeInstance(ConnectionPool::class));
    }

    private function controller(): PresentationController
    {
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(true, 20, 19, time() + 60));
        $configurationRepository = $this->createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findDefault')->willReturn(new LlmConfiguration());

        return new PresentationController(
            new PresentationConfiguration($this->get(ExtensionConfiguration::class)),
            new PresentationElementReader($this->finder()),
            new SectionProposalService(new FakeCompletionService()),
            new PresentationWriter($this->finder(), new NullLogger()),
            new PresentationSwitcher($this->finder(), new NullLogger()),
            new PresentationSwitchToken(new HashService()),
            ConfigurationAccessDouble::selector($configurationRepository),
            $rateLimiter,
            $this->get(UriBuilder::class),
            GeneralUtility::makeInstance(Context::class),
            new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): ServerRequest
    {
        return (new ServerRequest('https://example.com/typo3/ajax/cowriter/presentation', 'POST'))
            ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * Proposes and creates a tab element for element $uid as the current
     * editor; returns the creation's answer.
     *
     * @return array<string, mixed>
     */
    private function create(int $uid): array
    {
        $proposal = $this->json($this->controller()->proposeAction($this->post(['uid' => $uid])));
        self::assertTrue($proposal['success'] ?? false, json_encode($proposal, JSON_THROW_ON_ERROR));
        /** @var list<array{title: string, start: int, consumesHeading: bool}> $sections */
        $sections = $proposal['sections'];

        $created = $this->json($this->controller()->createAction($this->post([
            'uid'         => $uid,
            'layout'      => 'tab',
            'fingerprint' => $proposal['fingerprint'],
            'sections'    => array_map(
                static fn (array $s): array => ['title' => $s['title'], 'start' => $s['start'], 'consumesHeading' => $s['consumesHeading']],
                $sections,
            ),
        ])));
        self::assertTrue($created['success'] ?? false, json_encode($created, JSON_THROW_ON_ERROR));

        return $created;
    }

    /**
     * @param array<string, mixed> $created
     * @param array<string, mixed> $overrides
     */
    private function switchPair(array $created, array $overrides = []): ResponseInterface
    {
        return $this->controller()->switchAction($this->post($overrides + [
            'newUid'      => $created['uid'],
            'originalUid' => $created['originalUid'],
            'token'       => $created['switchToken'],
        ]));
    }

    private function hidden(int $uid, int $workspace): ?int
    {
        $row = $this->finder()->findRecord('tt_content', $uid, $workspace);

        return $row === null ? null : (int) $row['hidden'];
    }

    #[Test]
    public function theNewElementIsCreatedHiddenAndTheSwitchSwapsTheVisibility(): void
    {
        $this->editor(2);
        $created = $this->create(10);
        self::assertIsInt($created['uid']);
        self::assertSame(10, $created['originalUid']);
        self::assertStringEndsWith('#element-tt_content-' . $created['uid'], (string) $created['pageUrl']);
        self::assertStringContainsString('returnUrl=', (string) $created['editUrl']);
        self::assertSame([1, 0], [$this->hidden($created['uid'], 0), $this->hidden(10, 0)]);

        $response = $this->switchPair($created);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([0, 1], [$this->hidden($created['uid'], 0), $this->hidden(10, 0)]);
    }

    #[Test]
    public function inAWorkspaceTheSwitchIsADraftOfThatWorkspace(): void
    {
        $this->editor(2, 1);
        $created = $this->create(10);
        self::assertIsInt($created['uid']);

        $response = $this->switchPair($created);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([0, 1], [$this->hidden($created['uid'], 1), $this->hidden(10, 1)], 'the workspace shows the switch');
        self::assertSame(0, $this->hidden(10, 0), 'live still shows the original');
        self::assertNull($this->hidden($created['uid'], 0), 'live does not show the new element');
    }

    #[Test]
    public function aPairNotCreatedTogetherIsRefused(): void
    {
        $this->editor(2);
        $created = $this->create(10);

        // Element 11 is on the same page and editable, but not the original.
        $response = $this->switchPair($created, ['originalUid' => 11]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([1, 0, 0], [$this->hidden((int) $created['uid'], 0), $this->hidden(10, 0), $this->hidden(11, 0)]);
    }

    #[Test]
    public function aTokenForForeignUidsIsRefused(): void
    {
        $backendUser = $this->editor(2);
        $created     = $this->create(10);
        // A valid token, minted for a pair whose original is on another page.
        $token = (new PresentationSwitchToken(new HashService()))->issue((int) $created['uid'], 13, (int) $backendUser->user['uid'], 0);

        $response = $this->switchPair($created, ['originalUid' => 13, 'token' => $token]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([1, 0], [$this->hidden((int) $created['uid'], 0), $this->hidden(13, 0)]);
    }

    #[Test]
    public function anotherEditorCannotUseTheToken(): void
    {
        $this->editor(2);
        $created = $this->create(10);

        $this->editor(1);
        $response = $this->switchPair($created);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([1, 0], [$this->hidden((int) $created['uid'], 0), $this->hidden(10, 0)]);
    }

    #[Test]
    public function anEditorWithoutTheRightOnTheHiddenFieldIsRefused(): void
    {
        $this->editor(2);
        $created = $this->create(10);

        // Editor 7 may do everything except write the hidden field; the token
        // is made for that editor, so only the permission check refuses.
        $backendUser = $this->editor(7);
        $token       = (new PresentationSwitchToken(new HashService()))->issue((int) $created['uid'], 10, (int) $backendUser->user['uid'], 0);
        $response    = $this->switchPair($created, ['token' => $token]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([1, 0], [$this->hidden((int) $created['uid'], 0), $this->hidden(10, 0)]);
    }

    #[Test]
    public function theSwitchKeepsTheAccessRestrictionsOfTheNewElement(): void
    {
        // Element 17 is hidden already and for logged-in visitors only: the
        // switch shows the new element to them, not to everyone.
        $this->editor(2);
        $created = $this->create(17);

        $response = $this->switchPair($created);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $row = $this->finder()->findRecord('tt_content', (int) $created['uid'], 0);
        self::assertNotNull($row);
        self::assertSame(['-2', 1700000000, 4102444800, 0], [(string) $row['fe_group'], (int) $row['starttime'], (int) $row['endtime'], (int) $row['hidden']]);
        self::assertSame(1, $this->hidden(17, 0));
    }

    #[Test]
    public function aPlainWorkspaceMemberSwitchesInTheWorkspace(): void
    {
        // Editor 8 is a member of the workspace, not its owner.
        $this->editor(8, 1);
        $created = $this->create(10);

        $response = $this->switchPair($created);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([0, 1], [$this->hidden((int) $created['uid'], 1), $this->hidden(10, 1)]);
        self::assertSame(0, $this->hidden(10, 0), 'live still shows the original');
    }
}
