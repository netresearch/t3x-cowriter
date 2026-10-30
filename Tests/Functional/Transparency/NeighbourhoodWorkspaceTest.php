<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Transparency;

use Netresearch\T3Cowriter\Domain\DTO\FieldSuggestionRequest;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContext;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContextReader;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Neighbouring pages as a workspace sees them.
 *
 * Fixture: siblings of page 3 under page 2. In workspace 1 page 4 is moved
 * to page 1 (move pointer 9), page 5 is deleted (placeholder 10), page 6 is
 * retitled (version 11) and page 8 is moved here from page 7 (move pointer
 * 12). Workspace 2 retitles page 6 too (version 13) and has a new page (14).
 */
#[CoversClass(RecordContextReader::class)]
#[CoversClass(RecordFinder::class)]
#[CoversClass(RecordContext::class)]
#[CoversClass(FieldSuggestionRequest::class)]
final class NeighbourhoodWorkspaceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor', 'workspaces'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/neighbourhood_workspaces.csv');
    }

    /**
     * @return list<string>
     */
    private function siblingsIn(int $workspaceId): array
    {
        $backendUser            = $this->setUpBackendUser(1);
        $backendUser->workspace = $workspaceId;

        $reader = new RecordContextReader(new RecordFinder(GeneralUtility::makeInstance(ConnectionPool::class)));

        return $reader->read(
            FieldSuggestionRequest::fromArray(['table' => 'tt_content', 'field' => 'header', 'uid' => 100]),
            $backendUser,
        )->siblingPageTitles;
    }

    #[Test]
    public function liveSeesTheLiveSiblings(): void
    {
        self::assertSame(['Moved away in workspace', 'Deleted in workspace', 'Live title'], $this->siblingsIn(0));
    }

    #[Test]
    public function workspaceSeesItsMovesDeletionsAndTitlesAndNothingOfAnotherWorkspace(): void
    {
        $titles = $this->siblingsIn(1);

        self::assertSame(['Title in workspace', 'Moved here in workspace'], $titles);
        self::assertNotContains('Moved away in workspace', $titles);
        self::assertNotContains('Deleted in workspace', $titles);
        self::assertNotContains('OTHER-WORKSPACE-TITLE', $titles);
        self::assertNotContains('NEW-IN-OTHER-WORKSPACE', $titles);
    }

    #[Test]
    public function findVisibleSubpagesListsTheMovedPageUnderItsLiveUid(): void
    {
        $finder = new RecordFinder(GeneralUtility::makeInstance(ConnectionPool::class));

        $pages = $finder->findVisibleSubpages(2, 1);

        self::assertSame([3, 6, 8], array_map(static fn (array $page): int => (int) $page['uid'], $pages));
    }
}
