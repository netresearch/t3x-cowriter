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
    public const DEFAULT_FIELDS = 'pages.seo_title,pages.description,pages.keywords,pages.slug';

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

        $fields = [];
        foreach (explode(',', $setting) as $entry) {
            if (preg_match('/^\s*([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)\s*$/', $entry, $match) === 1) {
                $fields[] = [$match[1], $match[2]];
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
