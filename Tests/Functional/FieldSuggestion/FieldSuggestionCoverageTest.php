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
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldProfile;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionConfiguration;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionService;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContextReader;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\FieldSuggestion\SlugSuggestionBuilder;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Tests\Support\ConfigurationAccessDouble;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Which fields get the button in the compiled TCA of core plus the system
 * extensions a typical site uses, and what the model is told for them.
 */
#[CoversClass(RegisterFieldSuggestionControlsListener::class)]
#[CoversClass(FieldSuggestionConfiguration::class)]
#[CoversClass(FieldProfile::class)]
#[CoversClass(FieldSuggestionController::class)]
final class FieldSuggestionCoverageTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor', 'seo', 'workspaces'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
    ];

    private FakeCompletionService $completion;

    #[Test]
    public function theDefaultSetIsExactlyTheSetOfFieldsWithTheControl(): void
    {
        $withControl = [];
        foreach (['pages', 'tt_content', 'sys_file_reference', 'sys_file_metadata'] as $table) {
            foreach ($GLOBALS['TCA'][$table]['columns'] as $field => $column) {
                if (isset($column['config']['fieldControl'][RegisterFieldSuggestionControlsListener::CONTROL_NAME])) {
                    $withControl[] = $table . '.' . $field;
                }
            }
        }

        sort($withControl);
        $expected = explode(',', FieldSuggestionConfiguration::DEFAULT_FIELDS);
        sort($expected);

        self::assertSame($expected, $withControl);
    }

    #[Test]
    public function richTextBodytextGetsNoControlEvenWhenConfigured(): void
    {
        $listener = GeneralUtility::makeInstance(RegisterFieldSuggestionControlsListener::class);
        $tca      = $listener->register($GLOBALS['TCA'], [['tt_content', 'bodytext'], ['pages', 'TSconfig'], ['pages', 'cache_tags'], ['pages', 'target']], 3);

        foreach ([['tt_content', 'bodytext'], ['pages', 'TSconfig'], ['pages', 'cache_tags'], ['pages', 'target']] as [$table, $field]) {
            self::assertArrayNotHasKey(
                RegisterFieldSuggestionControlsListener::CONTROL_NAME,
                $tca[$table]['columns'][$field]['config']['fieldControl'] ?? [],
                $table . '.' . $field,
            );
        }
    }

    #[Test]
    public function aContentElementHeadlineIsSuggestedFromTheElementsOwnText(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages_and_users.csv');
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($this->setUpBackendUser(2));

        $response = $this->suggest(
            ['table' => 'tt_content', 'field' => 'header', 'uid' => 11, 'pid' => 3, 'count' => 3],
            ['Five-year frame warranty', 'Our warranty', 'Warranty on every frame'],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $call = $this->completion->completeStructuredForConfigurationCalls[0];
        self::assertStringContainsString('headline of this content element', (string) $call['options']?->getSystemPrompt());
        self::assertStringContainsString('at most 80 characters', (string) $call['options']?->getSystemPrompt());
        // The field by its translated label, as the editor sees it.
        self::assertStringContainsString("Other fields of the edited record:\n- Text: Five years on every frame.\n", $call['prompt']);
    }

    #[Test]
    public function anOpenGraphTitleIsLimitedAndRefusedToEditorsWithoutTheField(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages_and_users.csv');
        $this->setUpBackendUser(2);

        // The editor group has no access to the excluded field og_title.
        $refused = $this->suggest(['table' => 'pages', 'field' => 'og_title', 'uid' => 3, 'pid' => 3, 'count' => 3], ['x', 'y', 'z']);
        self::assertSame(403, $refused->getStatusCode());

        $this->setUpBackendUser(1);
        $longTitle = str_repeat('Ergonomic office chairs ', 5);
        $response  = $this->suggest(
            ['table' => 'pages', 'field' => 'og_title', 'uid' => 3, 'pid' => 3, 'count' => 3],
            ['Sit better: ergonomic office chairs', $longTitle, 'Office chairs for long days'],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        /** @var array{suggestions: list<string>} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(3, $data['suggestions']);
        foreach ($data['suggestions'] as $suggestion) {
            self::assertLessThanOrEqual(70, mb_strlen($suggestion));
        }

        self::assertStringContainsString('(Open Graph)', (string) $this->completion->completeStructuredForConfigurationCalls[0]['options']?->getSystemPrompt());
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string>         $answer
     */
    private function suggest(array $body, array $answer): ResponseInterface
    {
        $this->completion                   = new FakeCompletionService();
        $this->completion->structuredResult = ['suggestions' => $answer];

        $configurationRepository = $this->createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findDefault')->willReturn(new LlmConfiguration());
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $subject = new FieldSuggestionController(
            new RecordContextReader(new RecordFinder(GeneralUtility::makeInstance(ConnectionPool::class))),
            new FieldSuggestionService($this->completion, new SlugSuggestionBuilder()),
            ConfigurationAccessDouble::selector($configurationRepository),
            $rateLimiter,
            GeneralUtility::makeInstance(Context::class),
            new NullLogger(),
        );

        return $subject->suggestAction(
            (new ServerRequest('https://example.com/typo3/ajax/cowriter/suggestions', 'POST'))
                ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR))),
        );
    }
}
