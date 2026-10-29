<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

use Netresearch\T3Cowriter\Service\SiteTranslationLanguages;

defined('TYPO3') || exit;

// The Sites module rebuilds a language from its form fields on save and drops
// every key it has no field for, so the formality setting needs a field here
// to survive an edit in the module.
(static function (): void {
    $siteConfiguration = $GLOBALS['SiteConfiguration'] ?? null;
    if (!is_array($siteConfiguration) || !is_array($siteConfiguration['site_language'] ?? null)) {
        return;
    }

    $labels       = 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_be.xlf:siteLanguage.cowriterFormality';
    $siteLanguage = $siteConfiguration['site_language'];

    $columns                                          = is_array($siteLanguage['columns'] ?? null) ? $siteLanguage['columns'] : [];
    $columns[SiteTranslationLanguages::FORMALITY_KEY] = [
        'label'       => $labels,
        'description' => $labels . '.description',
        'config'      => [
            'type'       => 'select',
            'renderType' => 'selectSingle',
            'items'      => [
                ['label' => $labels . '.default', 'value' => 'default'],
                ['label' => $labels . '.formal', 'value' => 'formal'],
                ['label' => $labels . '.informal', 'value' => 'informal'],
            ],
            'default' => SiteTranslationLanguages::FORMALITY_DEFAULT,
        ],
    ];
    $siteLanguage['columns'] = $columns;

    $types            = is_array($siteLanguage['types'] ?? null) ? $siteLanguage['types'] : [];
    $type             = is_array($types[1] ?? null) ? $types[1] : [];
    $showitem         = is_string($type['showitem'] ?? null) ? $type['showitem'] : '';
    $type['showitem'] = ltrim($showitem . ', ' . SiteTranslationLanguages::FORMALITY_KEY, ', ');
    $types[1]         = $type;

    $siteLanguage['types']              = $types;
    $siteConfiguration['site_language'] = $siteLanguage;
    $GLOBALS['SiteConfiguration']       = $siteConfiguration;
})();
