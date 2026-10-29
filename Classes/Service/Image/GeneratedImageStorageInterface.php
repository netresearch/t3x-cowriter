<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Image;

use RuntimeException;
use TYPO3\CMS\Core\Resource\Folder;

/**
 * Stores a generated image in FAL as the current backend user.
 *
 * @internal
 */
interface GeneratedImageStorageInterface
{
    /**
     * The folder the current backend user's uploads go to, when that user may
     * add files to it and it is publicly reachable; null otherwise.
     */
    public function targetFolder(): ?Folder;

    /**
     * Decode, check and store the image in the given folder.
     *
     * @throws ImageRejectedException when the data is not an image that is stored
     * @throws RuntimeException       when FAL refuses or fails to add the file
     */
    public function store(?string $base64, string $prompt, Folder $folder): StoredImage;
}
