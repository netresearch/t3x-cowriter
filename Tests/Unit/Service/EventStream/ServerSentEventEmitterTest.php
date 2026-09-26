<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\EventStream;

use Netresearch\T3Cowriter\Service\EventStream\ServerSentEventEmitter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServerSentEventEmitter::class)]
final class ServerSentEventEmitterTest extends TestCase
{
    #[Test]
    public function aShortEventIsPaddedToTheFlushThresholdWithACommentLine(): void
    {
        $wire = ServerSentEventEmitter::format(['content' => 'Hi']);

        self::assertSame(ServerSentEventEmitter::MIN_EVENT_BYTES, strlen($wire));
        $lines = explode("\n", $wire);
        self::assertSame('data: {"content":"Hi"}', $lines[0]);
        self::assertStringStartsWith(':', $lines[1]);
        self::assertSame(['', ''], array_slice($lines, 2));
    }

    #[Test]
    public function aLongEventIsSentWithoutPadding(): void
    {
        $text = str_repeat('a', ServerSentEventEmitter::MIN_EVENT_BYTES);

        $wire = ServerSentEventEmitter::format(['content' => $text]);

        self::assertSame('data: {"content":"' . $text . "\"}\n\n", $wire);
    }

    #[Test]
    public function invalidUtf8DoesNotBreakTheEvent(): void
    {
        $wire = ServerSentEventEmitter::format(['content' => "broken \xC3"]);

        self::assertStringStartsWith('data: {"content":"broken ', $wire);
    }
}
