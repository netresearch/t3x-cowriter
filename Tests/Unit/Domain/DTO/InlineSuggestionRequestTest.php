<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Domain\DTO;

use Netresearch\T3Cowriter\Domain\DTO\InlineSuggestionRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InlineSuggestionRequest::class)]
final class InlineSuggestionRequestTest extends TestCase
{
    #[Test]
    public function keepsTheTextAsSentIncludingATrailingSpace(): void
    {
        $request = InlineSuggestionRequest::fromRequestBody(['text' => "  The keeper \n"]);

        self::assertSame("  The keeper \n", $request->text);
        self::assertNull($request->recordContext);
    }

    #[Test]
    #[DataProvider('nonStrings')]
    public function aTextThatIsNoStringIsEmpty(mixed $text): void
    {
        self::assertSame('', InlineSuggestionRequest::fromRequestBody(['text' => $text])->text);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonStrings(): iterable
    {
        yield 'null' => [null];
        yield 'number' => [42];
        yield 'array' => [['The keeper']];
        yield 'bool' => [true];
    }

    #[Test]
    public function aMissingTextIsEmpty(): void
    {
        self::assertSame('', InlineSuggestionRequest::fromRequestBody([])->text);
    }

    #[Test]
    public function readsTheRecordContext(): void
    {
        $request = InlineSuggestionRequest::fromRequestBody([
            'text'          => 'The keeper',
            'recordContext' => ['table' => 'tt_content', 'uid' => 12, 'field' => 'bodytext'],
        ]);

        self::assertSame(['table' => 'tt_content', 'uid' => 12], $request->recordContext);
    }

    #[Test]
    #[DataProvider('malformedRecordContexts')]
    public function aMalformedRecordContextIsNone(mixed $recordContext): void
    {
        $request = InlineSuggestionRequest::fromRequestBody(['text' => 'The keeper', 'recordContext' => $recordContext]);

        self::assertNull($request->recordContext);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedRecordContexts(): iterable
    {
        yield 'not an array' => ['tt_content:12'];
        yield 'no uid' => [['table' => 'tt_content']];
        yield 'uid zero' => [['table' => 'tt_content', 'uid' => 0]];
        yield 'negative uid' => [['table' => 'tt_content', 'uid' => -3]];
        yield 'uid as text' => [['table' => 'tt_content', 'uid' => '12']];
        yield 'NEW uid' => [['table' => 'tt_content', 'uid' => 'NEW123']];
        yield 'no table' => [['uid' => 12]];
        yield 'table with SQL' => [['table' => 'tt_content; DROP TABLE be_users', 'uid' => 12]];
        yield 'table starting with a digit' => [['table' => '1pages', 'uid' => 12]];
        yield 'table not a string' => [['table' => ['pages'], 'uid' => 12]];
    }

    #[Test]
    public function measuresLengthsInCharacters(): void
    {
        $atMaximum = new InlineSuggestionRequest(str_repeat('ü', InlineSuggestionRequest::MAX_TEXT_LENGTH));
        $above     = new InlineSuggestionRequest(str_repeat('ü', InlineSuggestionRequest::MAX_TEXT_LENGTH + 1));

        self::assertFalse($atMaximum->isTooLong());
        self::assertTrue($above->isTooLong());
    }

    #[Test]
    public function theMinimumIgnoresSurroundingWhitespace(): void
    {
        self::assertTrue((new InlineSuggestionRequest('   ' . str_repeat('ä', 9) . "\n"))->isShorterThan(10));
        self::assertFalse((new InlineSuggestionRequest(str_repeat('ä', 10)))->isShorterThan(10));
    }
}
