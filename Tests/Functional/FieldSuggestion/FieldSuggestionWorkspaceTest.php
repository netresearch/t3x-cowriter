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
 * Draft rows of workspaces: only the user's own workspace may reach the prompt.
 *
 * Fixture: page 3 and its content elements 10 and 11 are live. Workspace 2
 * (not the user's) has a draft of page 3 (uid 30), of element 10 (uid 20),
 * a new element (uid 22) and a new page (uid 32). Workspace 1 (the user's)
 * has a draft of page 3 (uid 31), of element 11 (uid 21) and deletes
 * element 10 (placeholder uid 23).
 */
#[CoversClass(RecordFinder::class)]
#[CoversClass(RecordContextReader::class)]
final class FieldSuggestionWorkspaceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor', 'workspaces'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
    ];

    private FakeCompletionService $completion;

    private const OTHER_WORKSPACE_TEXT = [
        'OTHER-WS-PAGE-TITLE', 'OTHER-WS-DESCRIPTION', 'SECRET-OTHER-WS-HEADER', 'SECRET-OTHER-WS-BODY',
        'NEW-ELEMENT-IN-OTHER-WS', 'NEW-PAGE-IN-OTHER-WS',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/workspaces.csv');
        $this->completion                   = new FakeCompletionService();
        $this->completion->structuredResult = ['suggestions' => ['One', 'Two', 'Three']];
    }

    private function suggestAs(int $workspaceId, int $uid): ResponseInterface
    {
        $backendUser            = $this->setUpBackendUser(1);
        $backendUser->workspace = $workspaceId;

        $configurationRepository = $this->createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findDefault')->willReturn(new LlmConfiguration());
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('checkLimit')->willReturn(new RateLimitResult(true, 20, 19, time() + 60));

        $subject = new FieldSuggestionController(
            new RecordContextReader(new RecordFinder(GeneralUtility::makeInstance(ConnectionPool::class))),
            new FieldSuggestionService($this->completion, new SlugSuggestionBuilder()),
            $configurationRepository,
            $rateLimiter,
            GeneralUtility::makeInstance(Context::class),
            new NullLogger(),
            GeneralUtility::makeInstance(LanguageServiceFactory::class),
        );
        $body = ['table' => 'pages', 'field' => 'description', 'uid' => $uid, 'count' => 3];

        return $subject->suggestAction(
            (new ServerRequest('https://example.com/typo3/ajax/cowriter/suggestions', 'POST'))
                ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR))),
        );
    }

    private function prompt(): string
    {
        self::assertCount(1, $this->completion->completeStructuredForConfigurationCalls);

        return $this->completion->completeStructuredForConfigurationCalls[0]['prompt'];
    }

    #[Test]
    public function liveUserSeesNoDraftOfAnyWorkspace(): void
    {
        $response = $this->suggestAs(0, 3);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $prompt = $this->prompt();
        self::assertStringContainsString('Stored value of the field: Stored description', $prompt);
        self::assertStringContainsString('Ergonomic chairs', $prompt);
        self::assertStringContainsString('Five years on every frame.', $prompt);
        foreach ([...self::OTHER_WORKSPACE_TEXT, 'Draft description in my workspace', 'Draft warranty in my workspace'] as $draft) {
            self::assertStringNotContainsString($draft, $prompt);
        }
    }

    #[Test]
    public function userInAWorkspaceGetsItsDraftsAndNothingOfOtherWorkspaces(): void
    {
        $response = $this->suggestAs(1, 3);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $prompt = $this->prompt();
        self::assertStringContainsString('Page title: Office chairs (draft)', $prompt);
        self::assertStringContainsString('Stored value of the field: Draft description in my workspace', $prompt);
        self::assertStringContainsString("Draft warranty in my workspace\nTen years on every frame.", $prompt);
        // Element 10 is deleted in workspace 1.
        self::assertStringNotContainsString('Ergonomic chairs', $prompt);
        self::assertStringNotContainsString('Five years on every frame.', $prompt);
        foreach (self::OTHER_WORKSPACE_TEXT as $draft) {
            self::assertStringNotContainsString($draft, $prompt);
        }
    }

    #[Test]
    public function versionRowOfAnotherWorkspaceCannotBeAddressed(): void
    {
        $response = $this->suggestAs(1, 30);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function versionRowOfTheOwnWorkspaceCannotBeAddressedDirectly(): void
    {
        $response = $this->suggestAs(1, 31);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function recordNewInAnotherWorkspaceCannotBeAddressed(): void
    {
        $response = $this->suggestAs(1, 32);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->completion->completeStructuredForConfigurationCalls);
    }

    #[Test]
    public function recordNewInTheOwnWorkspaceIsServed(): void
    {
        $response = $this->suggestAs(2, 32);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('Page title: NEW-PAGE-IN-OTHER-WS', $this->prompt());
    }
}
