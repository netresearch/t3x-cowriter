<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

/**
 * Request DTO for showing the new element and hiding its original: both uids
 * and the token the creation issued for exactly this pair.
 *
 * @internal
 */
final readonly class PresentationSwitchRequest
{
    public function __construct(
        public int $newUid,
        public int $originalUid,
        public string $token,
    ) {}

    public function isValid(): bool
    {
        return $this->newUid > 0
            && $this->originalUid > 0
            && $this->newUid !== $this->originalUid
            && preg_match('/^[0-9a-f]{40,128}$/', $this->token) === 1;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $token = $body['token'] ?? '';

        return new self(
            self::uid($body['newUid'] ?? null),
            self::uid($body['originalUid'] ?? null),
            is_string($token) ? $token : '',
        );
    }

    private static function uid(mixed $value): int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
    }
}
