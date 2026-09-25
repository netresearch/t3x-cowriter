<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

/**
 * What a field holds, which decides the prompt and the post-processing.
 */
enum FieldKind: string
{
    case SeoTitle        = 'seoTitle';
    case MetaDescription = 'metaDescription';
    case Keywords        = 'keywords';
    case Slug            = 'slug';
    case Text            = 'text';

    /**
     * The length guidance for the field. It is part of the prompt and enforced
     * again in code, because a model does not count characters reliably.
     */
    public function defaultMaxLength(): int
    {
        return match ($this) {
            self::SeoTitle        => 60,
            self::MetaDescription => 160,
            self::Keywords        => 255,
            // The last path segment only; the parent path is added by TYPO3.
            self::Slug => 60,
            self::Text => 255,
        };
    }

    /**
     * The field-specific instruction for the model.
     */
    public function instruction(int $maxLength, string $fieldName): string
    {
        return match ($this) {
            self::SeoTitle => sprintf(
                'Write SEO titles for the HTML <title> of this page. Each title has at most %d characters, names the page topic first and does not repeat the site name.',
                $maxLength,
            ),
            self::MetaDescription => sprintf(
                'Write meta descriptions for search result snippets of this page. Each description is one or two complete sentences with at most %d characters and summarises what the reader finds on the page.',
                $maxLength,
            ),
            self::Keywords => sprintf(
                'Write keyword sets (tags) for this page. Each set is a comma-separated list of 3 to 8 short keywords with at most %d characters in total.',
                $maxLength,
            ),
            self::Slug => sprintf(
                'Write the last URL path segment for this page. Each segment is 1 to 5 plain words describing the topic, at most %d characters, without slashes and without the parent path.',
                $maxLength,
            ),
            self::Text => sprintf(
                'Write alternative values for the field "%s". Each value has at most %d characters.',
                $fieldName,
                $maxLength,
            ),
        };
    }
}
