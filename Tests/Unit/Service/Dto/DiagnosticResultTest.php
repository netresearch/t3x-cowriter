<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Dto;

use Netresearch\T3Cowriter\Service\Dto\DiagnosticCheck;
use Netresearch\T3Cowriter\Service\Dto\DiagnosticResult;
use Netresearch\T3Cowriter\Service\Dto\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiagnosticResult::class)]
final class DiagnosticResultTest extends TestCase
{
    #[Test]
    public function okPropertyIsSetByConstructor(): void
    {
        $result = new DiagnosticResult(ok: true, checks: []);

        self::assertTrue($result->ok);
        self::assertSame([], $result->checks);
    }

    #[Test]
    public function getFirstFailureReturnsFirstFailedCheck(): void
    {
        $passing = new DiagnosticCheck('a', true, 'OK', Severity::Ok);
        $failing = new DiagnosticCheck('b', false, 'Bad', Severity::Error);
        $another = new DiagnosticCheck('c', false, 'Also bad', Severity::Warning);

        $result = new DiagnosticResult(ok: false, checks: [$passing, $failing, $another]);

        $failure = $result->getFirstFailure();
        self::assertNotNull($failure);
        self::assertSame('b', $failure->key);
        self::assertSame('Bad', $failure->message);
    }

    #[Test]
    public function getFirstFailureReturnsNullWhenAllPass(): void
    {
        $check1 = new DiagnosticCheck('a', true, 'OK', Severity::Ok);
        $check2 = new DiagnosticCheck('b', true, 'Also OK', Severity::Ok);

        $result = new DiagnosticResult(ok: true, checks: [$check1, $check2]);

        self::assertNull($result->getFirstFailure());
    }

    #[Test]
    public function getFirstFailureMessageReturnsMessage(): void
    {
        $failing = new DiagnosticCheck('x', false, 'Something went wrong.', Severity::Error);
        $result  = new DiagnosticResult(ok: false, checks: [$failing]);

        self::assertSame('Something went wrong.', $result->getFirstFailureMessage());
    }

    #[Test]
    public function getFirstFailureMessageReturnsEmptyStringWhenAllPass(): void
    {
        $passing = new DiagnosticCheck('a', true, 'OK', Severity::Ok);
        $result  = new DiagnosticResult(ok: true, checks: [$passing]);

        self::assertSame('', $result->getFirstFailureMessage());
    }
}
