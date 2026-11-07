<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Variant;

use RuntimeException;

/**
 * A refusal with the label the editor reads and the HTTP status it is sent
 * with.
 *
 * @internal
 */
final class VariantException extends RuntimeException
{
    private function __construct(
        private readonly string $labelKey,
        private readonly int $httpStatus,
    ) {
        parent::__construct($labelKey, $httpStatus);
    }

    /**
     * The element does not exist, is not a text element, or the user may not
     * edit it or create the copy. One answer for all of them, so the route
     * does not tell which uids exist.
     */
    public static function notAvailable(): self
    {
        return new self('variant.error.notAvailable', 403);
    }

    /**
     * The original has translations: the copy would take them over with the
     * old text, and a hidden original would hide them too.
     */
    public static function hasTranslations(): self
    {
        return new self('variant.error.translations', 409);
    }

    /**
     * The original is a connected translation: its variant would be a
     * translation of the same default-language element.
     */
    public static function connectedTranslation(): self
    {
        return new self('variant.error.connectedTranslation', 409);
    }

    public static function originalTranslated(): self
    {
        return new self('variant.error.originalTranslated', 409);
    }

    public static function noText(): self
    {
        return new self('variant.error.noText', 422);
    }

    public static function tooLong(): self
    {
        return new self('variant.error.tooLong', 422);
    }

    public static function noAudience(): self
    {
        return new self('variant.error.noAudience', 400);
    }

    public static function noTone(): self
    {
        return new self('variant.error.noTone', 400);
    }

    public static function noRewrite(): self
    {
        return new self('variant.error.noRewrite', 502);
    }

    public static function changed(): self
    {
        return new self('variant.error.changed', 409);
    }

    /**
     * A context the editor may not assign: EXT:contexts is not installed, the
     * context is unknown, disabled or hidden in the backend, the editor lacks
     * the right on the settings field, or he works in a workspace.
     */
    public static function contextNotAvailable(): self
    {
        return new self('variant.error.contextNotAvailable', 400);
    }

    public static function createFailed(): self
    {
        return new self('variant.error.createFailed', 500);
    }

    public static function createIncomplete(): self
    {
        return new self('variant.error.createIncomplete', 500);
    }

    public static function showFailed(): self
    {
        return new self('variant.error.showFailed', 500);
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
