<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Presentation;

use RuntimeException;

/**
 * A refusal with the label the editor reads and the HTTP status it is sent
 * with.
 *
 * @internal
 */
final class PresentationException extends RuntimeException
{
    private function __construct(
        private readonly string $labelKey,
        private readonly int $httpStatus,
    ) {
        parent::__construct($labelKey, $httpStatus);
    }

    /**
     * The element does not exist, is not a text element, or the user may not
     * edit it or create the new element. One answer for all of them, so the
     * route does not tell which uids exist.
     */
    public static function notAvailable(): self
    {
        return new self('presentation.error.notAvailable', 403);
    }

    public static function tooShort(): self
    {
        return new self('presentation.error.tooShort', 422);
    }

    public static function tooLong(): self
    {
        return new self('presentation.error.tooLong', 422);
    }

    public static function noSplit(): self
    {
        return new self('presentation.error.noSplit', 502);
    }

    public static function changed(): self
    {
        return new self('presentation.error.changed', 409);
    }

    public static function invalidSections(): self
    {
        return new self('presentation.error.invalidSections', 400);
    }

    public static function createFailed(): self
    {
        return new self('presentation.error.createFailed', 500);
    }

    public static function createIncomplete(): self
    {
        return new self('presentation.error.createIncomplete', 500);
    }

    public static function switchFailed(): self
    {
        return new self('presentation.error.switchFailed', 500);
    }

    /**
     * The original has translations: hiding a default-language element hides
     * its translations too on sites with overlays (fallback, strict).
     */
    public static function switchHasTranslations(): self
    {
        return new self('presentation.switchNote.translations', 409);
    }

    /**
     * The original is a connected translation: hiding it brings back the
     * default-language element on sites with fallback.
     */
    public static function switchConnectedTranslation(): self
    {
        return new self('presentation.switchNote.connectedTranslation', 409);
    }

    public function getLabelKey(): string
    {
        return $this->labelKey;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
