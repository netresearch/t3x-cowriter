<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Transparency;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Testing\FakeCompletionService;
use Netresearch\T3Cowriter\Controller\FieldSuggestionController;
use Netresearch\T3Cowriter\Service\FieldSuggestion\ComposedContext;
use Netresearch\T3Cowriter\Service\FieldSuggestion\ContextComposer;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionService;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContextReader;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\FieldSuggestion\SlugSuggestionBuilder;
use Netresearch\T3Cowriter\Service\FieldSuggestion\SuggestionResult;
use Netresearch\T3Cowriter\Service\RateLimiterInterface;
use Netresearch\T3Cowriter\Service\RateLimitResult;
use Netresearch\T3Cowriter\Service\Transparency\AboutBuilder;
use Netresearch\T3Cowriter\Service\Transparency\ContextSources;
use Netresearch\T3Cowriter\Service\Transparency\ModelUse;
use Netresearch\T3Cowriter\Tests\Support\ConfigurationAccessDouble;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The suggestion endpoint end to end with only the model faked: the page
 * neighbourhood reaches the message, and the "About" sources name what went
 * out, counted from the message itself.
 */
#[CoversClass(FieldSuggestionController::class)]
#[CoversClass(FieldSuggestionService::class)]
#[CoversClass(ContextComposer::class)]
#[CoversClass(ComposedContext::class)]
#[CoversClass(SuggestionResult::class)]
#[CoversClass(ContextSources::class)]
#[CoversClass(AboutBuilder::class)]
#[CoversClass(ModelUse::class)]
#[CoversClass(RecordContextReader::class)]
#[CoversClass(RecordFinder::class)]
final class ContextComposerSourcesTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
    ];

    private FakeCompletionService $completion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/neighbourhood.csv');
        $this->completion                   = new FakeCompletionService();
        $this->completion->structuredResult = ['suggestions' => ['One', 'Two', 'Three']];
    }

    private function suggest(int $uid): ResponseInterface
    {
        $this->setUpBackendUser(2);

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
            about: $this->get(AboutBuilder::class),
        );
        $body = ['table' => 'tt_content', 'field' => 'header', 'uid' => $uid, 'count' => 3];

        return $subject->suggestAction(
            (new ServerRequest('https://example.com/typo3/ajax/cowriter/suggestions', 'POST'))
                ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR))),
        );
    }

    private function message(): string
    {
        self::assertCount(1, $this->completion->completeStructuredForConfigurationCalls);

        return $this->completion->completeStructuredForConfigurationCalls[0]['prompt'];
    }

    /**
     * The "- " lines that follow $heading in the message.
     *
     * @return list<string>
     */
    private function listUnder(string $message, string $heading): array
    {
        $lines = explode("\n", $message);
        $start = array_search($heading, $lines, true);
        if ($start === false) {
            return [];
        }

        $items = [];
        for ($i = $start + 1; $i < count($lines) && str_starts_with($lines[$i], '- '); ++$i) {
            $items[] = substr($lines[$i], 2);
        }

        return $items;
    }

    #[Test]
    public function messageCarriesParentSiblingsAndPageFields(): void
    {
        $response = $this->suggest(100);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $message = $this->message();
        self::assertStringContainsString("Page title: Office chairs\n", $message);
        self::assertStringContainsString("\nTitle of the parent page: Products\n", $message);
        self::assertSame(['Desks', 'Lamps', 'Shelves', 'Sofas', 'Tables'], $this->listUnder($message, 'Titles of neighbouring pages:'));

        $pageFields = implode("\n", $this->listUnder($message, 'Fields of the page:'));
        self::assertCount(4, $this->listUnder($message, 'Fields of the page:'));
        foreach (['Chair overview', 'Chairs for long working days.', 'Office chairs with lumbar support.', 'chairs, seating'] as $value) {
            self::assertStringContainsString($value, $pageFields);
        }

        foreach (['SUBTITLE-WITHOUT-RIGHT', 'AUTHOR-NEVER-SENT', 'TSCONFIG-NEVER-SENT', 'NO-SHOW-SIBLING', 'HIDDEN-SIBLING'] as $never) {
            self::assertStringNotContainsString($never, $message);
        }
    }

    #[Test]
    public function translatedElementGetsTheGermanNeighbourhood(): void
    {
        $response = $this->suggest(101);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $message = $this->message();
        self::assertStringContainsString("Page title: Bürostühle\n", $message);
        self::assertStringContainsString("\nTitle of the parent page: Produkte\n", $message);
        self::assertSame(['Schreibtische', 'Lamps', 'Shelves', 'Sofas', 'Tables'], $this->listUnder($message, 'Titles of neighbouring pages:'));
        self::assertStringContainsString('Bürostühle mit Lordosenstütze.', $message);
    }

    #[Test]
    public function aboutSourcesCountWhatTheMessageCarries(): void
    {
        $response = $this->suggest(100);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $message = $this->message();
        /** @var array{about: array{sources: list<string>, links: list<mixed>, feature: string}} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $recordFields = count($this->listUnder($message, 'Other fields of the edited record:'));
        self::assertSame(
            [
                'Page title',
                'Stored and typed value of the field',
                count($this->listUnder($message, 'Fields of the page:')) . ' page fields',
                $recordFields === 1 ? '1 other field of this record' : $recordFields . ' other fields of this record',
                'Content of 1 element',
                'Title of the parent page',
                'Titles of ' . count($this->listUnder($message, 'Titles of neighbouring pages:')) . ' neighbouring pages',
            ],
            $data['about']['sources'],
        );
        // The counts above are not vacuous: the message holds them.
        self::assertSame('4 page fields', $data['about']['sources'][2]);
        self::assertSame('Titles of 5 neighbouring pages', $data['about']['sources'][6]);
        self::assertGreaterThan(0, $recordFields);
        self::assertStringContainsString('Adjustable seat height and lumbar support.', $message);
        // An editor gets no link.
        self::assertSame([], $data['about']['links']);
    }
}
