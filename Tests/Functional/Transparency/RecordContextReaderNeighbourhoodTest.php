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
 * The page neighbourhood a suggestion is built from — parent title, sibling
 * titles, page fields — against a real page tree, real page permissions and
 * real web mounts.
 *
 * Fixture: page 3 "Office chairs" under 2 "Products". Its siblings sort as
 * Desks (128), the page itself (256), seven siblings that must never be
 * listed (hidden, deleted, start time in the future, end time passed, folder,
 * menu separator, recycler, no show permission for the editor), then five
 * visible siblings of which the fifth listed is "Tables" and a sixth that
 * the cap of five cuts. Page 20 is the German translation of page 3, 21 of
 * page 2, 22 of "Desks"; the German translation of "Lamps" (23) is hidden.
 */
#[CoversClass(RecordContextReader::class)]
#[CoversClass(RecordFinder::class)]
#[CoversClass(RecordContext::class)]
#[CoversClass(FieldSuggestionRequest::class)]
final class RecordContextReaderNeighbourhoodTest extends FunctionalTestCase
{
    private const SIBLINGS_DEFAULT = ['Desks', 'Lamps', 'Shelves', 'Sofas', 'Tables'];

    private const SIBLINGS_GERMAN = ['Schreibtische', 'Lamps', 'Shelves', 'Sofas', 'Tables'];

