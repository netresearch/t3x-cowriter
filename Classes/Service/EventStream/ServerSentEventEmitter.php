<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\EventStream;

/**
 * Writes events as Server-Sent Events (`data: {json}`) straight to the output
 * and flushes each one.
 *
 * The same transport as nr-llm's tool playground stream: output buffering and
 * compression are switched off, and every event is padded to
 * {@see self::MIN_EVENT_BYTES} with an SSE comment line, so a reverse proxy
 * that waits for a full buffer passes it on at once. The client skips comment
 * lines. The caller returns a NullResponse afterwards, because the body has
 * already been sent.
 *
 * @internal
 */
final class ServerSentEventEmitter implements EventStreamEmitterInterface
{
    /**
     * The size up to which an event is padded; the flush threshold of common
     * proxy buffers, and the value nr-llm's playground stream uses.
     */
    public const MIN_EVENT_BYTES = 4096;

    public function open(array $headers = []): void
    {
        ini_set('zlib.output_compression', '0');
        ini_set('output_buffering', '0');
        ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        if (headers_sent()) {
            return;
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    public function send(array $event): void
    {
        echo self::format($event);
        flush();
    }

    /**
     * One event as it goes over the wire: the data line, padding as an SSE
     * comment line when the data line is shorter than the threshold, and the
     * blank line that ends the event.
     *
     * @param array<string, mixed> $event
     */
    public static function format(array $event): string
    {
        $data    = 'data: ' . json_encode($event, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        $padding = self::MIN_EVENT_BYTES - strlen($data) - 3;

        return $data . ($padding > 0 ? ':' . str_repeat(' ', $padding) . "\n" : '') . "\n";
    }
}
