<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\FieldSuggestion;

use Netresearch\T3Cowriter\Service\FieldSuggestion\ComposedContext;
use Netresearch\T3Cowriter\Service\FieldSuggestion\ContextComposer;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContext;
use Netresearch\T3Cowriter\Service\FieldSuggestion\UntrustedDataFence;
use Netresearch\T3Cowriter\Service\Transparency\ContextSources;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContextComposer::class)]
#[CoversClass(ComposedContext::class)]
#[CoversClass(ContextSources::class)]
#[CoversClass(RecordContext::class)]
#[CoversClass(UntrustedDataFence::class)]
final class ContextComposerTest extends TestCase
{
    /**
     * @param array<string, array{label: string, value: string}> $recordFields
     * @param array<string, array{label: string, value: string}> $pageFields
     * @param list<string>                                       $siblings
     */
    private function context(
        string $pageContent = "Header one Body one\n\nHeader two Body two",
        array $recordFields = ['header' => ['label' => 'Header', 'value' => 'Our test'], 'bodytext' => ['label' => 'Text', 'value' => 'We tested.']],
        array $pageFields = ['abstract' => ['label' => 'Abstract', 'value' => 'Twelve fryers'], 'keywords' => ['label' => 'Keywords', 'value' => 'fryer']],
        string $parent = 'Kitchen',
        array $siblings = ['Toasters', 'Mixers'],
    ): RecordContext {
        return new RecordContext(
            table: 'tt_content',
            field: 'header',
            record: [],
            storedValue: 'Our test',
            pageTitle: 'Airfryer Test',
            pageContent: $pageContent,
            slugPid: 3,
            recordFields: $recordFields,
            pageFields: $pageFields,
            parentPageTitle: $parent,
            siblingPageTitles: $siblings,
        );
    }

    #[Test]
    public function everySourceGoesInFencedInItsOrderAndIsCounted(): void
    {
        $composed = (new ContextComposer())->compose($this->context(), ['Stored value of the field: Our test'], ['header'], true);

        $fence = new UntrustedDataFence();
        self::assertSame($fence->wrap(implode("\n", [
            'Page title: Airfryer Test',
            'Stored value of the field: Our test',
            'Other fields of the edited record:',
            '- Text: We tested.',
            'Fields of the page:',
            '- Abstract: Twelve fryers',
            '- Keywords: fryer',
            'Title of the parent page: Kitchen',
            'Titles of neighbouring pages:',
            '- Toasters',
            '- Mixers',
            'Page content:',
            "Header one Body one\n\nHeader two Body two",
        ])), $composed->message);

        self::assertEquals(new ContextSources(
            pageTitle: true,
            fieldValues: true,
            pageFields: 2,
            recordFields: 1,
            contentElements: 2,
            parentTitle: true,
            siblingTitles: 2,
        ), $composed->sources);
    }

    #[Test]
    public function emptySourcesAreNeitherSentNorCounted(): void
    {
        $composed = (new ContextComposer())->compose(
            $this->context('', ['header' => ['label' => 'Header', 'value' => '  ']], ['abstract' => ['label' => 'Abstract', 'value' => '']], ' ', [' ', '']),
            [],
            [],
            false,
            true,
        );

        self::assertStringNotContainsString('Other fields', $composed->message);
        self::assertStringNotContainsString('Fields of the page', $composed->message);
        self::assertStringNotContainsString('parent page', $composed->message);
        self::assertStringNotContainsString('neighbouring', $composed->message);
        self::assertStringContainsString("Page content:\n(no content yet)", $composed->message);
        self::assertEquals(new ContextSources(pageTitle: true, reviewedFields: true), $composed->sources);
    }

