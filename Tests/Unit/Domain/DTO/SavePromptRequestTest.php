<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Domain\DTO;

use Netresearch\T3Cowriter\Domain\DTO\SavePromptRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SavePromptRequest::class)]
final class SavePromptRequestTest extends TestCase
{
    #[Test]
    public function titleAndInstructionAreTrimmedAndOnlyTrueShares(): void
    {
        $request = SavePromptRequest::fromArray(['title' => '  Teaser ', 'instruction' => "\nWrite.\n", 'shared' => 1]);

        self::assertSame('Teaser', $request->title);
        self::assertSame('Write.', $request->instruction);
        self::assertFalse($request->shared);
        self::assertTrue(SavePromptRequest::fromArray(['shared' => true])->shared);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function bodies(): iterable
    {
        yield 'complete' => [['title' => 'T', 'instruction' => 'I'], true];
        yield 'no title' => [['instruction' => 'I'], false];
        yield 'blank instruction' => [['title' => 'T', 'instruction' => '   '], false];
        yield 'title not a string' => [['title' => ['T'], 'instruction' => 'I'], false];
        yield 'title of 255 characters' => [['title' => str_repeat('ü', 255), 'instruction' => 'I'], true];
        yield 'title of 256 characters' => [['title' => str_repeat('ü', 256), 'instruction' => 'I'], false];
        yield 'longest instruction' => [['title' => 'T', 'instruction' => str_repeat('ü', SavePromptRequest::MAX_INSTRUCTION_LENGTH)], true];
        yield 'instruction too long' => [['title' => 'T', 'instruction' => str_repeat('ü', SavePromptRequest::MAX_INSTRUCTION_LENGTH + 1)], false];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('bodies')]
    public function validity(array $body, bool $valid): void
    {
        self::assertSame($valid, SavePromptRequest::fromArray($body)->isValid());
    }
}
