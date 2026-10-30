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
    case PageTitle          = 'pageTitle';
    case NavTitle           = 'navTitle';
    case Subtitle           = 'subtitle';
    case Abstract           = 'abstract';
    case SeoTitle           = 'seoTitle';
    case MetaDescription    = 'metaDescription';
    case Keywords           = 'keywords';
    case OgTitle            = 'ogTitle';
    case OgDescription      = 'ogDescription';
    case TwitterTitle       = 'twitterTitle';
    case TwitterDescription = 'twitterDescription';
    case Slug               = 'slug';
    case Headline           = 'headline';
    case Subheader          = 'subheader';
    case Text               = 'text';

    /**
     * The length guidance for the field. It is part of the prompt and enforced
     * again in code, because a model does not count characters reliably.
     */
    public function defaultMaxLength(): int
    {
        return match ($this) {
            // Shown in the page tree, menus and breadcrumbs, and the fallback
            // of the HTML <title> when no SEO title is set.
            self::PageTitle => 70,
            self::NavTitle  => 30,
            self::Subtitle  => 120,
            self::Abstract  => 500,
            // Search engines cut titles at about 60 characters and snippets at
            // about 160.
            self::SeoTitle        => 60,
            self::MetaDescription => 160,
            self::Keywords        => 255,
            // Link previews of social networks show about 70 characters of a
            // title and two lines (about 200 characters) of a description.
            self::OgTitle, self::TwitterTitle             => 70,
            self::OgDescription, self::TwitterDescription => 200,
            // The last path segment only; the parent path is added by TYPO3.
            self::Slug      => 60,
            self::Headline  => 80,
            self::Subheader => 120,
            self::Text      => 255,
        };
    }

    /**
     * What one value of the field is, for the model: purpose, form and limit.
     *
     * @param string $fieldLabel the field's label as the editor sees it
     */
    public function purpose(int $maxLength, string $fieldLabel): string
    {
        $description = match ($this) {
            self::PageTitle          => 'the page title, shown in the page tree, in menus and breadcrumbs and as the browser title when no SEO title is set. It names the page topic precisely, without the site name',
            self::NavTitle           => 'the short label of the page in navigation menus: 1 to 3 words',
            self::Subtitle           => 'the subtitle of the page: one short phrase that adds to the page title without repeating it',
            self::Abstract           => 'the abstract of the page: a summary of 1 to 3 complete sentences, used in teasers and page lists',
            self::SeoTitle           => 'an SEO title for the HTML <title> of this page. It names the page topic first and does not repeat the site name',
            self::MetaDescription    => 'a meta description for search result snippets of this page: one or two complete sentences that summarise what the reader finds on the page',
            self::Keywords           => 'a keyword set (tags) for this page: a comma-separated list of 3 to 8 short keywords',
            self::OgTitle            => 'the title of the link preview when this page is shared on social networks (Open Graph). It is catchy and names the page topic',
            self::OgDescription      => 'the description of the link preview when this page is shared on social networks (Open Graph): one or two sentences that make the reader want to open the page',
            self::TwitterTitle       => 'the title of the card when this page is shared on X (Twitter). It is catchy and names the page topic',
            self::TwitterDescription => 'the description of the card when this page is shared on X (Twitter): one or two sentences that make the reader want to open the page',
            self::Slug               => 'the last URL path segment of this page: 1 to 5 plain words describing the topic, without slashes and without the parent path',
            self::Headline           => "the headline of this content element. It names what the element is about, based on the element's own text",
            self::Subheader          => 'the subheader of this content element: one phrase that adds to the headline without repeating it',
            self::Text               => sprintf('a value for the field "%s" of this record', $fieldLabel),
        };

        return sprintf('%s; at most %d characters.', $description, $maxLength);
    }

    /**
     * The field-specific instruction for the model when it suggests several
     * values for this one field.
     *
     * @param string $fieldLabel the field's label as the editor sees it
     */
    public function instruction(int $maxLength, string $fieldLabel): string
    {
        return sprintf('The field is "%s". Each suggestion is %s', $fieldLabel, $this->purpose($maxLength, $fieldLabel));
    }
}