    #[Test]
    public function aLongContextIsCutNeighboursFirstThenParentThenContent(): void
    {
        $siblings = ['Toasters', 'Mixers', 'Kettles'];
        $content  = str_repeat('a', ContextComposer::MAX_DATA_LENGTH);
        $composed = (new ContextComposer())->compose($this->context($content, siblings: $siblings), [], [], true);

        self::assertLessThanOrEqual(ContextComposer::MAX_DATA_LENGTH, mb_strlen($composed->message));
        // Content is cut only after every neighbour and the parent are gone.
        self::assertSame(0, $composed->sources->siblingTitles);
        self::assertFalse($composed->sources->parentTitle);
        self::assertSame(1, $composed->sources->contentElements);
        self::assertSame(2, $composed->sources->pageFields);
        self::assertSame(2, $composed->sources->recordFields);
        // The cut keeps as much content as fits.
        self::assertGreaterThan(ContextComposer::MAX_DATA_LENGTH - 400, mb_strlen($composed->message));
    }

    #[Test]
    public function oneNeighbourTooManyDropsOnlyTheLastNeighbour(): void
    {
        $composer = new ContextComposer();
        $base     = $composer->compose($this->context('x', siblings: ['A']), [], [], true)->message;
        // Fill the content so that exactly one more neighbour line ("- B" plus newline) would not fit.
        $fill     = str_repeat('x', ContextComposer::MAX_DATA_LENGTH - mb_strlen($base) + 1);
        $composed = $composer->compose($this->context($fill, siblings: ['A', 'B']), [], [], true);

        self::assertSame(1, $composed->sources->siblingTitles);
        self::assertStringContainsString("- A\nPage content:", $composed->message);
        self::assertTrue($composed->sources->parentTitle);
        self::assertSame(1, $composed->sources->contentElements);
    }

    #[Test]
    public function contentElementsAreCountedFromWhatSurvivedTheCut(): void
    {
        $element  = str_repeat('e', 3000);
        $content  = implode("\n\n", array_fill(0, 8, $element));
        $composed = (new ContextComposer())->compose($this->context($content, [], [], '', []), [], [], true);

        self::assertLessThanOrEqual(ContextComposer::MAX_DATA_LENGTH, mb_strlen($composed->message));
        self::assertSame(6, $composed->sources->contentElements);
    }

    #[Test]
    public function pageFieldsGoBeforeRecordFieldsAndTheFieldValuesAreNeverCut(): void
    {
        $long       = str_repeat('v', ContextComposer::MAX_DATA_LENGTH);
        $pageFields = ['abstract' => ['label' => 'Abstract', 'value' => 'P1'], 'keywords' => ['label' => 'Keywords', 'value' => 'P2']];
        $composed   = (new ContextComposer())->compose(
            $this->context('content', pageFields: $pageFields),
            ['- seo_title: ' . $long],
            [],
            true,
        );

        self::assertStringContainsString($long, $composed->message);
        self::assertSame(0, $composed->sources->pageFields);
        self::assertSame(0, $composed->sources->recordFields);
        self::assertSame(0, $composed->sources->contentElements);
        self::assertTrue($composed->sources->fieldValues);
    }

    #[Test]
    public function aRecordFieldIsDroppedOnlyWhenNoPageFieldIsLeft(): void
    {
        $composer = new ContextComposer();
        $records  = ['header' => ['label' => 'Header', 'value' => 'H'], 'bodytext' => ['label' => 'Text', 'value' => 'T']];
        $pages    = ['abstract' => ['label' => 'Abstract', 'value' => 'P']];
        $base     = $composer->compose($this->context('', $records, $pages, '', []), [], [], true)->message;
        // Enough value text to push out the page field (and the content placeholder stays).
        $fill     = str_repeat('v', ContextComposer::MAX_DATA_LENGTH - mb_strlen($base) + 1);
        $composed = $composer->compose($this->context('', $records, $pages, '', []), [$fill], [], true);

        self::assertSame(0, $composed->sources->pageFields);
        self::assertSame(2, $composed->sources->recordFields);
    }
}
