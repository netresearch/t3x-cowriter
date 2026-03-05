<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

/**
 * Request DTO for vision/image analysis AJAX endpoint.
 *
 * @internal
 */
final readonly class VisionRequest
{
    private const DEFAULT_PROMPT = 'Generate a concise, descriptive alt text for this image.';

    private const MAX_FIELD_LENGTH = 32768;

    public function __construct(
        public string $imageUrl,
        public string $prompt = self::DEFAULT_PROMPT,
    ) {}

    /**
     * Validate field lengths to prevent resource exhaustion.
     */
    public function isValid(): bool
    {
        return mb_strlen($this->imageUrl) <= self::MAX_FIELD_LENGTH
            && mb_strlen($this->prompt) <= self::MAX_FIELD_LENGTH;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromRequestBody(array $body): self
    {
        return new self(
            imageUrl: trim(self::extractString($body, 'imageUrl')),
            prompt: trim(self::extractString($body, 'prompt', self::DEFAULT_PROMPT)),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractString(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }
}
