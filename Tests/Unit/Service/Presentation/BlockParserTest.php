<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Presentation;

use Netresearch\T3Cowriter\Service\Presentation\Block;
use Netresearch\T3Cowriter\Service\Presentation\BlockParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlockParser::class)]
#[CoversClass(Block::class)]
final class BlockParserTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function parse(string $html): array
    {
        return array_map(
            static fn (Block $block): array => [$block->html, $block->text, $block->headingLevel],
            (new BlockParser())->parse($html),
        );
    }

    #[Test]
    public function everyTopLevelBlockElementIsABlockOfItsOwn(): void
    {
        self::assertSame(
            [
                ['<p>Intro text.</p>', 'Intro text.', 0],
                ['<h2>Seat</h2>', 'Seat', 2],
                ['<ul><li>Height</li><li>Depth</li></ul>', 'HeightDepth', 0],
                ['<h3 class="small">Details</h3>', 'Details', 3],
                ['<table><tbody><tr><td>A</td></tr></tbody></table>', 'A', 0],
            ],
            $this->parse('<p>Intro text.</p><h2>Seat</h2><ul><li>Height</li><li>Depth</li></ul><h3 class="small">Details</h3><table><tbody><tr><td>A</td></tr></tbody></table>'),
        );
    }

    #[Test]
    public function looseTextAndInlineElementsBetweenBlocksBecomeOneBlock(): void
    {
        self::assertSame(
            [
                ['Plain <strong>bold</strong> text', 'Plain bold text', 0],
                ['<h2>Next</h2>', 'Next', 2],
                ['tail', 'tail', 0],
            ],
            $this->parse('Plain <strong>bold</strong> text<h2>Next</h2>tail'),
        );
    }

    #[Test]
    public function whitespaceAndCommentsBetweenBlocksAreDropped(): void
    {
        self::assertSame(
            [
                ['<p>One</p>', 'One', 0],
                ['<p>Two</p>', 'Two', 0],
            ],
            $this->parse("<p>One</p>\r\n  <!-- note -->\n\t<p>Two</p>\n"),
        );
    }

    #[Test]
    public function aHeadingInsideAWrapperIsNotSplitOff(): void
    {
        $blocks = $this->parse('<div class="box"><h2>Inside</h2><p>Text</p></div>');

        self::assertCount(1, $blocks);
        self::assertSame(0, $blocks[0][2]);
        self::assertSame('InsideText', $blocks[0][1]);
    }

    #[Test]
    public function headingLevelsOneToSixAreRecognised(): void
    {
        self::assertSame(
            [1, 2, 3, 4, 5, 6],
            array_column($this->parse('<h1>a</h1><h2>b</h2><h3>c</h3><h4>d</h4><h5>e</h5><h6>f</h6>'), 2),
        );
    }

    #[Test]
    public function plainTextCollapsesWhitespaceAndNoBreakSpaces(): void
    {
        self::assertSame('Five years on every frame.', BlockParser::plainText("  Five\u{00A0}\u{00A0}years\n on\tevery frame. "));
        self::assertSame('Größe & Höhe', $this->parse('<p>Größe &amp; Höhe</p>')[0][1]);
    }

    #[Test]
    public function anEmptyTextHasNoBlocks(): void
    {
        self::assertSame([], $this->parse(''));
        self::assertSame([], $this->parse("  \n "));
    }

    #[Test]
    public function anElementWithoutTextStillCounts(): void
    {
        self::assertSame([['<p>a</p>', 'a', 0], ['<hr>', '', 0], ['<p>b</p>', 'b', 0]], $this->parse('<p>a</p><hr><p>b</p>'));
    }

    #[Test]
    public function inlineElementsInsideAWordAddNoSpace(): void
    {
        self::assertSame([['Pl<b>ai</b>n text', 'Plain text', 0]], $this->parse('Pl<b>ai</b>n text'));
    }

    #[Test]
    public function aLooseRunIsTrimmed(): void
    {
        self::assertSame(
            [['Loose text', 'Loose text', 0], ['<p>x</p>', 'x', 0]],
            $this->parse("\n  Loose text \n<p>x</p>"),
        );
    }
}
