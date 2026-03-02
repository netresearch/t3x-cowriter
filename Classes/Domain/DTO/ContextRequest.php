<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Request DTO for context preview AJAX endpoint.
 *
 * @internal
 */
final readonly class ContextRequest
{
    private const ALLOWED_TABLES = ['tt_content', 'pages'];

    private const ALLOWED_SCOPES = [
        'selection',
        'text',
        'element',
        'page',
        'ancestors_1',
        'ancestors_2',
    ];

    public function __construct(
        public string $table,
        public int $uid,
        public string $field,
        public string $scope,
    ) {}

    public static function fromQueryParams(ServerRequestInterface $request): self
    {
        $params = $request->getQueryParams();

        return new self(
            table: is_string($params['table'] ?? null) ? $params['table'] : '',
            uid: is_numeric($params['uid'] ?? null) ? (int) $params['uid'] : 0,
            field: is_string($params['field'] ?? null) ? $params['field'] : '',
            scope: is_string($params['scope'] ?? null) ? $params['scope'] : '',
        );
    }

    public function isValid(): bool
    {
        if (!in_array($this->table, self::ALLOWED_TABLES, true)) {
            return false;
        }

        if ($this->uid <= 0) {
            return false;
        }

        if ($this->field === '') {
            return false;
        }

        return in_array($this->scope, self::ALLOWED_SCOPES, true);
    }
}
