<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

use RuntimeException;

/**
 * A field suggestion request that cannot be served. The message is written for
 * the editor and is returned to the browser as is; it never carries provider
 * details.
 */
final class FieldSuggestionException extends RuntimeException
{
    private function __construct(string $message, private readonly int $httpStatus)
    {
        parent::__construct($message, $httpStatus);
    }

    public static function notEnabled(): self
    {
        return new self('Suggestions are not enabled for this field.', 400);
    }

    public static function accessDenied(): self
    {
        return new self('You are not allowed to edit this field.', 403);
    }

    public static function recordNotFound(): self
    {
        return new self('The record could not be found.', 404);
    }

    public static function notApplicable(string $reason): self
    {
        return new self($reason, 422);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
