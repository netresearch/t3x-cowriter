<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

/**
 * Request DTO for translation AJAX endpoint.
 *
 * @internal
 */
final readonly class TranslationRequest
{
    private const MAX_FIELD_LENGTH = 32768;

    public function __construct(
        public string $text,
        public string $targetLanguage,
        public string $formality = 'default',
        public string $domain = 'general',
        public ?string $configuration = null,
    ) {}

    /**
     * Validate field lengths to prevent resource exhaustion.
     */
    public function isValid(): bool
    {
        return mb_strlen($this->text) <= self::MAX_FIELD_LENGTH
            && mb_strlen($this->targetLanguage) <= 10;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromRequestBody(array $body): self
    {
        return new self(
            text: trim(self::extractString($body, 'text')),
            targetLanguage: trim(self::extractString($body, 'targetLanguage')),
            formality: trim(self::extractString($body, 'formality', 'default')),
            domain: trim(self::extractString($body, 'domain', 'general')),
            configuration: self::extractNullableString($body, 'configuration'),
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
     */
    private static function extractNullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? trim((string) $value) : null;
    }
}
