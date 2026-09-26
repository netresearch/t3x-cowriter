<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Support;

use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Puts a LanguageService into $GLOBALS['LANG'] that resolves the extension's
 * labels from its language files, as TYPO3 does for a backend request. Tests
 * without a TYPO3 container use it so BackendLabels answers in English (or
 * German) instead of asking GeneralUtility for a LanguageServiceFactory.
 *
 * @mixin \PHPUnit\Framework\TestCase
 */
trait XliffLanguageServiceTrait
{
    private function useXliffLanguageService(string $language = 'en'): void
    {
        $languageService = $this->createStub(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn (string $reference): string => XliffFile::label($reference, $language),
        );
        $GLOBALS['LANG'] = $languageService;
    }
}
