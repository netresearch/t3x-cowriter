<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

use Netresearch\T3Cowriter\Domain\DTO\FieldSuggestionRequest;
use Netresearch\T3Cowriter\Service\CallerSource;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Extension configuration of the field suggestion button
 * (`fieldSuggestions.fields`, `fieldSuggestions.count` in ext_conf_template.txt).
 *
 * Extension configuration rather than TSconfig, because the button is
 * registered in the TCA, which is compiled once for the whole instance and
 * cannot vary per page.
 */
final readonly class FieldSuggestionConfiguration
{
    /**
     * The fields the button is meant for: short descriptive text an editor
     * writes about a page or a content element. Fields that do not exist in
     * an installation (EXT:seo not installed) are skipped by the listener.
     */
    public const DEFAULT_FIELDS = 'pages.title,pages.nav_title,pages.subtitle,pages.abstract,'
        . 'pages.seo_title,pages.description,pages.keywords,'
        . 'pages.og_title,pages.og_description,pages.twitter_title,pages.twitter_description,'
        . 'pages.slug,tt_content.header,tt_content.subheader';

    /**
     * The setting's value up to version 3.19. TYPO3 writes a setting's default
     * into settings.php when the extension is set up, so most installations
     * hold this literal string without anybody having chosen it; it is read as
     * the current default.
     */
    public const LEGACY_DEFAULT_FIELDS = 'pages.seo_title,pages.description,pages.keywords,pages.slug';

    /**
     * Entry that stands for DEFAULT_FIELDS inside the setting, so an
     * integrator can add fields and still get the ones later versions add.
     */
    public const DEFAULT_TOKEN = 'default';

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * The configured fields as [table, field] pairs; malformed entries are
     * skipped, an empty setting switches the button off.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function fields(): array
    {
        $setting = $this->setting('fields', self::DEFAULT_FIELDS);
        if (preg_replace('/\s+/', '', $setting) === self::LEGACY_DEFAULT_FIELDS) {
            $setting = self::DEFAULT_FIELDS;
        }

        $fields = [];
        foreach (explode(',', $setting) as $entry) {
            $entries = trim($entry) === self::DEFAULT_TOKEN ? explode(',', self::DEFAULT_FIELDS) : [$entry];
            foreach ($entries as $candidate) {
                if (preg_match('/^\s*([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)\s*$/', $candidate, $match) === 1
                    && !in_array([$match[1], $match[2]], $fields, true)
                ) {
                    $fields[] = [$match[1], $match[2]];
                }
            }
        }

        return $fields;
    }

    /**
     * How many suggestions the button asks for, within the request limits.
     */
    public function count(): int
    {
        $setting = $this->setting('count', (string) FieldSuggestionRequest::DEFAULT_COUNT);

        if (!ctype_digit($setting)) {
            return FieldSuggestionRequest::DEFAULT_COUNT;
        }

        return max(FieldSuggestionRequest::MIN_COUNT, min(FieldSuggestionRequest::MAX_COUNT, (int) $setting));
    }

    private function setting(string $key, string $default): string
    {
        try {
            $value = $this->extensionConfiguration->get(CallerSource::EXTENSION, 'fieldSuggestions/' . $key);
        } catch (Throwable) {
            // Not configured yet (no settings.php entry): the documented default applies.
            return $default;
        }

        return is_scalar($value) ? trim((string) $value) : $default;
    }
}
