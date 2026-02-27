<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Request DTO for completion AJAX endpoint.
 *
 * Parses and validates incoming requests, including support for
 * model override prefixes (e.g., #cw:gpt-4o).
 *
 * @internal
 */
final readonly class CompleteRequest
{
    /**
     * Pattern to match model override prefix: #cw:model-name followed by space.
     */
    private const MODEL_OVERRIDE_PATTERN = '/^#cw:(\S+)\s+/';

    /**
     * Allowed characters in model names (alphanumeric, hyphens, underscores, dots, colons, slashes).
     * Examples: gpt-4o, claude-3-opus-20240229, mistral/mixtral-8x7b, openai:gpt-4.
     */
    private const MODEL_NAME_PATTERN = '/^[a-zA-Z0-9][-a-zA-Z0-9_.:\/]*$/';

    /**
     * Maximum allowed prompt length in characters.
     * Prevents denial-of-wallet attacks and excessive token consumption.
     * 32KB is generous for most use cases while providing a safety limit.
     */
    private const MAX_PROMPT_LENGTH = 32768;

    public function __construct(
        public string $prompt,
        public ?string $configuration,
        public ?string $modelOverride,
    ) {}

    /**
     * Create request DTO from PSR-7 request.
     *
     * Supports both JSON body and form data.
     */
    public static function fromRequest(ServerRequestInterface $request): self
    {
        $body = $request->getParsedBody();

        // Handle JSON body (from getContents) or parsed form data
        if ($body === null) {
            $contents = $request->getBody()->getContents();
            if ($contents !== '') {
                /** @var array<string, mixed>|null $decoded */
                $decoded = json_decode($contents, true);
                $body    = is_array($decoded) ? $decoded : [];
            }
        }

        /** @var array<string, mixed> $data */
        $data = is_array($body) ? $body : [];

        $rawPrompt     = self::extractString($data, 'prompt');
        $modelOverride = null;
        $prompt        = $rawPrompt;

        // Parse model override prefix (#cw:model-name) with validation
        if (preg_match(self::MODEL_OVERRIDE_PATTERN, $rawPrompt, $matches) === 1) {
            $candidateModel = $matches[1];
            // Only accept model override if it matches the allowed pattern
            if (preg_match(self::MODEL_NAME_PATTERN, $candidateModel) === 1) {
                $modelOverride = $candidateModel;
                $prompt        = trim(substr($rawPrompt, strlen($matches[0])));
            }

            // If invalid model name, keep the original prompt unchanged (ignore the prefix)
        }

        return new self(
            prompt: $prompt,
            configuration: self::extractNullableString($data, 'configuration'),
            modelOverride: $modelOverride,
        );
    }

    /**
     * Check if the request is valid (has non-empty prompt within length limits).
     */
    public function isValid(): bool
    {
        $trimmed = trim($this->prompt);

        return $trimmed !== '' && mb_strlen($trimmed, 'UTF-8') <= self::MAX_PROMPT_LENGTH;
    }

    /**
     * Extract string value from data array with type safety.
     *
     * @param array<string, mixed> $data
     */
    private static function extractString(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Extract nullable string value from data array with type safety.
     *
     * @param array<string, mixed> $data
     */
    private static function extractNullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
