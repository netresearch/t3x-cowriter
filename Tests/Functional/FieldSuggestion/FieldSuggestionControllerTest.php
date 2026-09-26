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
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The suggestion endpoint against a real database, real backend users and
 * TYPO3's real SlugHelper; only the model is faked.
 */
#[CoversClass(FieldSuggestionController::class)]
#[CoversClass(RecordContextReader::class)]
#[CoversClass(RecordFinder::class)]
#[CoversClass(SlugSuggestionBuilder::class)]
final class FieldSuggestionControllerTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages_and_users.csv');
        $this->completion = new FakeCompletionService();
    }

    private function subject(): FieldSuggestionController
    {
        $configurationRepository = $this->createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findDefault')->willReturn(new LlmConfiguration());
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        return new FieldSuggestionController(
            new RecordContextReader(new RecordFinder(GeneralUtility::makeInstance(ConnectionPool::class))),
            new FieldSuggestionService($this->completion, new SlugSuggestionBuilder()),
            ConfigurationAccessDouble::selector($configurationRepository),
            $rateLimiter,
            GeneralUtility::makeInstance(Context::class),
            new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function suggest(array $body): ResponseInterface
    {
        $request = (new ServerRequest('https://example.com/typo3/ajax/cowriter/suggestions', 'POST'))
            ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));

        return $this->subject()->suggestAction($request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    #[Test]
    public function editorGetsThreeDescriptionSuggestionsBuiltFromThePage(): void
    {
        $this->setUpBackendUser(2);
        $this->completion->structuredResult = ['suggestions' => [
            'Ergonomic office chairs with adjustable seat height.',
            'Office chairs with lumbar support and five years warranty.',
            'Find the office chair that fits your back.',
        ]];

        $response = $this->suggest(['table' => 'pages', 'field' => 'description', 'uid' => 3, 'pid' => 3, 'count' => 3]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(3, self::json($response)['suggestions']);

        $prompt = $this->completion->completeStructuredForConfigurationCalls[0]['prompt'];
        self::assertStringContainsString('Page title: Office chairs', $prompt);
        self::assertStringContainsString('Stored value of the field: Stored description', $prompt);
        self::assertStringContainsString("Ergonomic chairs\nAdjustable seat height and lumbar support.", $prompt);
        self::assertStringContainsString('Five years on every frame.', $prompt);
    }

    #[Test]
    public function editorWithoutAccessToTheExcludeFieldIsRefused(): void
    {
        $this->setUpBackendUser(3);

        $response = $this->suggest(['table' => 'pages', 'field' => 'description', 'uid' => 3]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('You are not allowed to edit this field.', self::json($response)['error']);
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function editorWithoutEditPermissionOnThePageIsRefused(): void
    {
        $this->setUpBackendUser(2);

        $response = $this->suggest(['table' => 'pages', 'field' => 'description', 'uid' => 4]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function fieldWithoutTheControlIsRefusedEvenForAdmins(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->suggest(['table' => 'be_users', 'field' => 'username', 'uid' => 2]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function slugSuggestionsGetTheParentPathAndAreSanitisedByTypo3(): void
    {
        $this->setUpBackendUser(1);
        $this->completion->structuredResult = ['suggestions' => ['Ergonomic Office Chairs!', 'Bürostühle / Sitzmöbel', '???']];

        $response = $this->suggest(['table' => 'pages', 'field' => 'slug', 'uid' => 3, 'count' => 3]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            ['/products/ergonomic-office-chairs', '/products/buerostuehle-sitzmoebel'],
            self::json($response)['suggestions'],
        );
    }

    #[Test]
    public function slugOfANewPageUsesTheTargetPageAsParent(): void
    {
        $this->setUpBackendUser(1);
        $this->completion->structuredResult = ['suggestions' => ['Desks']];

        $response = $this->suggest(['table' => 'pages', 'field' => 'slug', 'uid' => 'NEW123', 'pid' => 2, 'count' => 1]);

        self::assertSame(['/products/desks'], self::json($response)['suggestions']);
    }

    #[Test]
    public function slugOfTheSiteRootIsNotSuggested(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->suggest(['table' => 'pages', 'field' => 'slug', 'uid' => 1]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }
}
