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
use Netresearch\T3Cowriter\EventListener\RegisterPresentationControlListener;
use Netresearch\T3Cowriter\Form\FieldControl\PresentationControl;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
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
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The control as FormEngine renders a text element with bootstrap_package's
 * TCA, and both routes end to end: the proposal of a text with headings
 * makes no model call, the confirmed creation writes the new element.
 */
#[CoversClass(RegisterPresentationControlListener::class)]
#[CoversClass(PresentationControl::class)]
#[CoversClass(PresentationController::class)]
final class PresentationControlTest extends FunctionalTestCase
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

        $request = (new ServerRequest('https://example.com/typo3/record/edit', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $GLOBALS['TYPO3_REQUEST'] = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    private function formHtml(int $userUid, int $elementUid): string
    {
        $backendUser     = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $formData = $this->get(FormDataCompiler::class)->compile(
            [
                'request'    => $GLOBALS['TYPO3_REQUEST']->withAttribute('route', new Route('/record/edit', ['packageName' => 'typo3/cms-backend'])),
                'tableName'  => 'tt_content',
                'vanillaUid' => $elementUid,
                'command'    => 'edit',
            ],
            $this->get(TcaDatabaseRecord::class),
        );
        $formData['renderType'] = 'fullRecordContainer';

        return (string) $this->get(NodeFactory::class)->create($formData)->render()['html'];
    }

    #[Test]
    public function theTextTypesCarryTheControlInTheRealTca(): void
    {
        foreach (['text', 'textpic', 'textmedia'] as $ctype) {
            self::assertSame(
                ['renderType' => RegisterPresentationControlListener::NODE_NAME],
                $GLOBALS['TCA']['tt_content']['types'][$ctype]['columnsOverrides']['bodytext']['config']['fieldControl'][RegisterPresentationControlListener::CONTROL_NAME] ?? null,
                $ctype,
            );
        }

        self::assertArrayNotHasKey('fieldControl', $GLOBALS['TCA']['tt_content']['columns']['bodytext']['config']);
    }

    #[Test]
    public function formEngineRendersTheControlNextToTheRichTextOfATextElement(): void
    {
        $html = $this->formHtml(2, 10);

        self::assertSame(1, preg_match('/<a[^>]*id="(t3js-cowriter-presentation-[^"]+)"[^>]*>/', $html, $control), 'control rendered');
        self::assertStringContainsString('data-uid="10"', $control[0]);
        self::assertStringContainsString('data-layouts="tab,accordion"', $control[0]);
        self::assertStringContainsString('Present as tabs or accordion', $html);
        self::assertStringNotContainsString('LLL:EXT:t3_cowriter', $control[0]);
    }

    #[Test]
    public function anEditorWithoutTheLayoutsDoesNotSeeTheControl(): void
    {
        self::assertStringNotContainsString('t3js-cowriter-presentation-', $this->formHtml(4, 10));
    }

    private function controller(FakeCompletionService $completion): PresentationController
    {
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(true, 20, 19, time() + 60));
        $configurationRepository = $this->createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findDefault')->willReturn(new LlmConfiguration());
        $finder = new RecordFinder(GeneralUtility::makeInstance(ConnectionPool::class));

        return new PresentationController(
            new PresentationConfiguration($this->get(ExtensionConfiguration::class)),
            new PresentationElementReader($finder),
            new SectionProposalService($completion),
            new PresentationWriter($finder, new NullLogger()),
            new PresentationSwitcher($finder, new NullLogger()),
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

    #[Test]
    public function proposingAndCreatingEndToEnd(): void
    {
        $backendUser     = $this->setUpBackendUser(2);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $completion      = new FakeCompletionService();
        $subject         = $this->controller($completion);

        $proposal = $this->json($subject->proposeAction($this->post(['uid' => 10])));
        self::assertTrue($proposal['success'] ?? false, json_encode($proposal, JSON_THROW_ON_ERROR));
        self::assertSame('headings', $proposal['source']);
        self::assertSame([], $completion->completeStructuredForConfigurationCalls, 'no model call, no cost');

        /** @var list<array{title: string, start: int, consumesHeading: bool}> $sections */
        $sections             = $proposal['sections'];
        $sections[0]['title'] = 'Overview';

        $response = $subject->createAction($this->post([
            'uid'         => 10,
            'layout'      => 'accordion',
            'fingerprint' => $proposal['fingerprint'],
            'sections'    => array_map(
                static fn (array $s): array => ['title' => $s['title'], 'start' => $s['start'], 'consumesHeading' => $s['consumesHeading']],
                $sections,
            ),
        ]));
        $created = $this->json($response);

        self::assertSame(200, $response->getStatusCode(), json_encode($created, JSON_THROW_ON_ERROR));
        self::assertIsInt($created['uid']);
        self::assertStringContainsString('record/edit', (string) $created['editUrl']);

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_bootstrappackage_accordion_item');
        $headers      = $queryBuilder->select('header')->from('tx_bootstrappackage_accordion_item')
            ->where($queryBuilder->expr()->eq('tt_content', $queryBuilder->createNamedParameter($created['uid'], Connection::PARAM_INT)))
            ->orderBy('sorting')->executeQuery()->fetchFirstColumn();
        self::assertSame(['Overview', 'Seat', 'Warranty'], $headers);
    }
}
