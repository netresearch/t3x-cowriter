<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

/**
 * Request DTO for proposing sections: only the uid of the text element. The
 * text itself is read from the stored record.
 *
 * @internal
 */
final readonly class PresentationProposeRequest
{
    public function __construct(
        public int $uid,
    ) {}

    public function isValid(): bool
    {
        return $this->uid > 0;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $uid = $body['uid'] ?? 0;

        return new self(is_int($uid) || (is_string($uid) && ctype_digit($uid)) ? (int) $uid : 0);
    }
}
