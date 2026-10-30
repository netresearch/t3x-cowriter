<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\FieldSuggestion;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\T3Cowriter\Controller\FieldOverviewController;
use Netresearch\T3Cowriter\EventListener\AddFieldOverviewButtonListener;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldOverviewService;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContextReader;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\FieldSuggestion\SlugSuggestionBuilder;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Service\Vision\RecordLanguageResolver;
use Netresearch\T3Cowriter\Tests\Support\ConfigurationAccessDouble;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The "all fields" overview against the real TCA, database and permissions.
 */
#[CoversClass(FieldOverviewController::class)]
#[CoversClass(FieldOverviewService::class)]
#[CoversClass(AddFieldOverviewButtonListener::class)]
final class FieldOverviewTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor', 'seo'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
    ];

    private FakeCompletionService $completion;

    #[Test]
    public function theControllerIsResolvedThroughDependencyInjection(): void
    {
        self::assertInstanceOf(FieldOverviewController::class, $this->get(FieldOverviewController::class));
    }

    #[Test]
    public function theRecordFormOfAPageGetsTheHiddenButtonThroughTheEventDispatcher(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages_and_users.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($this->setUpBackendUser(1));
        $request         = (new ServerRequest('https://example.com/typo3/record/edit'))
            ->withAttribute('route', new Route('/record/edit', ['_identifier' => 'record_edit']))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/typo3/index.php']))
            ->withQueryParams(['edit' => ['pages' => ['3' => 'edit']]]);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        /** @var ModifyButtonBarEvent $event */
        $event = $this->get(EventDispatcherInterface::class)->dispatch(
            new ModifyButtonBarEvent([], $this->createStub(ButtonBar::class), $request),
        );

        $html = '';
        foreach ($event->getButtons()[ButtonBar::BUTTON_POSITION_LEFT] ?? [] as $group) {
            foreach ($group as $button) {
                $html .= $button->render();
            }
        }

        self::assertStringContainsString(AddFieldOverviewButtonListener::BUTTON_CLASS . ' d-none', $html);
        self::assertStringContainsString('data-table="pages"', $html);
        self::assertStringContainsString('Suggest values for all fields with AI', $html);
        self::assertStringContainsString('cowriter/suggestions/all', $html);
    }

    #[Test]
    public function anEditorGetsReviewsOnlyForTheFieldsHeMayEdit(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages_and_users.csv');
        $this->setUpBackendUser(2);

        $response = $this->suggestAll(
            ['table' => 'pages', 'uid' => 3, 'pid' => 2, 'fields' => [
                // The editor group may edit description, keywords and seo_title,
                // not og_title; abstract has no permission either.
                'seo_title' => '', 'description' => 'Stored description', 'og_title' => '', 'abstract' => '', 'TSconfig' => '',
            ]],
            ['fields' => [
                ['field' => 'seo_title', 'action' => 'change', 'value' => str_repeat('Office chairs compared ', 5), 'reason' => 'The field is empty.'],
                ['field' => 'description', 'action' => 'keep', 'reason' => 'Already a good summary.'],
                // The model answers for a field the editor may not edit: dropped.
                ['field' => 'og_title', 'action' => 'keep', 'reason' => 'Not asked for.'],
            ]],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        /** @var array{fields: list<array{field: string, action: string, value: string, maxLength: int}>} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['seo_title', 'description'], array_column($data['fields'], 'field'));
        self::assertLessThanOrEqual(60, mb_strlen($data['fields'][0]['value']));
        self::assertSame(60, $data['fields'][0]['maxLength']);

        $call = $this->completion->completeStructuredForConfigurationCalls[0];
        self::assertStringContainsString('- seo_title (', (string) $call['options']?->getSystemPrompt());
        self::assertStringContainsString('- description (', (string) $call['options']?->getSystemPrompt());
        self::assertStringNotContainsString('og_title', (string) $call['options']?->getSystemPrompt());
        self::assertStringContainsString('Ergonomic chairs', $call['prompt']);
    }

    #[Test]
    public function anEditorWithoutAnyAllowedFieldIsRefusedBeforeTheModel(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages_and_users.csv');
        $this->setUpBackendUser(3);

        $response = $this->suggestAll(
            ['table' => 'pages', 'uid' => 3, 'pid' => 2, 'fields' => ['seo_title' => '', 'description' => '']],
            ['fields' => []],
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $answer
     */
    private function suggestAll(array $body, array $answer): ResponseInterface
    {
        $this->completion                   = new FakeCompletionService();
        $this->completion->structuredResult = $answer;

        $configurationRepository = $this->createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findDefault')->willReturn(new LlmConfiguration());
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(true, 20, 19, time() + 60));
        $finder = new RecordFinder(GeneralUtility::makeInstance(ConnectionPool::class));

        $subject = new FieldOverviewController(
            new RecordContextReader($finder),
            new FieldOverviewService($this->completion, new SlugSuggestionBuilder()),
            ConfigurationAccessDouble::selector($configurationRepository),
            $rateLimiter,
            new RecordLanguageResolver($finder, GeneralUtility::makeInstance(SiteFinder::class)),
            GeneralUtility::makeInstance(Context::class),
            new NullLogger(),
        );

        return $subject->suggestAllAction(
            (new ServerRequest('https://example.com/typo3/ajax/cowriter/suggestions/all', 'POST'))
                ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR))),
        );
    }
}
