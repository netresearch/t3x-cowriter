<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

/**
 * Request DTO for an audience variant proposal: the element and the uids of
 * the audience (required) and tone-of-voice (optional) prompt snippets.
 *
 * @internal
 */
final readonly class VariantProposeRequest
{
    public function __construct(
        public int $uid,
        public int $audience,
        public int $tone = 0,
    ) {}

    public function isValid(): bool
    {
        return $this->uid > 0 && $this->audience > 0 && $this->tone >= 0;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            VariantRequestValue::uid($body['uid'] ?? null),
            VariantRequestValue::uid($body['audience'] ?? null),
            VariantRequestValue::optionalUid($body['tone'] ?? null),
        );
    }
}
