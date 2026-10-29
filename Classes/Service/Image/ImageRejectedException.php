<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Image;

use RuntimeException;

/**
 * The image the model returned is not stored: it is missing, larger than the
 * cap, not a PNG, JPEG or WebP image, or not readable as an image.
 *
 * @internal
 */
final class ImageRejectedException extends RuntimeException
{
    public const REASON_MISSING = 'missing';

    public const REASON_TOO_LARGE = 'too_large';

    public const REASON_TYPE = 'type';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message, 1793200001);
    }
}
