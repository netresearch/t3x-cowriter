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
use Netresearch\T3Cowriter\Controller\FieldSuggestionController;
use Netresearch\T3Cowriter\EventListener\RegisterFieldSuggestionControlsListener;
use Netresearch\T3Cowriter\Form\FieldControl\FieldSuggestionsControl;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionService;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContextReader;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\FieldSuggestion\SlugSuggestionBuilder;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The demo case: EXT:seo installed, an editor uses the button on the SEO title.
 */
#[CoversClass(RegisterFieldSuggestionControlsListener::class)]
#[CoversClass(FieldSuggestionController::class)]
#[CoversClass(FieldSuggestionsControl::class)]
final class FieldSuggestionWithSeoTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor', 'seo'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
    ];

    #[Test]
    public function seoTitleCarriesTheControlWhenExtSeoIsInstalled(): void
    {
        self::assertSame(
            ['renderType' => RegisterFieldSuggestionControlsListener::NODE_NAME, 'options' => ['count' => 3]],
            $GLOBALS['TCA']['pages']['columns']['seo_title']['config']['fieldControl'][RegisterFieldSuggestionControlsListener::CONTROL_NAME] ?? null,
        );
    }

    #[Test]
    public function theControlIsResolvedThroughDependencyInjection(): void
    {
        // NodeFactory creates field controls with GeneralUtility::makeInstance().
        self::assertInstanceOf(FieldSuggestionsControl::class, GeneralUtility::makeInstance(FieldSuggestionsControl::class));
    }

    #[Test]
    public function editorGetsThreeSuggestionsForTheSeoTitle(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages_and_users.csv');
        $this->setUpBackendUser(2);

        $completion                   = new FakeCompletionService();
        $completion->structuredResult = ['suggestions' => [
            'Office chairs with lumbar support',
            'Ergonomic office chairs',
            'Office chairs: adjustable and comfortable',
        ]];
        $configurationRepository = $this->createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findDefault')->willReturn(new LlmConfiguration());
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $subject = new FieldSuggestionController(
            new RecordContextReader(new RecordFinder(GeneralUtility::makeInstance(ConnectionPool::class))),
            new FieldSuggestionService($completion, new SlugSuggestionBuilder()),
            $configurationRepository,
            $rateLimiter,
            GeneralUtility::makeInstance(Context::class),
            new NullLogger(),
            GeneralUtility::makeInstance(LanguageServiceFactory::class),
        );
        $body     = ['table' => 'pages', 'field' => 'seo_title', 'uid' => 3, 'pid' => 3, 'count' => 3];
        $response = $subject->suggestAction(
            (new ServerRequest('https://example.com/typo3/ajax/cowriter/suggestions', 'POST'))
                ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR))),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        /** @var array{suggestions: list<string>} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(3, $data['suggestions']);
        foreach ($data['suggestions'] as $suggestion) {
            self::assertLessThanOrEqual(60, mb_strlen($suggestion));
        }
        self::assertStringContainsString('SEO titles', (string) $completion->completeStructuredForConfigurationCalls[0]['options']?->getSystemPrompt());
    }
}
