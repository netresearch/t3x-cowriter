<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * The target languages of the RTE translate menu, read from a site's
 * languages: one entry per enabled language, labelled with its title and
 * carrying the formality set on it.
 *
 * Target code rule: the lower-case ISO 639-1 code of the language's locale
 * ("de_DE.UTF-8" becomes "de"). The region is kept only where the
 * translators distinguish written variants by it: English (en-GB, en-US) and
 * Portuguese (pt-BR, pt-PT), which DeepL lists as targets of their own. Any
 * other region is dropped, because a code such as "de-DE" is not in DeepL's
 * list and would route the request away from DeepL. A language whose code has
 * no two-letter form is left out: nr-llm accepts only "xx" or "xx-YY".
 */
final readonly class SiteTranslationLanguages
{
    /**
     * The key on a site language that sets the formality of translations
     * into it (config/sites/<site>/config.yaml, languages[]).
     */
    public const FORMALITY_KEY = 'cowriterFormality';

    public const FORMALITY_DEFAULT = 'default';

    /**
     * The values nr-llm's TranslationService accepts; any other value makes
     * it throw, so an unknown setting falls back to the default.
     */
    public const FORMALITIES = [self::FORMALITY_DEFAULT, 'formal', 'informal'];

    /**
     * Regions kept in the target code, per language.
     */
    private const REGIONAL_TARGETS = [
        'en' => ['GB', 'US'],
        'pt' => ['BR', 'PT'],
    ];

    /**
     * The site's translation targets; empty when the record belongs to no site.
     *
     * @return list<array{code: string, label: string, formality: string}>
     */
    public function forSite(SiteInterface $site): array
    {
        // A record outside every site (pid 0, a folder above all site roots)
        // gets a NullSite. Its languages are placeholders or installation-wide
        // defaults, not the languages of a site configuration, so they do not
        // count; the RTE then keeps its fixed list.
        if (!$site instanceof Site) {
            return [];
        }

        $languages = [];
        foreach ($site->getLanguages() as $language) {
            $code = self::targetCode($language->getLocale());
            if ($code === null) {
                continue;
            }

            $title       = trim($language->getTitle());
            $languages[] = [
                'code'      => $code,
                'label'     => $title !== '' ? $title : $code,
                'formality' => self::formality($language),
            ];
        }

        return $languages;
    }

    /**
     * The translation target code for a locale, or null when it has none.
     */
    public static function targetCode(Locale $locale): ?string
    {
        // Locale has already lower-cased the language and upper-cased the
        // country ("de_de" and "DE-de" both give "de" and "DE").
        $language = $locale->getLanguageCode();
        if (preg_match('/^[a-z]{2}$/', $language) !== 1) {
            return null;
        }

        $country = $locale->getCountryCode() ?? '';
        if (in_array($country, self::REGIONAL_TARGETS[$language] ?? [], true)) {
            return $language . '-' . $country;
        }

        return $language;
    }

    /**
     * The formality set on a site language, "default" when unset or unknown.
     */
    public static function formality(SiteLanguage $language): string
    {
        $value = $language->getConfiguration()[self::FORMALITY_KEY] ?? null;

        return is_string($value) && in_array($value, self::FORMALITIES, true)
            ? $value
            : self::FORMALITY_DEFAULT;
    }
}
