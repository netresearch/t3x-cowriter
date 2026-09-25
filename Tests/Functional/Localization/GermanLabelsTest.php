<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Localization;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * TYPO3 itself finds the extension's `de.` language files: a German
 * LanguageService returns the German text, an English one the English text.
 */
#[CoversNothing]
final class GermanLabelsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
    ];

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function labelProvider(): iterable
    {
        yield 'field suggestion heading' => [
            'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_be.xlf:fieldSuggestions.heading',
            'AI suggestions',
            'KI-Vorschläge',
        ];
        yield 'status module title' => [
            'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_mod_status.xlf:mlang_tabs_tab',
            'Cowriter Status',
            'Cowriter-Status',
        ];
    }

    #[Test]
    #[DataProvider('labelProvider')]
    public function labelFollowsTheBackendLanguage(string $reference, string $english, string $german): void
    {
        $factory = $this->get(LanguageServiceFactory::class);

        self::assertSame($english, $factory->create('default')->sL($reference));
        self::assertSame($german, $factory->create('de')->sL($reference));
    }
}
