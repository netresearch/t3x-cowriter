<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Editor-facing texts from locallang_be.xlf in the backend user's language.
 *
 * TYPO3 puts the user's LanguageService into $GLOBALS['LANG'] for every
 * authenticated backend request, AJAX routes included (BackendUserAuthenticator,
 * TYPO3 13.4 and 14.3); without it one is created from the backend user's
 * preferences. Log messages stay English and do not go through this class.
 */
final readonly class BackendLabels
{
    public const LOCALLANG_BE = 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_be.xlf:';

    /**
     * The label with its %s / %d placeholders filled in order.
     */
    public function get(string $key, string|int ...$arguments): string
    {
        $label = $this->languageService()->sL(self::LOCALLANG_BE . $key);

        return $arguments === [] ? $label : sprintf($label, ...$arguments);
    }

    public function languageService(): LanguageService
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if ($languageService instanceof LanguageService) {
            return $languageService;
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return GeneralUtility::makeInstance(LanguageServiceFactory::class)
            ->createFromUserPreferences($backendUser instanceof AbstractUserAuthentication ? $backendUser : null);
    }
}
