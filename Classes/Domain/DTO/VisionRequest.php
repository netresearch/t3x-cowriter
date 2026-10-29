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
    private const MAX_FIELD_LENGTH = 32768;

    /**
     * @param string                              $prompt        the instruction the request sends; empty when it sends none,
     *                                                           {@see \Netresearch\T3Cowriter\Service\Vision\AltTextPrompt} then takes the configured one
     * @param array{table: string, uid: int}|null $recordContext the record the editor works on, as the client names it;
     *                                                           only used to look up the record's language on the server
     */
    public function __construct(
        public string $imageUrl,
        public string $prompt = '',
        public ?array $recordContext = null,
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
            prompt: trim(self::extractString($body, 'prompt')),
            recordContext: self::extractRecordContext($body),
        );
    }

    /**
     * Table and uid of the record context; null when it is missing or
     * malformed. A malformed context is not an error: the alt text is then
     * written without a language hint.
     *
     * @param array<string, mixed> $data
     *
     * @return array{table: string, uid: int}|null
     */
    private static function extractRecordContext(array $data): ?array
    {
        $rc = $data['recordContext'] ?? null;
        if (!is_array($rc)) {
            return null;
        }

        $table = is_string($rc['table'] ?? null) ? $rc['table'] : '';
        $uid   = is_numeric($rc['uid'] ?? null) ? (int) $rc['uid'] : 0;

        if ($uid <= 0 || preg_match('/^[a-z][a-z0-9_]*$/i', $table) !== 1) {
            return null;
        }

        return ['table' => $table, 'uid' => $uid];
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
