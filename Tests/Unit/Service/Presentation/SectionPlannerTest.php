<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Presentation;

use Netresearch\T3Cowriter\Service\Presentation\Block;
use Netresearch\T3Cowriter\Service\Presentation\BlockParser;
use Netresearch\T3Cowriter\Service\Presentation\SectionBoundary;
use Netresearch\T3Cowriter\Service\Presentation\SectionContent;
use Netresearch\T3Cowriter\Service\Presentation\SectionPlanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SectionPlanner::class)]
#[CoversClass(SectionBoundary::class)]
#[CoversClass(SectionContent::class)]
final class SectionPlannerTest extends TestCase
{
    private SectionPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new SectionPlanner();
    }

    /**
     * @return list<Block>
     */
    private function blocks(string $html): array
    {
        return (new BlockParser())->parse($html);
    }

    /**
     * @param list<SectionBoundary> $boundaries
     *
     * @return list<array{0: string, 1: int, 2: bool}>
     */
    private function plain(array $boundaries): array
    {
        return array_map(static fn (SectionBoundary $b): array => [$b->title, $b->start, $b->consumesHeading], $boundaries);
    }

    /**
     * @param list<SectionBoundary> $boundaries
     *
     * @return list<array{0: string, 1: string}>
     */
    private function cut(string $html, array $boundaries): array
    {
        return array_map(
            static fn (SectionContent $c): array => [$c->title, $c->html],
            $this->planner->contents($this->blocks($html), $boundaries),
        );
    }

    #[Test]
    public function aTextIsCutAtItsH2HeadingsWithTheTextBeforeThemAsIntroduction(): void
    {
        $html       = '<p>Intro</p><h2>Seat</h2><p>Height</p><h2>Warranty</h2><p>Five years</p>';
        $boundaries = $this->planner->fromHeadings($this->blocks($html), 'Introduction');

        self::assertSame([['Introduction', 0, false], ['Seat', 1, true], ['Warranty', 3, true]], $this->plain($boundaries));
        self::assertSame(
            [['Introduction', '<p>Intro</p>'], ['Seat', '<p>Height</p>'], ['Warranty', '<p>Five years</p>']],
            $this->cut($html, $boundaries),
        );
    }

    #[Test]
    public function aTextStartingWithAHeadingHasNoIntroduction(): void
    {
        $boundaries = $this->planner->fromHeadings($this->blocks('<h2>A</h2><p>1</p><h2>B</h2><p>2</p>'), 'Introduction');

        self::assertSame([['A', 0, true], ['B', 2, true]], $this->plain($boundaries));
    }

    #[Test]
    public function nestedHeadingsStayInsideTheSectionsOfTheHigherLevel(): void
    {
        $html       = '<h2>A</h2><h3>A.1</h3><p>1</p><h2>B</h2><h3>B.1</h3><p>2</p><h4>B.1.a</h4>';
        $boundaries = $this->planner->fromHeadings($this->blocks($html), 'Introduction');

        self::assertSame([['A', 0, true], ['B', 3, true]], $this->plain($boundaries));
        self::assertSame(
            [['A', "<h3>A.1</h3>\n<p>1</p>"], ['B', "<h3>B.1</h3>\n<p>2</p>\n<h4>B.1.a</h4>"]],
            $this->cut($html, $boundaries),
        );
    }

    #[Test]
    public function withoutH2TheH3HeadingsCut(): void
    {
        $boundaries = $this->planner->fromHeadings($this->blocks('<h3>A</h3><p>1</p><h4>x</h4><h3>B</h3><p>2</p>'), 'Introduction');

        self::assertSame([['A', 0, true], ['B', 3, true]], $this->plain($boundaries));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function textsWithoutTwoSections(): iterable
    {
        yield 'no headings' => ['<p>One</p><p>Two</p><p>Three</p>'];
        yield 'only h1 and h4' => ['<h1>Title</h1><p>One</p><h4>Small</h4><p>Two</p>'];
        yield 'one heading at the start' => ['<h2>Only</h2><p>One</p><p>Two</p>'];
        yield 'heading inside a wrapper' => ['<div><h2>A</h2><p>1</p><h2>B</h2></div>'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('textsWithoutTwoSections')]
    public function textsWithoutTwoHeadingSectionsAreLeftToTheModel(string $html): void
    {
        self::assertSame([], $this->planner->fromHeadings($this->blocks($html), 'Introduction'));
    }

    #[Test]
    public function moreSectionsThanTheMaximumAreLeftToTheModel(): void
    {
        $html = str_repeat('<h2>H</h2><p>t</p>', SectionPlanner::MAX_SECTIONS);
        self::assertCount(SectionPlanner::MAX_SECTIONS, $this->planner->fromHeadings($this->blocks($html), 'Introduction'));

        self::assertSame([], $this->planner->fromHeadings($this->blocks($html . '<h2>H</h2>'), 'Introduction'));
        self::assertSame([], $this->planner->fromHeadings($this->blocks('<p>i</p>' . $html), 'Introduction'));
    }

    #[Test]
    public function aHeadingWithoutTextBelowItGivesAnEmptySection(): void
    {
        $html       = '<h2>Empty</h2><h2>Full</h2><p>Text</p>';
        $boundaries = $this->planner->fromHeadings($this->blocks($html), 'Introduction');
        $contents   = $this->planner->contents($this->blocks($html), $boundaries);

        self::assertSame([['Empty', ''], ['Full', '<p>Text</p>']], $this->cut($html, $boundaries));
        self::assertSame('', $contents[0]->text);
        self::assertSame('Text', $contents[1]->text);
    }

    #[Test]
    public function headingTitlesArePlainText(): void
    {
        $boundaries = $this->planner->fromHeadings(
            $this->blocks('<h2>Seat <em>&amp;</em>&nbsp;back</h2><p>1</p><h2></h2><p>2</p>'),
            'Intro <b>x</b>',
        );

        self::assertSame(['Seat & back', ''], [$boundaries[0]->title, $boundaries[1]->title]);
    }

    #[Test]
    public function aModelAnswerIsOrderedAndItsFirstSectionStartsAtTheFirstBlock(): void
    {
        $blocks = $this->blocks('<p>0</p><p>1</p><p>2</p><p>3</p>');

        $boundaries = $this->planner->fromModel($blocks, [
            ['title' => 'Later', 'start' => 3],
            ['title' => 'First', 'start' => 1],
        ]);

        self::assertSame([['First', 0, false], ['Later', 3, false]], $this->plain($boundaries));
    }

    #[Test]
    public function unusableEntriesOfAModelAnswerAreDropped(): void
    {
        $blocks = $this->blocks('<p>0</p><p>1</p><p>2</p><p>3</p>');

        $boundaries = $this->planner->fromModel($blocks, [
            ['title' => 'A', 'start' => 0],
            ['title' => '  ', 'start' => 1],
            ['title' => 'Out of range', 'start' => 4],
            ['title' => 'Negative', 'start' => -1],
            ['title' => 'Not a number', 'start' => '2'],
            ['title' => 'Duplicate start', 'start' => 0],
            'not an object',
            ['title' => 'B <script>x</script>', 'start' => 2],
        ]);

        self::assertSame([['A', 0, false], ['B x', 2, false]], $this->plain($boundaries));
    }

    #[Test]
    public function aModelAnswerWithLessThanTwoUsableSectionsGivesNothing(): void
    {
        $blocks = $this->blocks('<p>0</p><p>1</p>');

        self::assertSame([], $this->planner->fromModel($blocks, [['title' => 'Only', 'start' => 0]]));
        self::assertSame([], $this->planner->fromModel($blocks, 'no list'));
        self::assertSame([], $this->planner->fromModel([], [['title' => 'A', 'start' => 0], ['title' => 'B', 'start' => 1]]));
    }

    #[Test]
    public function aModelAnswerIsCappedAtTheMaximum(): void
    {
        $blocks   = $this->blocks(str_repeat('<p>x</p>', 20));
        $sections = array_map(static fn (int $i): array => ['title' => 'S' . $i, 'start' => $i], range(0, 19));

        $boundaries = $this->planner->fromModel($blocks, $sections);

        self::assertCount(SectionPlanner::MAX_SECTIONS, $boundaries);
        self::assertSame('S11', $boundaries[SectionPlanner::MAX_SECTIONS - 1]->title);
    }

    #[Test]
    public function modelSectionsKeepEveryBlockInTheirBodies(): void
    {
        $html = '<p>0</p><h4>Sub</h4><p>2</p>';

        self::assertSame(
            [['A', '<p>0</p>'], ['B', "<h4>Sub</h4>\n<p>2</p>"]],
            $this->cut($html, $this->planner->fromModel($this->blocks($html), [['title' => 'A', 'start' => 0], ['title' => 'B', 'start' => 1]])),
        );
    }

    /**
     * @return iterable<string, array{list<SectionBoundary>, bool}>
     */
    public static function returnedSections(): iterable
    {
        yield 'as proposed' => [[new SectionBoundary('I', 0), new SectionBoundary('A', 1, true), new SectionBoundary('B', 3, true)], true];
        yield 'without consuming the headings' => [[new SectionBoundary('I', 0), new SectionBoundary('A', 1)], true];
        yield 'one section' => [[new SectionBoundary('I', 0)], false];
        yield 'too many' => [array_map(static fn (int $i): SectionBoundary => new SectionBoundary('x', $i), range(0, SectionPlanner::MAX_SECTIONS)), false];
        yield 'not from the first block' => [[new SectionBoundary('A', 1, true), new SectionBoundary('B', 3, true)], false];
        yield 'not increasing' => [[new SectionBoundary('I', 0), new SectionBoundary('B', 3, true), new SectionBoundary('A', 1, true)], false];
        yield 'same start twice' => [[new SectionBoundary('I', 0), new SectionBoundary('I', 0)], false];
        yield 'past the end' => [[new SectionBoundary('I', 0), new SectionBoundary('X', 5)], false];
        yield 'empty title' => [[new SectionBoundary('I', 0), new SectionBoundary('', 1, true)], false];
        yield 'consumes a paragraph' => [[new SectionBoundary('I', 0, true), new SectionBoundary('A', 1, true)], false];
    }

    /**
     * @param list<SectionBoundary> $boundaries
     */
    #[Test]
    #[DataProvider('returnedSections')]
    public function sectionsSentBackMustFitTheText(array $boundaries, bool $fits): void
    {
        // Blocks: 0 intro, 1 h2, 2 p, 3 h2, 4 p
        $blocks = $this->blocks('<p>Intro</p><h2>A</h2><p>1</p><h2>B</h2><p>2</p>');

        self::assertSame($fits, $this->planner->fits($blocks, $boundaries));
    }

    #[Test]
    public function titlesArePlainTextOfLimitedLength(): void
    {
        self::assertSame('Seat & back', SectionPlanner::title("<b>Seat</b> &amp;\n back"));
        self::assertSame(SectionPlanner::MAX_TITLE_LENGTH, mb_strlen(SectionPlanner::title(str_repeat('ä', 300))));
        self::assertSame('', SectionPlanner::title('<img src="x" onerror="alert(1)">'));
    }

    #[Test]
    public function theMaximumNumberOfSectionsFits(): void
    {
        $blocks     = $this->blocks(str_repeat('<p>x</p>', SectionPlanner::MAX_SECTIONS));
        $boundaries = array_map(static fn (int $i): SectionBoundary => new SectionBoundary('S', $i), range(0, SectionPlanner::MAX_SECTIONS - 1));

        self::assertTrue($this->planner->fits($blocks, $boundaries));
    }

    #[Test]
    public function titlesDecodeQuotesAndLoseTrailingSpaceWhereTheyAreCut(): void
    {
        self::assertSame('"Seat" & \'back\'', SectionPlanner::title('&quot;Seat&quot; &amp; &apos;back&#039;'));
        self::assertSame(str_repeat('a', 99), SectionPlanner::title(str_repeat('a', 99) . ' bcd'));
    }
}
