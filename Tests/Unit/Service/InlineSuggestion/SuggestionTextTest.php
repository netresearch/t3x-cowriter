<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\InlineSuggestion;

use Netresearch\T3Cowriter\Service\InlineSuggestion\SuggestionText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SuggestionText::class)]
final class SuggestionTextTest extends TestCase
{
    private const BEFORE = 'The keeper climbed the stairs';

    #[Test]
    #[DataProvider('answers')]
    public function cleansTheAnswerIntoOneLineOfPlainText(string $answer, string $expected): void
    {
        self::assertSame($expected, SuggestionText::clean($answer, self::BEFORE));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function answers(): iterable
    {
        yield 'plain' => ['every evening.', ' every evening.'];
        yield 'surrounding whitespace' => ["  every evening.\n", ' every evening.'];
        yield 'first sentence only' => ['every evening. Then he slept!', ' every evening.'];
        yield 'question mark ends the sentence' => ['every evening? Yes.', ' every evening?'];
        yield 'a decimal point does not end it' => ['3.5 times a day.', ' 3.5 times a day.'];
        yield 'line breaks become spaces' => ["every\nevening\r\nagain.", ' every evening again.'];
        yield 'Unicode line and paragraph separators' => ["every\u{2028}evening\u{2029}again", ' every evening again'];
        yield 'tabs and control characters' => ["every\tevening\u{0007}", ' every evening'];
        yield 'runs of spaces' => ['every    evening  again', ' every evening again'];
        yield 'direction overrides and zero-width characters' => ["ev\u{202E}ery\u{200B} even\u{2066}ing", ' every evening'];
        yield 'tags' => ['<p>every <strong>evening</strong></p>', ' every evening'];
        yield 'script element keeps no markup' => ['<script>alert(1)</script>every evening', ' alert(1)every evening'];
        yield 'entities decoded, not escaped' => ['salt &amp; pepper &quot;fresh&quot;', ' salt & pepper "fresh"'];
        yield 'an encoded tag is removed too' => ['&lt;img src=x onerror=alert(1)&gt;every evening', ' every evening'];
        yield 'reasoning block' => ['<think>The user wants…</think>every evening.', ' every evening.'];
        yield 'unclosed reasoning block' => ['<think>The user wants more', ''];
        yield 'Markdown emphasis and code' => ['**every** __evening__ `now`', ' every evening now'];
        yield 'Markdown list marker' => ['- every evening', ' every evening'];
        yield 'Markdown heading marker' => ['## every evening', ' every evening'];
        yield 'numbered list marker' => ['1. every evening', ' every evening'];
        yield 'quotes around the answer' => ['"every evening"', ' every evening'];
        yield 'typographic quotes around the answer' => ['„every evening“', ' every evening'];
        yield 'closing punctuation takes no space' => [', every evening.', ', every evening.'];
        yield 'a full stop takes no space' => ['.', '.'];
        yield 'abbreviations with single letters' => ['z. B. bei Regen und Wind.', ' z. B. bei Regen und Wind.'];
        yield 'a number after an abbreviation' => ['ca. 3 km entfernt. Dann mehr.', ' ca. 3 km entfernt.'];
        yield 'a title before a name' => ['Dr. Müller kam. Dann ging er.', ' Dr. Müller kam.'];
        yield 'single initial before a name' => ['J. Smith came. Then he left.', ' J. Smith came.'];
        yield 'a lower-case word after a full stop' => ['every evening. then more', ' every evening. then more'];
        yield 'the next sentence opens with a quote' => ['sagte er. „Gut.“', ' sagte er.'];
        yield 'less-than in text' => ['wenn a<b gilt.', ' wenn a<b gilt.'];
        yield 'comparison with spaces' => ['x < 3 and y > 2', ' x < 3 and y > 2'];
        yield 'HTML comment' => ['every<!-- note --> evening', ' every evening'];
        yield 'two quoted parts keep their quotes' => ['"A" und "B"', ' "A" und "B"'];
        yield 'empty' => ['', ''];
        yield 'only whitespace and markup' => [" <br> \n ", ''];
    }

    #[Test]
    public function noSpaceWhenTheTextEndsInOne(): void
    {
        self::assertSame('every evening.', SuggestionText::clean(' every evening.', self::BEFORE . ' '));
        self::assertSame('every evening.', SuggestionText::clean('every evening.', self::BEFORE . "\u{00A0}"));
    }

    #[Test]
    public function noSpaceWithoutTextBefore(): void
    {
        self::assertSame('Every evening.', SuggestionText::clean('Every evening.', ''));
    }

    #[Test]
    public function isCutAtAWordBoundaryAtTheMaximumLength(): void
    {
        // 11 characters per word: character 200 is inside a word.
        $answer = str_repeat('lighthouse ', 30);

        $clean = SuggestionText::clean($answer, self::BEFORE . ' ');

        self::assertLessThanOrEqual(SuggestionText::MAX_LENGTH, mb_strlen($clean));
        self::assertGreaterThan(SuggestionText::MAX_LENGTH - 12, mb_strlen($clean));
        self::assertStringEndsWith(' lighthouse', $clean);
    }

    #[Test]
    public function aWordLongerThanHalfTheMaximumIsCutInside(): void
    {
        $clean = SuggestionText::clean(str_repeat('ä', SuggestionText::MAX_LENGTH + 50), self::BEFORE . ' ');

        self::assertSame(str_repeat('ä', SuggestionText::MAX_LENGTH), $clean);
    }

    #[Test]
    public function theInstructionNamesTheLanguageWhenKnown(): void
    {
        self::assertStringEndsWith("\nWrite in German (Germany).", SuggestionText::instruction('German (Germany)'));
        self::assertStringEndsWith("\nWrite in the language of the text.", SuggestionText::instruction(null));
        self::assertStringContainsString('at most one sentence', SuggestionText::instruction(null));
        self::assertStringContainsString('plain text only', SuggestionText::instruction(null));
    }
}
