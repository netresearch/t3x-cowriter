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
     * Maximum allowed editor capabilities length in characters.
     */
    private const MAX_CAPABILITIES_LENGTH = 2048;

    /**
     * Maximum number of reference pages allowed.
     */
    private const MAX_REFERENCE_PAGES = 10;

    /**
     * Allowed context types.
     *
     * @var list<string>
     */
    private const ALLOWED_CONTEXT_TYPES = ['selection', 'content_element'];

    /**
     * Allowed context scopes.
     *
     * @var list<string>
     */
    private const ALLOWED_CONTEXT_SCOPES = [
        '',
        'selection',
        'text',
        'element',
        'page',
        'ancestors_1',
        'ancestors_2',
    ];

    /**
     * Context scopes that require a record context.
     *
     * @var list<string>
     */
    private const SCOPES_REQUIRING_RECORD_CONTEXT = [
        'element',
        'page',
        'ancestors_1',
        'ancestors_2',
    ];

    /**
     * Allowed record context tables.
     *
     * @var list<string>
     */
    private const ALLOWED_RECORD_TABLES = ['tt_content', 'pages'];

    /**
     * @param array{table: string, uid: int, field: string}|null $recordContext
     * @param list<array{pid: int, relation: string}>            $referencePages
     */
    public function __construct(
        public int $taskUid,
        public string $context,
        public string $contextType,
        public string $adHocRules,
        public ?string $configuration,
        public string $editorCapabilities = '',
        public string $contextScope = '',
        public ?array $recordContext = null,
        public array $referencePages = [],
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
            editorCapabilities: self::extractString($data, 'editorCapabilities'),
            contextScope: self::extractString($data, 'contextScope'),
            recordContext: self::extractRecordContext($data),
            referencePages: self::extractReferencePages($data),
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

        if (mb_strlen($this->editorCapabilities, 'UTF-8') > self::MAX_CAPABILITIES_LENGTH) {
            return false;
        }

        if (!in_array($this->contextScope, self::ALLOWED_CONTEXT_SCOPES, true)) {
            return false;
        }

        if (in_array($this->contextScope, self::SCOPES_REQUIRING_RECORD_CONTEXT, true) && $this->recordContext === null) {
            return false;
        }

        if ($this->recordContext !== null) {
            if (!in_array($this->recordContext['table'] ?? '', self::ALLOWED_RECORD_TABLES, true)) {
                return false;
            }
            if (($this->recordContext['uid'] ?? 0) <= 0) {
                return false;
            }
            $rcField = $this->recordContext['field'] ?? '';
            if ($rcField === '' || preg_match('/^[a-z][a-z0-9_]*$/i', $rcField) !== 1) {
                return false;
            }
        }

        if (count($this->referencePages) > self::MAX_REFERENCE_PAGES) {
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

    /**
     * Extract and validate record context from data array.
     *
     * @param array<string, mixed> $data
     *
     * @return array{table: string, uid: int, field: string}|null
     */
    private static function extractRecordContext(array $data): ?array
    {
        $rc = $data['recordContext'] ?? null;
        if (!is_array($rc)) {
            return null;
        }

        $table = is_string($rc['table'] ?? null) ? $rc['table'] : '';
        $uid   = is_numeric($rc['uid'] ?? null) ? (int) $rc['uid'] : 0;
        $field = is_string($rc['field'] ?? null) ? $rc['field'] : '';

        if ($table === '' || $uid <= 0 || $field === '' || preg_match('/^[a-z][a-z0-9_]*$/i', $field) !== 1) {
            return null;
        }

        return ['table' => $table, 'uid' => $uid, 'field' => $field];
    }

    /**
     * Extract and validate reference pages from data array.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{pid: int, relation: string}>
     */
    private static function extractReferencePages(array $data): array
    {
        $pages = $data['referencePages'] ?? [];
        if (!is_array($pages)) {
            return [];
        }

        $result = [];
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $pid      = is_numeric($page['pid'] ?? null) ? (int) $page['pid'] : 0;
            $relation = is_string($page['relation'] ?? null) ? $page['relation'] : '';
            $relation = mb_substr($relation, 0, 100);
            if ($pid > 0) {
                $result[] = ['pid' => $pid, 'relation' => $relation];
            }
        }

        return $result;
    }
}
