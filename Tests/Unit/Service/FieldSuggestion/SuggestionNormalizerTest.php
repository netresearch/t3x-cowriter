<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\FieldSuggestion;

use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldKind;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldProfile;
use Netresearch\T3Cowriter\Service\FieldSuggestion\SuggestionNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SuggestionNormalizer::class)]
#[CoversClass(FieldProfile::class)]
final class SuggestionNormalizerTest extends TestCase
{
    private SuggestionNormalizer $subject;

    protected function setUp(): void
    {
        $this->subject = new SuggestionNormalizer();
    }

    #[Test]
    public function seoTitlesLongerThanSixtyCharactersAreCutAtAWordBoundary(): void
    {
        $long = 'Sustainable office furniture for small teams and growing companies in Leipzig';

        $result = $this->subject->normalize([$long], new FieldProfile(FieldKind::SeoTitle, 60), 3);

        self::assertSame(['Sustainable office furniture for small teams and growing'], $result);
        self::assertLessThanOrEqual(60, mb_strlen($result[0]));
    }

    #[Test]
    public function metaDescriptionsAreLimitedToOneHundredSixtyCharacters(): void
    {
        $long = str_repeat('Lorem ipsum dolor sit amet. ', 10);

        $result = $this->subject->normalize([$long], new FieldProfile(FieldKind::MetaDescription, 160), 1);

        self::assertLessThanOrEqual(160, mb_strlen($result[0]));
        // 157 characters: the cut falls inside "sit" and goes back to the space before it.
        self::assertSame(157, mb_strlen($result[0]));
        self::assertStringEndsWith('amet. Lorem ipsum dolor', $result[0]);
    }

    #[Test]
    public function valueWithinTheLimitIsKeptUnchanged(): void
    {
        self::assertSame(['Exactly ten'], $this->subject->normalize(['Exactly ten'], new FieldProfile(FieldKind::Text, 11), 1));
    }

    #[Test]
    public function cutAtAnExistingSpaceKeepsTheWholeWord(): void
    {
        // Character 11 is a space: the first ten characters are complete words.
        self::assertSame('Short text', $this->subject->truncate('Short text continues', 10));
    }

    #[Test]
    public function wordWithoutSpaceInTheSecondHalfIsCutHard(): void
    {
        self::assertSame('Donaudampf', $this->subject->truncate('Donaudampfschifffahrt', 10));
        // The only space lies before half of the limit: a hard cut keeps more text.
        self::assertSame('A Donaudam', $this->subject->truncate('A Donaudampfschifffahrt', 10));
    }

    #[Test]
    public function spaceExactlyAtHalfTheLimitIsAWordBoundary(): void
    {
        // The space sits at index 5, which is maxLength / 2.
        self::assertSame('Abcde', $this->subject->truncate('Abcde fghijklmnop', 10));
    }

    #[Test]
    public function trailingPunctuationIsRemovedAfterACut(): void
    {
        self::assertSame('Offers', $this->subject->truncate('Offers, deals and more', 8));
        self::assertSame('Offers', $this->subject->truncate('Offers – deals and more', 9));
    }

    #[Test]
    public function multibyteTextWithinTheLimitIsNotCut(): void
    {
        // 10 characters, 20 bytes.
        self::assertSame('ääääääääää', $this->subject->truncate('ääääääääää', 10));
    }

    #[Test]
    public function multibyteWordBoundaryIsFoundByCharacterPosition(): void
    {
        // Byte offsets would put the space outside the second half.
        self::assertSame('äääää äää', $this->subject->truncate('äääää äää ääää', 12));
        self::assertSame('ää äääää', $this->subject->truncate('ää äääää äääää', 9));
    }

    #[Test]
    public function duplicatesDifferingInUmlautCaseAreDropped(): void
    {
        $result = $this->subject->normalize(['ÄPFEL UND BIRNEN', 'äpfel und birnen'], new FieldProfile(FieldKind::Text, 60), 3);

        self::assertSame(['ÄPFEL UND BIRNEN'], $result);
    }

    #[Test]
    public function keywordLimitCountsCharactersNotBytes(): void
    {
        // "größe, übung" has 12 characters and 15 bytes.
        self::assertSame('größe, übung', $this->subject->keywords('größe, übung', 12));
        self::assertSame('Größe', $this->subject->keywords('Größe, größe', 12));
    }

    #[Test]
    public function keywordListIsAPlainListAfterDeduplication(): void
    {
        self::assertSame('a, b', $this->subject->keywords('a, A, b', 4));
    }

