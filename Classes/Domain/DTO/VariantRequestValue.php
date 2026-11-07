<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

/**
 * Value parsing shared by the audience variant request DTOs.
 *
 * @internal
 */
final class VariantRequestValue
{
    /**
     * A non-negative integer sent as a JSON number or a digit string; 0 for
     * anything else.
     */
    public static function uid(mixed $value): int
    {
        return (is_int($value) && $value > 0) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
    }

    /**
     * An optional uid: 0 when it is missing, null or 0, the uid when it is a
     * valid one, -1 (invalid) for anything else, so a malformed value is
     * refused rather than read as "none".
     */
    public static function optionalUid(mixed $value): int
    {
        if (in_array($value, [null, 0, '0', ''], true)) {
            return 0;
        }

        $uid = self::uid($value);

        return $uid > 0 ? $uid : -1;
    }

    public static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
