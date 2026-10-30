<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

/**
 * The model's verdict on one field in the "all fields" overview: a new value
 * with the reason for it, or "keep" with the reason why the current value is
 * fine.
 */
final readonly class FieldReview
{
    public const ACTION_CHANGE = 'change';

    public const ACTION_KEEP = 'keep';

    public function __construct(
        public string $field,
        public string $action,
        public string $value,
        public string $reason,
    ) {}

    public function isChange(): bool
    {
        return $this->action === self::ACTION_CHANGE;
    }
}