    #[Test]
    public function multibyteTextIsCountedInCharacters(): void
    {
        $result = $this->subject->truncate('Größenänderung überall möglich', 14);

        self::assertSame('Größenänderung', $result);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cleanProvider(): iterable
    {
        yield 'double quotes' => ['"Quoted title"', 'Quoted title'];
        yield 'typographic quotes' => ["\u{201E}Deutsch\u{201C}", 'Deutsch'];
        yield 'guillemets' => ["\u{00AB}Titre\u{00BB}", 'Titre'];
        yield 'single quotes' => ["'Single'", 'Single'];
        yield 'backticks' => ['`Code`', 'Code'];
        yield 'newlines and tabs' => ["Two\n\tlines", 'Two lines'];
        yield 'surrounding spaces' => ['   Spaced   ', 'Spaced'];
    }

    #[Test]
    #[DataProvider('cleanProvider')]
    public function cleanStripsQuotesAndCollapsesWhitespace(string $input, string $expected): void
    {
        self::assertSame($expected, $this->subject->clean($input));
    }

    /**
     * Values whose first or last byte also occurs inside a stripped quote or
     * dash character; a byte-wise trim() would cut them into invalid UTF-8.
     *
     * @return iterable<string, array{string}>
     */
    public static function multibyteEdgeProvider(): iterable
    {
        yield 'Cyrillic' => ['Компьютер'];
        yield 'CJK ending in 一' => ['统一'];
        yield 'starting with €' => ['€ 5 Rabatt'];
        yield 'ending in Ü' => ['GRÜNE WIESE Ü'];
        yield 'ending in ©' => ['Netresearch ©'];
    }

    #[Test]
    #[DataProvider('multibyteEdgeProvider')]
    public function cleanKeepsMultibyteCharactersAtTheEdges(string $value): void
    {
        $result = $this->subject->clean($value);

        self::assertSame($value, $result);
        self::assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    #[Test]
    #[DataProvider('multibyteEdgeProvider')]
    public function truncateKeepsMultibyteCharactersAtTheEnd(string $value): void
    {
        $result = $this->subject->truncate($value . ' – more text', mb_strlen($value) + 2);

        self::assertSame($value, $result);
        self::assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    #[Test]
    public function quotesAroundMultibyteTextAreStillStripped(): void
    {
        self::assertSame('Компьютер', $this->subject->clean("\u{201E}Компьютер\u{201C}"));
        self::assertSame('统一', $this->subject->clean('"统一"'));
        self::assertSame('€ 5', $this->subject->clean("\u{00AB}€ 5\u{00BB}"));
    }

    #[Test]
    public function trailingDashesAfterMultibyteTextAreStillStripped(): void
    {
        self::assertSame('Größe', $this->subject->truncate("Größe \u{2014} und mehr", 8));
        self::assertSame('Größe', $this->subject->truncate('Größe, und mehr', 7));
    }

    #[Test]
    public function duplicatesAreDroppedCaseInsensitively(): void
    {
        $result = $this->subject->normalize(
            ['Office Chairs', 'office chairs', '"Office chairs"', 'Desks'],
            new FieldProfile(FieldKind::SeoTitle, 60),
            3,
        );

        self::assertSame(['Office Chairs', 'Desks'], $result);
    }

    #[Test]
    public function emptyAndNonStringEntriesAreDropped(): void
    {
        $result = $this->subject->normalize(['', '   ', 42, null, ['x'], 'Valid'], new FieldProfile(FieldKind::Text, 60), 3);

        self::assertSame(['Valid'], $result);
    }

    #[Test]
    public function resultIsCappedToTheRequestedCount(): void
    {
        $result = $this->subject->normalize(['One', 'Two', 'Three', 'Four'], new FieldProfile(FieldKind::Text, 60), 2);

        self::assertSame(['One', 'Two'], $result);
    }

    #[Test]
    public function keywordListsAreTrimmedAndDeduplicated(): void
    {
        $result = $this->subject->normalize(
            [' office ; Chairs, desks,,chairs', "lamps\nlight"],
            new FieldProfile(FieldKind::Keywords, 255),
            2,
        );

        self::assertSame(['office, Chairs, desks', 'lamps, light'], $result);
    }

    #[Test]
    public function keywordListsDropWholeKeywordsToFitTheLimit(): void
    {
        // "alpha, beta, gamma" has 18 characters.
        self::assertSame('alpha, beta', $this->subject->keywords('alpha, beta, gamma', 17));
        self::assertSame('alpha, beta, gamma', $this->subject->keywords('alpha, beta, gamma', 18));
        self::assertSame('', $this->subject->keywords('averyveryverylongkeyword', 5));
    }

    #[Test]
    public function keywordsAreNotTruncatedMidWord(): void
    {
        $result = $this->subject->normalize(['alpha, beta, gamma'], new FieldProfile(FieldKind::Keywords, 17), 1);

        self::assertSame(['alpha, beta'], $result);
    }
}
