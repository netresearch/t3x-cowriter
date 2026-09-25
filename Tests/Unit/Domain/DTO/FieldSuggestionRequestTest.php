<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Domain\DTO;

use Netresearch\T3Cowriter\Domain\DTO\FieldSuggestionRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldSuggestionRequest::class)]
final class FieldSuggestionRequestTest extends TestCase
{
    #[Test]
    public function fromArrayReadsAllFields(): void
    {
        $request = FieldSuggestionRequest::fromArray([
            'table'        => 'pages',
            'field'        => 'seo_title',
            'uid'          => '12',
            'pid'          => 3,
            'currentValue' => 'Draft title',
            'count'        => 4,
        ]);

        self::assertSame('pages', $request->table);
        self::assertSame('seo_title', $request->field);
        self::assertSame(12, $request->uid);
        self::assertSame(3, $request->pid);
        self::assertSame('Draft title', $request->currentValue);
        self::assertSame(4, $request->count);
        self::assertTrue($request->isValid());
        self::assertFalse($request->isNewRecord());
    }

    #[Test]
    public function countDefaultsToThree(): void
    {
        self::assertSame(3, FieldSuggestionRequest::fromArray([])->count);
        self::assertSame(3, FieldSuggestionRequest::DEFAULT_COUNT);
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function countProvider(): iterable
    {
        yield 'zero is raised to one' => [0, 1];
        yield 'negative is raised to one' => [-4, 1];
        yield 'one stays' => [1, 1];
        yield 'five stays' => [5, 5];
        yield 'six is capped to five' => [6, 5];
        yield 'huge is capped to five' => [1000, 5];
        yield 'numeric string is accepted' => ['2', 2];
        yield 'numeric string is capped' => ['9', 5];
        yield 'non-numeric string: default' => ['many', 3];
        yield 'float: default' => [2.5, 3];
        yield 'null: default' => [null, 3];
    }

    #[Test]
    #[DataProvider('countProvider')]
    public function countIsClampedToOneToFive(mixed $count, int $expected): void
    {
        self::assertSame($expected, FieldSuggestionRequest::fromArray(['count' => $count])->count);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidIdentifierProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'sql fragment' => ['pages; DROP TABLE be_users'];
        yield 'dotted' => ['pages.title'];
        yield 'array' => [['pages']];
        yield 'too long' => [str_repeat('a', 65)];
    }

    #[Test]
    #[DataProvider('invalidIdentifierProvider')]
    public function invalidTableMakesTheRequestInvalid(mixed $table): void
    {
        $request = FieldSuggestionRequest::fromArray(['table' => $table, 'field' => 'title']);

        self::assertSame('', $request->table);
        self::assertFalse($request->isValid());
    }

    #[Test]
    #[DataProvider('invalidIdentifierProvider')]
    public function invalidFieldMakesTheRequestInvalid(mixed $field): void
    {
        $request = FieldSuggestionRequest::fromArray(['table' => 'pages', 'field' => $field]);

        self::assertSame('', $request->field);
        self::assertFalse($request->isValid());
    }

    #[Test]
    public function identifierOfMaximumLengthIsAccepted(): void
    {
        $name = str_repeat('a', 64);

        self::assertSame($name, FieldSuggestionRequest::fromArray(['table' => $name])->table);
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function uidProvider(): iterable
    {
        yield 'int' => [7, 7];
        yield 'numeric string' => ['7', 7];
        yield 'NEW placeholder' => ['NEW64f0a1', 0];
        yield 'negative int' => [-3, 0];
        yield 'negative string' => ['-3', 0];
        yield 'missing' => [null, 0];
    }

    #[Test]
    #[DataProvider('uidProvider')]
    public function uidAndPidAreNonNegativeIntegers(mixed $value, int $expected): void
    {
        $request = FieldSuggestionRequest::fromArray(['uid' => $value, 'pid' => $value]);

        self::assertSame($expected, $request->uid);
        self::assertSame($expected, $request->pid);
    }

    #[Test]
    public function newRecordIsDetectedByUidZero(): void
    {
        self::assertTrue(FieldSuggestionRequest::fromArray(['uid' => 'NEW1', 'pid' => 5])->isNewRecord());
    }

    #[Test]
    public function currentValueIsCutToTheMaximumLength(): void
    {
        $request = FieldSuggestionRequest::fromArray(['currentValue' => str_repeat('ä', 2500)]);

        self::assertSame(2000, mb_strlen($request->currentValue));
        self::assertSame(str_repeat('ä', 2000), $request->currentValue);
    }

    #[Test]
    public function nonStringCurrentValueBecomesEmpty(): void
    {
        self::assertSame('', FieldSuggestionRequest::fromArray(['currentValue' => ['x']])->currentValue);
    }
}
