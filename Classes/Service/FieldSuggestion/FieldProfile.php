<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

/**
 * The kind of a field plus the length every suggestion for it must respect.
 */
final readonly class FieldProfile
{
    public function __construct(
        public FieldKind $kind,
        public int $maxLength,
    ) {}

    /**
     * Derive the profile from the field's TCA column.
     *
     * The TCA `max` of an input field is a hard limit and wins over the kind's
     * guidance when it is lower.
     *
     * @param array<array-key, mixed> $column the TCA column definition
     */
    public static function fromTca(string $table, string $field, array $column): self
    {
        $config = is_array($column['config'] ?? null) ? $column['config'] : [];
        $type   = is_string($config['type'] ?? null) ? $config['type'] : '';

        $kind = match (true) {
            $type === 'slug'                               => FieldKind::Slug,
            $field === 'seo_title'                         => FieldKind::SeoTitle,
            $table === 'pages' && $field === 'description' => FieldKind::MetaDescription,
            $field === 'keywords'                          => FieldKind::Keywords,
            default                                        => FieldKind::Text,
        };

        $maxLength = $kind->defaultMaxLength();
        $tcaMax    = $config['max'] ?? null;
        if (is_numeric($tcaMax) && (int) $tcaMax > 0) {
            $maxLength = min($maxLength, (int) $tcaMax);
        }

        return new self($kind, $maxLength);
    }
}
