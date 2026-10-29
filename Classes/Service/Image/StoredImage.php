<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Image;

/**
 * A generated image as it was stored in FAL.
 *
 * @internal
 */
final readonly class StoredImage
{
    public function __construct(
        public int $fileUid,
        public string $publicUrl,
        public int $width,
        public int $height,
        public string $mimeType,
        public string $combinedIdentifier,
    ) {}
}
