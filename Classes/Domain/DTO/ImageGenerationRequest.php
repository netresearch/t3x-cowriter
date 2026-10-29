<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

/**
 * Request DTO for the image generation AJAX endpoint.
 *
 * @internal
 */
final readonly class ImageGenerationRequest
{
    /**
     * Longest prompt accepted, in characters. Well below what the OpenAI image
     * models accept; a prompt for one picture in a text needs no more.
     */
    public const MAX_PROMPT_LENGTH = 1000;

    public function __construct(
        public string $prompt,
        public ?string $configuration = null,
    ) {}

    /**
     * Validate field lengths to prevent resource exhaustion.
     */
    public function isValid(): bool
    {
        return mb_strlen($this->prompt) <= self::MAX_PROMPT_LENGTH
            && ($this->configuration === null || mb_strlen($this->configuration) <= 255);
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromRequestBody(array $body): self
    {
        $prompt = $body['prompt'] ?? '';
        $config = $body['configuration'] ?? null;

        return new self(
            prompt: is_string($prompt) ? trim($prompt) : '',
            configuration: is_string($config) && trim($config) !== '' ? trim($config) : null,
        );
    }
}
