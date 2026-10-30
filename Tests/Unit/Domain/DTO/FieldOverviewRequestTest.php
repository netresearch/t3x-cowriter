<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Domain\DTO;

use Netresearch\T3Cowriter\Domain\DTO\FieldOverviewRequest;
use Netresearch\T3Cowriter\Domain\DTO\FieldSuggestionRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldOverviewRequest::class)]
final class FieldOverviewRequestTest extends TestCase
{
    #[Test]
    public function readsTableRecordAndFieldValues(): void
    {
        $request = FieldOverviewRequest::fromArray([
            'table'  => 'pages',
            'uid'    => '12',
            'pid'    => 3,
            'fields' => ['seo_title' => 'Old title', 'description' => '', 'keywords' => null],
        ]);

        self::assertSame('pages', $request->table);
        self::assertSame(12, $request->uid);
        self::assertSame(3, $request->pid);
        self::assertSame(['seo_title' => 'Old title', 'description' => '', 'keywords' => ''], $request->fields);
        self::assertTrue($request->isValid());
    }

    #[Test]
    public function aNewRecordPlaceholderIsUidZero(): void
    {
        self::assertSame(0, FieldOverviewRequest::fromArray(['uid' => 'NEW64a1', 'pid' => -5])->uid);
        self::assertSame(0, FieldOverviewRequest::fromArray(['pid' => -5])->pid);
    }

    #[Test]
    public function fieldNamesThatAreNoIdentifiersAreDropped(): void
    {
        $request = FieldOverviewRequest::fromArray(['table' => 'pages', 'fields' => [
            'seo_title' => 'a', 'bad name' => 'b', 'x;DROP' => 'c', 0 => 'd', str_repeat('a', 65) => 'e',
        ]]);

        self::assertSame(['seo_title' => 'a'], $request->fields);
    }

    #[Test]
    public function aTableThatIsNoIdentifierMakesTheRequestInvalid(): void
    {
        self::assertFalse(FieldOverviewRequest::fromArray(['table' => 'pages;', 'fields' => ['title' => '']])->isValid());
        self::assertFalse(FieldOverviewRequest::fromArray(['table' => 'pages', 'fields' => []])->isValid());
        self::assertFalse(FieldOverviewRequest::fromArray(['table' => 'pages', 'fields' => 'title'])->isValid());
    }

    #[Test]
    public function fieldsAndValuesAreBounded(): void
    {
        $fields = [];
        for ($i = 0; $i < 40; ++$i) {
            $fields['field_' . $i] = str_repeat('x', FieldSuggestionRequest::MAX_VALUE_LENGTH + 10);
        }

        $request = FieldOverviewRequest::fromArray(['table' => 'pages', 'fields' => $fields]);

        self::assertCount(FieldOverviewRequest::MAX_FIELDS, $request->fields);
        self::assertSame(FieldSuggestionRequest::MAX_VALUE_LENGTH, mb_strlen($request->fields['field_0']));
    }
}
