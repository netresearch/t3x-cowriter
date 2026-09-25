<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

use RuntimeException;

/**
 * A field suggestion request that cannot be served. The label key names the
 * editor-facing text in locallang_be.xlf, which the controller returns in the
 * editor's backend language; the exception message is the English text. Neither
 * carries provider details.
 */
final class FieldSuggestionException extends RuntimeException
{
    private function __construct(string $message, private readonly int $httpStatus, private readonly string $labelKey)
    {
        parent::__construct($message, $httpStatus);
    }

    public static function notEnabled(): self
    {
        return new self('Suggestions are not enabled for this field.', 400, 'fieldSuggestions.error.notEnabled');
    }

    public static function accessDenied(): self
    {
        return new self('You are not allowed to edit this field.', 403, 'fieldSuggestions.error.accessDenied');
    }

    public static function siteRootSlug(): self
    {
        return new self('The root page of a site always has the slug "/".', 422, 'fieldSuggestions.error.siteRootSlug');
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Id of the editor-facing text in locallang_be.xlf.
     */
    public function getLabelKey(): string
    {
        return $this->labelKey;
    }
}
