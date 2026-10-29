<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Image;

use finfo;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Charset\CharsetConverter;
use TYPO3\CMS\Core\Resource\DefaultUploadFolderResolver;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Stores a generated image in FAL as the current backend user: in the folder
 * TYPO3 uploads that user's files to, with that user's file permissions.
 *
 * The folder comes from {@see DefaultUploadFolderResolver} (user TSconfig
 * `options.defaultUploadFolder`, else the first default folder the user's
 * file mounts let them write to). It is checked before the image is
 * generated, so an editor without a writable folder pays for nothing.
 * Permission checks stay on: {@see \TYPO3\CMS\Core\Resource\ResourceStorage::addFile()}
 * checks the file extension, the user's "add file" right and the folder's
 * write permission again.
 *
 * Only the bytes the model returned are stored, never a URL: they must decode
 * from base64, be at most {@see self::MAX_BYTES} long and be a PNG, JPEG or
 * WebP image by content (finfo), not by name.
 *
 * @internal
 */
final readonly class GeneratedImageStorage implements GeneratedImageStorageInterface
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * Accepted MIME types and the file extension each one gets.
     */
    private const EXTENSIONS = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    private const SLUG_LENGTH = 40;

    public function __construct(
        private DefaultUploadFolderResolver $uploadFolderResolver,
        private CharsetConverter $charsetConverter,
    ) {}

    /**
     * Publicly reachable, because the editor shows the image by its URL.
     */
    public function targetFolder(): ?Folder
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return null;
        }

        try {
            $folder = $this->uploadFolderResolver->resolve($backendUser);
            if (!$folder instanceof Folder) {
                return null;
            }

            $storage = $folder->getStorage();

            return $storage->isPublic()
                && $storage->checkUserActionPermission('add', 'File')
                && $folder->checkActionPermission('write')
                ? $folder
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function store(?string $base64, string $prompt, Folder $folder): StoredImage
    {
        $bytes    = $this->decode($base64);
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $suffix   = is_string($mimeType) ? (self::EXTENSIONS[$mimeType] ?? null) : null;
        if ($suffix === null) {
            throw new ImageRejectedException(
                ImageRejectedException::REASON_TYPE,
                sprintf('The generated file is no PNG, JPEG or WebP image (%s).', is_string($mimeType) ? $mimeType : 'unknown'),
            );
        }

        $dimensions = getimagesizefromstring($bytes);
        if ($dimensions === false) {
            throw new ImageRejectedException(ImageRejectedException::REASON_TYPE, 'The generated file cannot be read as an image.');
        }

        $temporaryFile = GeneralUtility::tempnam('cowriter_image_');
        try {
            if (file_put_contents($temporaryFile, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('The generated image could not be written to a temporary file.', 1793200002);
            }

            $file = $folder->getStorage()->addFile(
                $temporaryFile,
                $folder,
                $this->fileName($prompt, $suffix),
                DuplicationBehavior::RENAME,
            );
        } finally {
            if (is_file($temporaryFile)) {
                GeneralUtility::unlink_tempfile($temporaryFile);
            }
        }

        $publicUrl = $file->getPublicUrl();
        if ($publicUrl === null || $publicUrl === '') {
            throw new RuntimeException('The stored image has no public URL.', 1793200003);
        }

        return new StoredImage(
            fileUid: $file->getUid(),
            publicUrl: $publicUrl,
            width: $dimensions[0],
            height: $dimensions[1],
            mimeType: $mimeType,
            combinedIdentifier: $file->getCombinedIdentifier(),
        );
    }

    /**
     * The file name: a slug of the prompt and a random suffix, so two images
     * from the same prompt do not depend on FAL's renaming, and the name
     * reveals nothing beyond the prompt's first words.
     */
    public function fileName(string $prompt, string $suffix): string
    {
        // utf8_char_mapping() exists unchanged in TYPO3 13.4 and 14.3;
        // specCharsToASCII() is gone in 14.
        $ascii = strtolower($this->charsetConverter->utf8_char_mapping($prompt));
        $slug  = trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
        $slug  = trim(substr($slug, 0, self::SLUG_LENGTH), '-');

        return ($slug !== '' ? $slug : 'image') . '-' . bin2hex(random_bytes(4)) . '.' . $suffix;
    }

    /**
     * The image bytes; the length is checked before decoding, so an oversized
     * answer is never decoded into memory.
     *
     * @throws ImageRejectedException
     */
    private function decode(?string $base64): string
    {
        if ($base64 === null || $base64 === '') {
            throw new ImageRejectedException(ImageRejectedException::REASON_MISSING, 'The model returned no image data.');
        }

        if (strlen($base64) > intdiv(self::MAX_BYTES + 2, 3) * 4) {
            throw new ImageRejectedException(ImageRejectedException::REASON_TOO_LARGE, 'The generated image is larger than the limit.');
        }

        $bytes = base64_decode($base64, true);
        if ($bytes === false || $bytes === '') {
            throw new ImageRejectedException(ImageRejectedException::REASON_MISSING, 'The image data is not valid base64.');
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new ImageRejectedException(ImageRejectedException::REASON_TOO_LARGE, 'The generated image is larger than the limit.');
        }

        return $bytes;
    }
}
