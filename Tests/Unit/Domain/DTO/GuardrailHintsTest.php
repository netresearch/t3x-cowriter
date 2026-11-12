<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Domain\DTO;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\T3Cowriter\Domain\DTO\CompleteResponse;
use Netresearch\T3Cowriter\Domain\DTO\GuardrailHints;
use Netresearch\T3Cowriter\Domain\DTO\UsageData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The hints of an nr-llm guardrail that let a text pass reach the dialog: from
 * a response's metadata and from a drained stream.
 */
#[CoversClass(GuardrailHints::class)]
#[CoversClass(CompleteResponse::class)]
#[CoversClass(UsageData::class)]
final class GuardrailHintsTest extends TestCase
{
    #[Test]
    public function aResponseCarriesItsHintsIntoTheJson(): void
    {
        $response = new CompletionResponse('Hallo du', 'm', new UsageStatistics(1, 1, 2), metadata: [
            GuardrailHints::METADATA_KEY => [
                ['guardrail' => 'rule-set-judge', 'kind' => 'violation', 'message' => 'Rule "Siezen": "Hallo du"', 'rule' => 'Siezen', 'passage' => 'Hallo du'],
            ],
        ]);

        $json = CompleteResponse::success($response)->jsonSerialize();

        self::assertSame([['kind' => 'violation', 'message' => 'Rule "Siezen": "Hallo du"']], $json['hints'] ?? null);
    }

    #[Test]
    public function aResponseWithoutHintsHasNoHintsKey(): void
    {
        $json = CompleteResponse::success(new CompletionResponse('Guten Tag', 'm', new UsageStatistics(1, 1, 2)))->jsonSerialize();

        self::assertArrayNotHasKey('hints', $json);
    }

    #[Test]
    public function aStreamReturnsItsHintsAsObjects(): void
    {
        $hint          = new stdClass();
        $hint->kind    = 'unavailable';
        $hint->message = 'Not checked.';

        self::assertSame([['kind' => 'unavailable', 'message' => 'Not checked.']], GuardrailHints::fromStreamReturn([$hint]));
    }

    #[Test]
    public function anythingElseIsNoHint(): void
    {
        self::assertSame([], GuardrailHints::fromStreamReturn(null));
        self::assertSame([], GuardrailHints::fromMetadata(null));
        self::assertSame([], GuardrailHints::fromMetadata([GuardrailHints::METADATA_KEY => 'x']));
        self::assertSame([], GuardrailHints::fromMetadata([GuardrailHints::METADATA_KEY => [['kind' => 'violation'], ['message' => ''], 'x']]));
    }

    #[Test]
    public function aCaptureWithoutTheCollectorRunsTheCallAndFindsNoHints(): void
    {
        $captured = GuardrailHints::capture(static fn (): string => 'done', 'Netresearch\\NrLlm\\NoSuchCollector');

        self::assertSame(['result' => 'done', 'hints' => []], $captured);
    }

    #[Test]
    public function hintsBecomeResponseMetadataOnlyWhenThereAreAny(): void
    {
        self::assertSame([], GuardrailHints::asMetadata([]));
        self::assertSame([GuardrailHints::METADATA_KEY => [['kind' => 'violation', 'message' => 'm']]], GuardrailHints::asMetadata([['kind' => 'violation', 'message' => 'm']]));
    }
}
