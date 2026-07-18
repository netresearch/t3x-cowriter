<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tools;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;

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
     * Get the typed tool specification for the LLM API.
     */
    public static function spec(): ToolSpec
    {
        return ToolSpec::function(
            'query_content',
            'Query TYPO3 content elements by type or page. Returns metadata about content elements on a specific page.',
            [
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
        );
    }
}