    /**
     * Pages that must never be named as a neighbour, in any language.
     */
    private const NEVER_LISTED = [
        'HIDDEN-SIBLING', 'DELETED-SIBLING', 'FUTURE-SIBLING', 'EXPIRED-SIBLING', 'FOLDER-SIBLING',
        'SPACER-SIBLING', 'RECYCLER-SIBLING', 'NO-SHOW-SIBLING', 'SIXTH-VISIBLE-SIBLING',
        'HIDDEN-TRANSLATION-LAMPEN', 'TRANSLATION-OF-HIDDEN-SIBLING', 'Office chairs', 'Bürostühle',
    ];

    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/neighbourhood.csv');
    }

    private function read(int $userId, string $table, string $field, int $uid): RecordContext
    {
        $backendUser = $this->setUpBackendUser($userId);
        $reader      = new RecordContextReader(new RecordFinder(GeneralUtility::makeInstance(ConnectionPool::class)));

        return $reader->read(FieldSuggestionRequest::fromArray(['table' => $table, 'field' => $field, 'uid' => $uid]), $backendUser);
    }

    /**
     * @return array<string, string> field => value
     */
    private function values(array $fields): array
    {
        return array_map(static fn (array $entry): string => $entry['value'], $fields);
    }

    #[Test]
    public function contentInTheDefaultLanguageGetsParentAndFiveListableSiblingsInTreeOrder(): void
    {
        $context = $this->read(2, 'tt_content', 'header', 100);

        self::assertSame('Office chairs', $context->pageTitle);
        self::assertSame('Products', $context->parentPageTitle);
        self::assertSame(self::SIBLINGS_DEFAULT, $context->siblingPageTitles);
        self::assertCount(RecordContextReader::MAX_SIBLING_PAGES, $context->siblingPageTitles);
    }

    #[Test]
    public function contentInTheDefaultLanguageGetsTheDescriptivePageFieldsTheEditorMayRead(): void
    {
        $context = $this->read(2, 'tt_content', 'header', 100);

        $values = $this->values($context->pageFields);
        self::assertEqualsCanonicalizing(['nav_title', 'abstract', 'description', 'keywords'], array_keys($values));
        self::assertSame('Chair overview', $values['nav_title']);
        self::assertSame('Chairs for long working days.', $values['abstract']);
        self::assertSame('Office chairs with lumbar support.', $values['description']);
        self::assertSame('chairs, seating', $values['keywords']);

        // Title and slug have their own lines or none; author and TSconfig are
        // not descriptive; subtitle is an excluded field the editor has no right to.
        foreach (['title', 'slug', 'author', 'TSconfig', 'subtitle'] as $field) {
            self::assertArrayNotHasKey($field, $context->pageFields);
        }

        $flat = implode("\n", $values);
        self::assertStringNotContainsString('SUBTITLE-WITHOUT-RIGHT', $flat);
        self::assertStringNotContainsString('AUTHOR-NEVER-SENT', $flat);
        self::assertStringNotContainsString('TSCONFIG-NEVER-SENT', $flat);
    }

    #[Test]
    public function translatedContentGetsTheTitlesAndFieldsOfTheGermanPages(): void
    {
        $context = $this->read(2, 'tt_content', 'header', 101);

        self::assertSame('Bürostühle', $context->pageTitle);
        self::assertSame('Produkte', $context->parentPageTitle);
        // "Lamps" has a German translation, but a hidden one: the default title stands.
        self::assertSame(self::SIBLINGS_GERMAN, $context->siblingPageTitles);

        $values = $this->values($context->pageFields);
        self::assertEqualsCanonicalizing(['abstract', 'description'], array_keys($values));
        self::assertSame('Für lange Arbeitstage.', $values['abstract']);
        self::assertSame('Bürostühle mit Lordosenstütze.', $values['description']);
    }

    #[Test]
    public function noSiblingThatMustStayOutIsEverListed(): void
    {
        foreach ([100, 101] as $uid) {
            $titles = $this->read(2, 'tt_content', 'header', $uid)->siblingPageTitles;
            foreach (self::NEVER_LISTED as $title) {
                self::assertNotContains($title, $titles, sprintf('element %d', $uid));
            }
        }
    }

    #[Test]
    public function editorMountedBelowTheParentSeesNeitherParentNorSiblings(): void
    {
        // Web mount on page 3 only: page 2 and the siblings lie outside it.
        $context = $this->read(3, 'tt_content', 'header', 100);

        self::assertSame('Office chairs', $context->pageTitle);
        self::assertSame('', $context->parentPageTitle);
        self::assertSame([], $context->siblingPageTitles);
        self::assertArrayHasKey('description', $context->pageFields);
    }

    #[Test]
    public function hiddenParentIsNotNamed(): void
    {
        $context = $this->read(2, 'tt_content', 'header', 104);

        self::assertSame('Child of hidden section', $context->pageTitle);
        self::assertSame('', $context->parentPageTitle);
        self::assertSame(['Sibling under hidden section'], $context->siblingPageTitles);
    }

    #[Test]
    public function editingThePageItselfGetsNeighboursButNoPageFields(): void
    {
        $context = $this->read(2, 'pages', 'description', 3);

        self::assertSame('Office chairs', $context->pageTitle);
        self::assertSame('Products', $context->parentPageTitle);
        self::assertSame(self::SIBLINGS_DEFAULT, $context->siblingPageTitles);
        // The page's own fields travel as the record's fields.
        self::assertSame([], $context->pageFields);

        $values = $this->values($context->recordFields);
        self::assertSame('Chairs for long working days.', $values['abstract'] ?? null);
        self::assertSame('Office chairs with lumbar support.', $values['description'] ?? null);
        self::assertArrayNotHasKey('subtitle', $values);
        self::assertArrayNotHasKey('author', $values);
        self::assertArrayNotHasKey('TSconfig', $values);
    }

    #[Test]
    public function editingTheGermanPageTakesNeighboursFromTheDefaultLanguagePage(): void
    {
        $context = $this->read(2, 'pages', 'description', 20);

        self::assertSame('Bürostühle', $context->pageTitle);
        self::assertSame('Produkte', $context->parentPageTitle);
        // Page 3 is excluded by its default-language uid, under its default-language parent.
        self::assertSame(self::SIBLINGS_GERMAN, $context->siblingPageTitles);
        self::assertSame([], $context->pageFields);
        self::assertSame('Bürostühle mit Lordosenstütze.', $this->values($context->recordFields)['description'] ?? null);
    }
}
