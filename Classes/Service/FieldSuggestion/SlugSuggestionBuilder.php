<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Builds a complete slug from a suggested last path segment.
 *
 * The model only proposes the words of the last segment. Everything else —
 * the parent page prefix, replacements, sanitising, the fallback character,
 * postModifiers — is TYPO3's own SlugHelper::generate(), run with the field's
 * TCA configuration and the segment standing in for the generator fields.
 * Uniqueness stays with TYPO3 as well: the slug element checks it when the
 * value is picked, DataHandler enforces it on save.
 */
class SlugSuggestionBuilder
{
    /**
     * Record key the segment is passed under; not a real column.
     */
    private const SEGMENT_FIELD = '__cowriter_slug_segment';

    /**
     * @return string the full slug, or '' when the segment holds no usable character
     */
    public function build(string $segment, RecordContext $context): string
    {
        $column = Tca::column($context->table, $context->field);
        if ($column === null) {
            return '';
        }

        $fieldConfig = Tca::config($column);

        // Only the last segment is the model's: a slash would add path levels.
        $segment = trim(str_replace('/', ' ', $segment));

        $generatorOptions                = is_array($fieldConfig['generatorOptions'] ?? null) ? $fieldConfig['generatorOptions'] : [];
        $generatorOptions['fields']      = [[self::SEGMENT_FIELD]];
        $fieldConfig['generatorOptions'] = $generatorOptions;

        /** @var array<string, mixed> $fieldConfig */
        $slugHelper = GeneralUtility::makeInstance(SlugHelper::class, $context->table, $context->field, $fieldConfig);

        // Without a single usable character SlugHelper invents "default-<hash>".
        if (trim($slugHelper->sanitize($segment), '/-_') === '') {
            return '';
        }

        $record                      = $context->record;
        $record[self::SEGMENT_FIELD] = $segment;

        return $slugHelper->generate($record, $context->slugPid);
    }
}
