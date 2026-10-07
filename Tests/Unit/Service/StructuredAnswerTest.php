<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\T3Cowriter\Service\StructuredAnswer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use UnexpectedValueException;

#[CoversClass(StructuredAnswer::class)]
final class StructuredAnswerTest extends TestCase
{
    /**
     * Named as a string: the class exists from nr-llm 0.39 on only.
     */
    private const RESPONSE_CLASS = 'Netresearch\\NrLlm\\Domain\\Model\\StructuredCompletionResponse';

    #[Test]
    public function anArrayAnswerIsThePayload(): void
    {
        self::assertSame(['suggestions' => ['A', 'B']], StructuredAnswer::payload(['suggestions' => ['A', 'B']]));
    }

    #[Test]
    public function noAnswerIsAnEmptyPayload(): void
    {
        self::assertSame([], StructuredAnswer::payload(null));
    }

    #[Test]
    public function theResponseObjectOfNrLlm039IsUnwrappedToItsData(): void
    {
        if (!class_exists(self::RESPONSE_CLASS)) {
            self::markTestSkipped('The installed nr-llm returns structured answers as arrays (0.38).');
        }

        $class    = self::RESPONSE_CLASS;
        $usage    = new UsageStatistics(1, 1, 2);
        $response = new $class(
            ['variants' => ['One', 'Two']],
            new CompletionResponse('{"variants":["One","Two"]}', 'gpt-test', $usage),
            $usage,
            1,
        );

        self::assertSame(['variants' => ['One', 'Two']], StructuredAnswer::payload($response));
    }

    #[Test]
    public function anObjectWithoutAPayloadArrayIsRefused(): void
    {
        $object       = new stdClass();
        $object->data = 'not an array';

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionCode(1791371837);

        StructuredAnswer::payload($object);
    }
}
