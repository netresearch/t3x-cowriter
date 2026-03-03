<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Domain\DTO;

use Netresearch\T3Cowriter\Domain\DTO\ContextRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(ContextRequest::class)]
final class ContextRequestTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $dto = new ContextRequest(
            table: 'tt_content',
            uid: 42,
            field: 'bodytext',
            scope: 'page',
        );

        self::assertSame('tt_content', $dto->table);
        self::assertSame(42, $dto->uid);
        self::assertSame('bodytext', $dto->field);
        self::assertSame('page', $dto->scope);
    }

    #[Test]
    public function fromQueryParamsCreatesFromGetRequest(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'table' => 'tt_content',
            'uid'   => '123',
            'field' => 'bodytext',
            'scope' => 'element',
        ]);

        $dto = ContextRequest::fromQueryParams($request);

        self::assertSame('tt_content', $dto->table);
        self::assertSame(123, $dto->uid);
        self::assertSame('bodytext', $dto->field);
        self::assertSame('element', $dto->scope);
    }

    #[Test]
    public function fromQueryParamsHandlesEmptyParams(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $dto = ContextRequest::fromQueryParams($request);

        self::assertSame('', $dto->table);
        self::assertSame(0, $dto->uid);
        self::assertSame('', $dto->field);
        self::assertSame('', $dto->scope);
    }

    #[Test]
    public function fromQueryParamsHandlesNonNumericUid(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'table' => 'tt_content',
            'uid'   => 'not-a-number',
            'field' => 'bodytext',
            'scope' => 'page',
        ]);

        $dto = ContextRequest::fromQueryParams($request);
        self::assertSame(0, $dto->uid);
    }

    #[Test]
    public function isValidReturnsTrueForValidRequest(): void
    {
        $dto = new ContextRequest('tt_content', 1, 'bodytext', 'page');
        self::assertTrue($dto->isValid());
    }

    #[Test]
    public function isValidAcceptsAllScopes(): void
    {
        foreach (['selection', 'text', 'element', 'page', 'ancestors_1', 'ancestors_2'] as $scope) {
            $dto = new ContextRequest('tt_content', 1, 'bodytext', $scope);
            self::assertTrue($dto->isValid(), "Scope '$scope' should be valid");
        }
    }

    /**
     * @return array<string, array{ContextRequest}>
     */
    public static function invalidRequestProvider(): array
    {
        return [
            'empty table'      => [new ContextRequest('', 1, 'bodytext', 'page')],
            'disallowed table' => [new ContextRequest('be_users', 1, 'bodytext', 'page')],
            'zero uid'         => [new ContextRequest('tt_content', 0, 'bodytext', 'page')],
            'negative uid'     => [new ContextRequest('tt_content', -1, 'bodytext', 'page')],
            'empty field'      => [new ContextRequest('tt_content', 1, '', 'page')],
            'empty scope'      => [new ContextRequest('tt_content', 1, 'bodytext', '')],
            'invalid scope'    => [new ContextRequest('tt_content', 1, 'bodytext', 'invalid')],
        ];
    }

    #[Test]
    #[DataProvider('invalidRequestProvider')]
    public function isValidReturnsFalseForInvalidRequest(ContextRequest $dto): void
    {
        self::assertFalse($dto->isValid());
    }

    // =========================================================================
    // Regex-specific tests (kills PregMatchRemoveCaret #59, PregMatchRemoveDollar #60, PregMatchRemoveFlags #61, LogicalOr #62)
    // =========================================================================

    #[Test]
    public function isValidRejectsFieldStartingWithDigit(): void
    {
        // Kills PregMatchRemoveCaret #59: /^[a-z].../ → /[a-z].../
        // Without ^, '1bodytext' would match because 'b' appears later
        $dto = new ContextRequest('tt_content', 1, '1bodytext', 'page');
        self::assertFalse($dto->isValid());
    }

    #[Test]
    public function isValidRejectsFieldWithTrailingSpecialChars(): void
    {
        // Kills PregMatchRemoveDollar #60: /...[a-z0-9_]*$/ → /...[a-z0-9_]*/
        // Without $, 'body!' would still match up to 'y'
        $dto = new ContextRequest('tt_content', 1, 'bodytext!', 'page');
        self::assertFalse($dto->isValid());
    }

    #[Test]
    public function isValidAcceptsFieldWithUpperCaseLetters(): void
    {
        // Kills PregMatchRemoveFlags #61: /^[a-z]...$/i → /^[a-z]...$/
        // Without /i flag, 'Bodytext' would fail because 'B' is not in [a-z]
        $dto = new ContextRequest('tt_content', 1, 'Bodytext', 'page');
        self::assertTrue($dto->isValid());
    }

    #[Test]
    public function isValidRejectsEmptyFieldRegardlessOfRegex(): void
    {
        // Kills LogicalOr #62: ($field === '' || preg_match...) → ($field === '' && preg_match...)
        // With &&, empty field would still call preg_match on '' which returns 0, but the && would require BOTH
        // Already covered by invalidRequestProvider but explicit for clarity
        $dto = new ContextRequest('tt_content', 1, '', 'page');
        self::assertFalse($dto->isValid());
    }
}
