<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * The kind of a field, its label and the length every suggestion for it must
 * respect.
 */
final readonly class FieldProfile
{
    /**
     * Kinds named by table and field. A field of another table or extension
     * that is configured for suggestions gets the generic kind with its label.
     */
    private const KINDS = [
        'pages.title'               => FieldKind::PageTitle,
        'pages.nav_title'           => FieldKind::NavTitle,
        'pages.subtitle'            => FieldKind::Subtitle,
        'pages.abstract'            => FieldKind::Abstract,
        'pages.description'         => FieldKind::MetaDescription,
        'pages.og_title'            => FieldKind::OgTitle,
        'pages.og_description'      => FieldKind::OgDescription,
        'pages.twitter_title'       => FieldKind::TwitterTitle,
        'pages.twitter_description' => FieldKind::TwitterDescription,
        'tt_content.header'         => FieldKind::Headline,
        'tt_content.subheader'      => FieldKind::Subheader,
    ];

    /**
     * @param string $label the field's label as the editor sees it
     */
    public function __construct(
        public FieldKind $kind,
        public int $maxLength,
        public string $label = '',
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
            $type === 'slug'                          => FieldKind::Slug,
            isset(self::KINDS[$table . '.' . $field]) => self::KINDS[$table . '.' . $field],
            // EXT:seo adds these two to pages; extensions reuse the names.
            $field === 'seo_title' => FieldKind::SeoTitle,
            $field === 'keywords'  => FieldKind::Keywords,
            default                => FieldKind::Text,
        };

        $maxLength = $kind->defaultMaxLength();
        $tcaMax    = $config['max'] ?? null;
        if (is_numeric($tcaMax) && (int) $tcaMax > 0) {
            $maxLength = min($maxLength, (int) $tcaMax);
        }

        return new self($kind, $maxLength, self::columnLabel($field, $column));
    }

    /**
     * The column label, translated for the current backend user; the field
     * name when there is none.
     *
     * @param array<array-key, mixed> $column
     */
    public static function columnLabel(string $field, array $column): string
    {
        $label = is_string($column['label'] ?? null) ? trim($column['label']) : '';
        if (!self::isReference($label)) {
            return $label !== '' ? $label : $field;
        }

        $languageService = $GLOBALS['LANG'] ?? null;
        $label           = $languageService instanceof LanguageService ? trim($languageService->sL($label)) : '';

        // A reference that did not resolve means nothing to the model.
        $isReference = self::isReference($label);

        return $label === '' || $isReference ? $field : $label;
    }

    /**
     * "LLL:EXT:…", or a v14 translation domain key such as
     * "frontend.db.tt_content:bodytext".
     */
    private static function isReference(string $label): bool
    {
        return str_starts_with($label, 'LLL:') || preg_match('/^[\w.\-]+:[\w.\-]+$/', $label) === 1;
    }
}
