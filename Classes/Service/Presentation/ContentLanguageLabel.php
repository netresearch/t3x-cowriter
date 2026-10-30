<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Presentation;

use InvalidArgumentException;
use Netresearch\T3Cowriter\Service\BackendLabels;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * A label of this extension in the language of the content, not of the
 * editor: a title the new element shows to visitors ("Introduction") must be
 * in the language of the page.
 *
 * @internal
 */
class ContentLanguageLabel
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * The label in the locale of the page's site language $languageId; null
     * when the page belongs to no site or the site has no such language.
     */
    public function get(string $key, int $pageUid, int $languageId): ?string
    {
        try {
            $language = $this->siteFinder
                ->getSiteByPageId($pageUid, BackendUtility::BEgetRootLine($pageUid, '', true))
                ->getLanguageById($languageId);
        } catch (SiteNotFoundException|InvalidArgumentException) {
            return null;
        }

        // From the site language, not its Locale object: on TYPO3 13.4 only
        // this resolves "de_DE" to the "de" labels.
        $label = $this->languageServiceFactory->createFromSiteLanguage($language)->sL(BackendLabels::LOCALLANG_BE . $key);

        return $label !== '' ? $label : null;
    }
}
