<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

/**
 * Request DTO for tool calling AJAX endpoint.
 *
 * @internal
 */
final readonly class ToolRequest
{
    private const MAX_FIELD_LENGTH = 32768;

    /**
     * @param list<string> $enabledTools
     */
    public function __construct(
        public string $prompt,
        public array $enabledTools = [],
    ) {}

    /**
     * Validate field lengths to prevent resource exhaustion.
     */
    public function isValid(): bool
    {
        return mb_strlen($this->prompt) <= self::MAX_FIELD_LENGTH;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromRequestBody(array $body): self
    {
        return new self(
            prompt: trim(self::extractString($body, 'prompt')),
            enabledTools: self::extractStringList($body, 'tools'),
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

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function extractStringList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $trimmed = trim((string) $item);
                if ($trimmed !== '') {
                    $result[] = $trimmed;
                }
            }
        }

        return $result;
    }
}
