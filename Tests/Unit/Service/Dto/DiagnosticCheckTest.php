<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Dto;

use Netresearch\T3Cowriter\Service\Dto\DiagnosticCheck;
use Netresearch\T3Cowriter\Service\Dto\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiagnosticCheck::class)]
final class DiagnosticCheckTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $check = new DiagnosticCheck(
            key: 'provider_exists',
            passed: false,
            message: 'No provider configured.',
            severity: Severity::Error,
            fixRoute: 'nrllm_providers',
        );

        self::assertSame('provider_exists', $check->key);
        self::assertFalse($check->passed);
        self::assertSame('No provider configured.', $check->message);
        self::assertSame(Severity::Error, $check->severity);
        self::assertSame('nrllm_providers', $check->fixRoute);
    }

    #[Test]
    public function fixRouteDefaultsToNull(): void
    {
        $check = new DiagnosticCheck(
            key: 'ok_check',
            passed: true,
            message: 'All good.',
            severity: Severity::Ok,
        );

        self::assertNull($check->fixRoute);
    }
}
