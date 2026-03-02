<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Request DTO for task execution AJAX endpoint.
 *
 * Parses and validates incoming task execution requests.
 *
 * @internal
 */
final readonly class ExecuteTaskRequest
{
    /**
     * Maximum allowed context length in characters.
     * Matches CompleteRequest::MAX_PROMPT_LENGTH for consistency.
     */
    private const MAX_CONTEXT_LENGTH = 32768;

    /**
     * Maximum allowed ad-hoc rules length in characters.
     */
    private const MAX_RULES_LENGTH = 4096;

    /**
     * Allowed context types.
     *
     * @var list<string>
     */
    private const ALLOWED_CONTEXT_TYPES = ['selection', 'content_element'];

    public function __construct(
        public int $taskUid,
        public string $context,
        public string $contextType,
        public string $adHocRules,
        public ?string $configuration,
    ) {}

    /**
     * Create request DTO from PSR-7 request.
     */
    public static function fromRequest(ServerRequestInterface $request): self
    {
        $body = $request->getParsedBody();

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

        return new self(
            taskUid: self::extractInt($data, 'taskUid'),
            context: self::extractString($data, 'context'),
            contextType: self::extractString($data, 'contextType'),
            adHocRules: self::extractString($data, 'adHocRules'),
            configuration: self::extractNullableString($data, 'configuration'),
        );
    }

    /**
     * Check if the request is valid.
     */
    public function isValid(): bool
    {
        if ($this->taskUid <= 0) {
            return false;
        }

        if (trim($this->context) === '') {
            return false;
        }

        if (mb_strlen($this->context, 'UTF-8') > self::MAX_CONTEXT_LENGTH) {
            return false;
        }

        if (!in_array($this->contextType, self::ALLOWED_CONTEXT_TYPES, true)) {
            return false;
        }

        if (mb_strlen($this->adHocRules, 'UTF-8') > self::MAX_RULES_LENGTH) {
            return false;
        }

        return true;
    }

    /**
     * Extract integer value from data array with type safety.
     *
     * @param array<string, mixed> $data
     */
    private static function extractInt(array $data, string $key): int
    {
        $value = $data[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
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
