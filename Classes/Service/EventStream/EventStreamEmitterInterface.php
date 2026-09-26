<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\EventStream;

/**
 * Sends a response as a stream of events while it is being produced, instead
 * of one body at the end.
 *
 * @internal
 */
interface EventStreamEmitterInterface
{
    /**
     * Start the stream: send the headers and make sure nothing buffers the
     * events that follow.
     *
     * @param array<string, string> $headers additional response headers
     */
    public function open(array $headers = []): void;

    /**
     * Send one event to the client now.
     *
     * @param array<string, mixed> $event
     */
    public function send(array $event): void;
}
