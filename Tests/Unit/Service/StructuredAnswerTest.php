<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\StructuredCompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\T3Cowriter\Service\StructuredAnswer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructuredAnswer::class)]
final class StructuredAnswerTest extends TestCase
{
    #[Test]
    public function noAnswerIsAnEmptyPayload(): void
    {
        self::assertSame([], StructuredAnswer::payload(null));
    }

    #[Test]
    public function theResponseIsUnwrappedToItsData(): void
    {
        $usage    = new UsageStatistics(1, 1, 2);
        $response = new StructuredCompletionResponse(
            ['variants' => ['One', 'Two']],
            new CompletionResponse('{"variants":["One","Two"]}', 'gpt-test', $usage),
            $usage,
            1,
        );

        self::assertSame(['variants' => ['One', 'Two']], StructuredAnswer::payload($response));
    }
}
