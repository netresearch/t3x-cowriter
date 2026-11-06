<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Variant;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\T3Cowriter\Controller\VariantController;
use Netresearch\T3Cowriter\EventListener\RegisterVariantControlListener;
use Netresearch\T3Cowriter\Form\FieldControl\VariantControl;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Service\Style\StyleInstructionBuilder;
use Netresearch\T3Cowriter\Service\Variant\AudienceContexts;
use Netresearch\T3Cowriter\Service\Variant\VariantConfiguration;
use Netresearch\T3Cowriter\Service\Variant\VariantElementReader;
use Netresearch\T3Cowriter\Service\Variant\VariantProposalService;
use Netresearch\T3Cowriter\Service\Variant\VariantShowToken;
use Netresearch\T3Cowriter\Service\Variant\VariantWriter;
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
 * The control as FormEngine renders a text element, and the three routes end
 * to end with EXT:contexts and real audience snippets: the proposal is a
 * model call and writes nothing, the confirmed creation writes the hidden
 * copy in its context, the show step makes it visible and hides the original
 * in that context.
 */
#[CoversClass(RegisterVariantControlListener::class)]
#[CoversClass(VariantControl::class)]
#[CoversClass(VariantController::class)]
#[CoversClass(VariantProposalService::class)]
final class VariantControlTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor', 'workspaces'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/contexts',
        'netresearch/t3-cowriter',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            't3_cowriter' => [
                'variant' => ['enable' => '1'],
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

    private function login(int $userUid): void
    {
        $backendUser     = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    private function formHtml(int $userUid, int $elementUid): string
    {
        $this->login($userUid);
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
    public function theTextTypesCarryTheControl(): void
    {
        foreach (['text', 'textpic', 'textmedia'] as $ctype) {
            self::assertSame(
                ['renderType' => RegisterVariantControlListener::NODE_NAME],
                $GLOBALS['TCA']['tt_content']['types'][$ctype]['columnsOverrides']['bodytext']['config']['fieldControl'][RegisterVariantControlListener::CONTROL_NAME] ?? null,
                $ctype,
            );
        }
    }

    #[Test]
    public function formEngineRendersTheControlWithTheActiveAudiences(): void
    {
        $html = $this->formHtml(2, 10);

        self::assertSame(1, preg_match('/<a[^>]*id="(t3js-cowriter-variant-[^"]+)"[^>]*>/', $html, $control), 'control rendered');
        self::assertStringContainsString('data-uid="10"', $control[0]);
        self::assertStringContainsString('Students', $control[0]);
        self::assertStringContainsString('Friendly', $control[0]);
        self::assertStringNotContainsString('Retired audience', $control[0]);
        self::assertStringNotContainsString('LLL:EXT:t3_cowriter', $control[0]);
    }

    #[Test]
    public function withoutAnAudienceSnippetThereIsNoControl(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrllm_promptsnippet')
            ->update('tx_nrllm_promptsnippet', ['is_active' => 0], ['tags' => 'audience']);

        self::assertStringNotContainsString('t3js-cowriter-variant-', $this->formHtml(2, 10));
    }

    #[Test]
    public function anEditorWhoMayNotHideTheCopyDoesNotSeeTheControl(): void
    {
        self::assertStringNotContainsString('t3js-cowriter-variant-', $this->formHtml(4, 10));
    }

    private function controller(FakeCompletionService $completion): VariantController
    {
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(true, 20, 19, time() + 60));
        $configurationRepository = $this->createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findDefault')->willReturn(new LlmConfiguration());
        $finder   = new RecordFinder(GeneralUtility::makeInstance(ConnectionPool::class));
        $contexts = new AudienceContexts(GeneralUtility::makeInstance(ConnectionPool::class));

        return new VariantController(
            new VariantConfiguration($this->get(ExtensionConfiguration::class)),
            new VariantElementReader($finder),
            new VariantProposalService($completion),
            new VariantWriter($finder, $contexts, new NullLogger()),
            new VariantShowToken(new HashService()),
            $contexts,
            $this->get(StyleInstructionBuilder::class),
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
        return (new ServerRequest('https://example.com/typo3/ajax/cowriter/variant', 'POST'))
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
     * @return array<string, mixed>
     */
    private function row(int $uid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder->select('*')->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    #[Test]
    public function proposingCreatingAndShowingEndToEnd(): void
    {
        $this->login(2);
        $completion                   = new FakeCompletionService();
        $completion->structuredResult = ['header' => 'Chairs for students', 'bodytext' => '<p>Cheap and sturdy.</p>'];

        $subject = $this->controller($completion);
        $before  = $this->row(10);

        $proposal = $this->json($subject->proposeAction($this->post(['uid' => 10, 'audience' => 31, 'tone' => 34])));
        self::assertTrue($proposal['success'] ?? false, json_encode($proposal, JSON_THROW_ON_ERROR));
        self::assertSame('Chairs for students', $proposal['header']);
        self::assertSame([['uid' => 6, 'title' => 'Campaign'], ['uid' => 5, 'title' => 'Mobile channel']], $proposal['contexts']);
        self::assertSame($before, $this->row(10), 'the proposal writes nothing');

        // The audience and the tone reach the model as instructions, the
        // element as fenced data.
        self::assertCount(1, $completion->completeStructuredForConfigurationCalls);
        $call    = $completion->completeStructuredForConfigurationCalls[0];
        $options = $call['options'];
        self::assertInstanceOf(ChatOptions::class, $options);
        $systemPrompt = (string) $options->getSystemPrompt();
        self::assertStringContainsString('Students on a small budget', $systemPrompt);
        self::assertStringContainsString('Warm and friendly', $systemPrompt);
        self::assertStringNotContainsString('Our chairs fit every office.', $systemPrompt);
        self::assertStringContainsString('Our chairs fit every office.', $call['prompt']);

        $response = $subject->createAction($this->post([
            'uid'         => 10,
            'fingerprint' => $proposal['fingerprint'],
            'header'      => 'Chairs for students (reviewed)',
            'bodytext'    => '<p>Cheap and sturdy.</p>',
            'context'     => 5,
        ]));
        $created = $this->json($response);
        self::assertSame(200, $response->getStatusCode(), json_encode($created, JSON_THROW_ON_ERROR));
        self::assertSame('Mobile channel', $created['contextTitle']);
        self::assertNotSame('', $created['showToken']);
        self::assertIsInt($created['uid']);

        $variant = $this->row($created['uid']);
        self::assertSame('Chairs for students (reviewed)', $variant['header']);
        self::assertSame(1, (int) $variant['hidden']);
        self::assertSame('5', (string) $variant['tx_contexts_enable']);
        self::assertSame($before, $this->row(10), 'the creation does not touch the original');

        $shown = $subject->showAction($this->post([
            'variantUid'   => $created['uid'],
            'originalUid'  => 10,
            'context'      => 5,
            'hideOriginal' => true,
            'token'        => $created['showToken'],
        ]));
        self::assertSame(200, $shown->getStatusCode(), (string) $shown->getBody());
        self::assertSame(0, (int) $this->row($created['uid'])['hidden']);
        self::assertSame('5', (string) $this->row(10)['tx_contexts_disable']);
        self::assertSame(0, (int) $this->row(10)['hidden']);
    }

    #[Test]
    public function anAudienceTheDialogDoesNotOfferCostsNothing(): void
    {
        $this->login(2);
        $completion = new FakeCompletionService();

        $response = $this->controller($completion)->proposeAction($this->post(['uid' => 10, 'audience' => 33]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function aContextHiddenInTheBackendIsRefused(): void
    {
        $this->login(2);
        $subject = $this->controller(new FakeCompletionService());
        $count   = count(GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')->select(['uid'], 'tt_content')->fetchAllAssociative());

        $response = $subject->createAction($this->post([
            'uid'         => 10,
            'fingerprint' => hash('sha256', "Office chairs\0<p>Our chairs fit every office.</p><p>Five years warranty.</p>"),
            'header'      => 'x',
            'bodytext'    => '<p>y</p>',
            'context'     => 7,
        ]));

        self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount($count, GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')->select(['uid'], 'tt_content')->fetchAllAssociative());
    }

    #[Test]
    public function aVariantWithoutAContextGetsNoShowStepAndANote(): void
    {
        $this->login(2);
        $response = $this->controller(new FakeCompletionService())->createAction($this->post([
            'uid'         => 10,
            'fingerprint' => hash('sha256', "Office chairs\0<p>Our chairs fit every office.</p><p>Five years warranty.</p>"),
            'header'      => 'Chairs',
            'bodytext'    => '<p>Text</p>',
        ]));
        $created = $this->json($response);

        self::assertSame(200, $response->getStatusCode(), json_encode($created, JSON_THROW_ON_ERROR));
        self::assertSame('', $created['showToken']);
        self::assertNotSame('', $created['note']);
    }

    #[Test]
    public function aChangedTextIsRefused(): void
    {
        $this->login(2);
        $response = $this->controller(new FakeCompletionService())->createAction($this->post([
            'uid'         => 10,
            'fingerprint' => str_repeat('a', 64),
            'header'      => 'Chairs',
            'bodytext'    => '<p>Text</p>',
        ]));

        self::assertSame(409, $response->getStatusCode());
    }

    #[Test]
    public function aForgedShowTokenIsRefused(): void
    {
        $this->login(2);
        $response = $this->controller(new FakeCompletionService())->showAction($this->post([
            'variantUid'   => 11,
            'originalUid'  => 10,
            'context'      => 5,
            'hideOriginal' => true,
            'token'        => str_repeat('0', 64),
        ]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('', (string) $this->row(10)['tx_contexts_disable']);
    }
}
