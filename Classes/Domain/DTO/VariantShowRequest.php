<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

/**
 * Request DTO for showing an audience variant: the variant, its original,
 * its context, whether the original is hidden in that context, and the token
 * the creation issued for exactly this variant.
 *
 * @internal
 */
final readonly class VariantShowRequest
{
    public function __construct(
        public int $variantUid,
        public int $originalUid,
        public int $context,
        public bool $hideOriginal,
        public string $token,
    ) {}

    public function isValid(): bool
    {
        return $this->variantUid > 0
            && $this->originalUid > 0
            && $this->variantUid !== $this->originalUid
            && $this->context > 0
            && preg_match('/^[0-9a-f]{40,128}$/', $this->token) === 1;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            VariantRequestValue::uid($body['variantUid'] ?? null),
            VariantRequestValue::uid($body['originalUid'] ?? null),
            VariantRequestValue::uid($body['context'] ?? null),
            ($body['hideOriginal'] ?? false) === true,
            VariantRequestValue::string($body['token'] ?? null),
        );
    }
}
