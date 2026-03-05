<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tools;

/**
 * Tool definition for LLM function calling.
 *
 * Provides a structured tool that allows the LLM to query
 * TYPO3 page and content element metadata during conversations.
 *
 * @internal
 */
final class ContentQueryTool
{
    /**
     * Get the tool definition for the LLM API.
     *
     * @return array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}
     */
    public static function definition(): array
    {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => 'query_content',
                'description' => 'Query TYPO3 content elements by type or page. Returns metadata about content elements on a specific page.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'pageId' => [
                            'type'        => 'integer',
                            'description' => 'TYPO3 page UID to query content from',
                        ],
                        'contentType' => [
                            'type'        => 'string',
                            'description' => 'Content element type filter (e.g., text, textmedia, header)',
                        ],
                    ],
                    'required' => ['pageId'],
                ],
            ],
        ];
    }
}
