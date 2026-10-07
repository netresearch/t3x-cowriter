<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Context;

use Netresearch\T3Cowriter\Service\ContextAssemblyService;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The task context holds only what the backend user may read: content
 * elements with read access to tt_content, in the user's languages, on pages
 * the user may show, and as the user's workspace shows them.
 */
#[CoversClass(ContextAssemblyService::class)]
#[CoversClass(RecordFinder::class)]
final class ContextAssemblyServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor', 'workspaces'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
    ];

    private function subject(): ContextAssemblyService
    {
        return new ContextAssemblyService(GeneralUtility::makeInstance(ConnectionPool::class));
    }

    private function contextAs(int $userUid, int $elementUid, string $scope, int $workspaceId = 0): string
    {
        $backendUser            = $this->setUpBackendUser($userUid);
        $backendUser->workspace = $workspaceId;

        return $this->subject()->assembleContext('tt_content', $elementUid, 'bodytext', $scope);
    }

    #[Test]
    public function editorGetsThePageContentInTheLanguagesTheyMayEdit(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/permissions.csv');

        $context = $this->contextAs(2, 10, 'page');

        self::assertStringContainsString('Default language body.', $context);
        self::assertStringNotContainsString('Second language body.', $context);
    }

    #[Test]
    public function editorGetsNoElementInALanguageTheyMayNotEdit(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/permissions.csv');

        self::assertSame('', $this->contextAs(2, 11, 'element'));
    }

    #[Test]
    public function editorGetsNoElementOnTheRootLevel(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/permissions.csv');

        self::assertSame('', $this->contextAs(2, 12, 'element'));
    }

    #[Test]
    public function adminGetsAnElementOnTheRootLevel(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/permissions.csv');

        self::assertStringContainsString('Root level body.', $this->contextAs(1, 12, 'element'));
    }

    #[Test]
    public function userWithoutReadAccessToContentElementsGetsNoContext(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/permissions.csv');

        self::assertSame('', $this->contextAs(3, 10, 'page'));
        self::assertSame(0, $this->subject()->getContextSummary('tt_content', 10, 'bodytext', 'page')['wordCount']);
    }

    #[Test]
    public function liveUserGetsNoDraftOfAnyWorkspace(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../FieldSuggestion/Fixtures/workspaces.csv');

        $context = $this->contextAs(1, 10, 'page');

        self::assertStringContainsString('Ergonomic chairs', $context);
        self::assertStringContainsString('Five years on every frame.', $context);
        foreach (['SECRET-OTHER-WS-HEADER', 'NEW-ELEMENT-IN-OTHER-WS', 'Draft warranty in my workspace'] as $draft) {
            self::assertStringNotContainsString($draft, $context);
        }
    }

    #[Test]
    public function userInAWorkspaceGetsItsDraftsAndNothingOfOtherWorkspaces(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../FieldSuggestion/Fixtures/workspaces.csv');

        $context = $this->contextAs(1, 11, 'page', 1);

        self::assertStringContainsString('Draft warranty in my workspace', $context);
        self::assertStringContainsString('Ten years on every frame.', $context);
        // Element 10 is deleted in workspace 1.
        self::assertStringNotContainsString('Ergonomic chairs', $context);
        foreach (['SECRET-OTHER-WS-HEADER', 'NEW-ELEMENT-IN-OTHER-WS'] as $draft) {
            self::assertStringNotContainsString($draft, $context);
        }
    }

    #[Test]
    public function liveUserFollowsTheLivePageTreeToTheParent(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/workspace_move.csv');

        $context = $this->contextAs(1, 10, 'ancestors_1');

        self::assertStringContainsString('PRODUCTS-PAGE-ELEMENT', $context);
        self::assertStringNotContainsString('HOME-PAGE-ELEMENT', $context);
    }

    #[Test]
    public function userInAWorkspaceFollowsAPageMovedThereToItsNewParent(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/workspace_move.csv');

        // Workspace 1 moves page 3 from below page 2 to below page 1.
        $context = $this->contextAs(1, 10, 'ancestors_1', 1);

        self::assertStringContainsString('HOME-PAGE-ELEMENT', $context);
        self::assertStringNotContainsString('PRODUCTS-PAGE-ELEMENT', $context);
    }

    #[Test]
    public function liveUserFindsAContentElementOnItsLivePage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/content_move.csv');

        self::assertStringContainsString('MOVED-ELEMENT', $this->contextAs(1, 10, 'page'));
        self::assertStringNotContainsString('MOVED-ELEMENT', $this->contextAs(1, 40, 'page'));
    }

    #[Test]
    public function userInAWorkspaceFindsAContentElementMovedThereOnItsNewPageOnly(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/content_move.csv');

        // Workspace 1 moves element 50 from page 3 to page 2.
        self::assertStringNotContainsString('MOVED-ELEMENT', $this->contextAs(1, 10, 'page', 1));

        $newPage = $this->contextAs(1, 40, 'page', 1);
        self::assertSame(1, substr_count($newPage, 'Header: MOVED-ELEMENT'));
        self::assertStringContainsString('(tt_content #50)', $newPage);
        self::assertStringNotContainsString('(tt_content #51)', $newPage);
    }

    #[Test]
    public function userInAWorkspaceFindsAContentElementReorderedOnItsPageOnceAtItsNewPosition(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/same_page_move.csv');

        // Workspace 1 moves element 10 behind element 11 on the same page.
        $context = $this->contextAs(1, 11, 'page', 1);

        self::assertSame(1, substr_count($context, 'Header: Ergonomic chairs'));
        self::assertLessThan(
            strpos($context, 'Header: Ergonomic chairs'),
            strpos($context, 'Header: Second element'),
        );
    }

    #[Test]
    public function visibilityIsJudgedAsTheWorkspaceShowsTheElement(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/visibility.csv');

        $live = $this->contextAs(1, 10, 'page');
        self::assertStringNotContainsString('SHOWN-IN-WORKSPACE', $live);
        self::assertStringNotContainsString('EXPIRED-ELEMENT', $live);

        // Workspace 1 shows element 13, which is hidden live.
        $workspace = $this->contextAs(1, 10, 'page', 1);
        self::assertStringContainsString('SHOWN-IN-WORKSPACE', $workspace);
        self::assertStringNotContainsString('EXPIRED-ELEMENT', $workspace);
    }

    #[Test]
    public function ancestorScopeStartsAtThePageOfTheRequestedElement(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/content_move.csv');

        $context = $this->contextAs(1, 10, 'ancestors_1', 1);

        self::assertStringContainsString('Ergonomic chairs', $context);
        self::assertStringContainsString('PRODUCTS-PAGE-ELEMENT', $context);
    }

    #[Test]
    public function versionRowOfAnotherWorkspaceCannotBeAddressed(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../FieldSuggestion/Fixtures/workspaces.csv');

        self::assertSame('', $this->contextAs(1, 20, 'element'));
    }
}
