<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Support;

use Netresearch\T3Cowriter\Service\EventStream\EventStreamEmitterInterface;

/**
 * Keeps the events a streaming action sends instead of writing them to the
 * output, which PHPUnit owns.
 */
final class RecordingEventStream implements EventStreamEmitterInterface
{
    /** @var array<string, string>|null null while the stream was never opened */
    public ?array $openedWith = null;

    /** @var list<array<string, mixed>> */
    public array $events = [];

    public function open(array $headers = []): void
    {
        $this->openedWith = $headers;
    }

    public function send(array $event): void
    {
        $this->events[] = $event;
    }

    /**
     * The text of all content events, in order.
     */
    public function streamedText(): string
    {
        $text = '';
        foreach ($this->events as $event) {
            if (!isset($event['done']) && isset($event['content']) && is_string($event['content'])) {
                $text .= $event['content'];
            }
        }

        return $text;
    }
}
