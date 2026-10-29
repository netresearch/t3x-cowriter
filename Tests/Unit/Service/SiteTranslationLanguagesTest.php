<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service;

use Netresearch\T3Cowriter\Service\SiteTranslationLanguages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

#[CoversClass(SiteTranslationLanguages::class)]
final class SiteTranslationLanguagesTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $languages
     */
    public static function site(array $languages): Site
    {
        return new Site('main', 1, ['base' => 'https://example.org/', 'languages' => $languages]);
    }

    #[Test]
    public function aSiteWithTwoLanguagesOffersBothWithTitleAndFormality(): void
    {
        $site = self::site([
            ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
            ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/', 'cowriterFormality' => 'formal'],
        ]);

        self::assertSame(
            [
                ['code' => 'en-US', 'label' => 'English', 'formality' => 'default'],
                ['code' => 'de', 'label' => 'Deutsch', 'formality' => 'formal'],
            ],
            (new SiteTranslationLanguages())->forSite($site),
        );
    }

    #[Test]
    public function aLanguageHiddenFromTheFrontendIsOffered(): void
    {
        $site = self::site([
            ['languageId' => 0, 'title' => 'English', 'locale' => 'en_GB.UTF-8', 'base' => '/'],
            ['languageId' => 1, 'title' => 'Français', 'locale' => 'fr_FR.UTF-8', 'base' => '/fr/', 'enabled' => false],
        ]);

        self::assertSame(
            ['en-GB', 'fr'],
            array_column((new SiteTranslationLanguages())->forSite($site), 'code'),
        );
    }

    #[Test]
    public function aLanguageWithoutATwoLetterCodeIsLeftOut(): void
    {
        $site = self::site([
            ['languageId' => 0, 'title' => 'Filipino', 'locale' => 'fil_PH.UTF-8', 'base' => '/'],
            ['languageId' => 1, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/en/'],
        ]);

        self::assertSame(
            ['en-US'],
            array_column((new SiteTranslationLanguages())->forSite($site), 'code'),
        );
    }

    #[Test]
    public function aLanguageWithABlankTitleIsLabelledWithItsCode(): void
    {
        $site = self::site([
            ['languageId' => 0, 'title' => '  ', 'locale' => 'pt_BR.UTF-8', 'base' => '/'],
        ]);

        self::assertSame(
            [['code' => 'pt-BR', 'label' => 'pt-BR', 'formality' => 'default']],
            (new SiteTranslationLanguages())->forSite($site),
        );
    }

    #[Test]
    public function aRecordOutsideEverySiteGetsNoLanguages(): void
    {
        self::assertSame([], (new SiteTranslationLanguages())->forSite(new NullSite()));
    }

    #[Test]
    public function theLanguagesOfANullSiteDoNotCount(): void
    {
        $nullSite = new NullSite([
            ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8'],
            ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8'],
        ]);

        self::assertSame([], (new SiteTranslationLanguages())->forSite($nullSite));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function localeProvider(): iterable
    {
        yield 'region dropped for German' => ['de_DE.UTF-8', 'de'];
        yield 'region dropped for Swiss German' => ['de_CH.UTF-8', 'de'];
        yield 'British English keeps its region' => ['en_GB.UTF-8', 'en-GB'];
        yield 'American English keeps its region' => ['en_US.UTF-8', 'en-US'];
        yield 'other English regions fall back to en' => ['en_AU.UTF-8', 'en'];
        yield 'Brazilian Portuguese keeps its region' => ['pt_BR.UTF-8', 'pt-BR'];
        yield 'European Portuguese keeps its region' => ['pt_PT.UTF-8', 'pt-PT'];
        yield 'Simplified Chinese' => ['zh_CN.UTF-8', 'zh'];
        yield 'Chinese without region' => ['zh', 'zh'];
        yield 'Traditional Chinese, Taiwan' => ['zh_TW.UTF-8', 'zh-TW'];
        yield 'Traditional Chinese, Hong Kong' => ['zh_HK.UTF-8', 'zh-HK'];
        yield 'Traditional Chinese, Macau' => ['zh_MO.UTF-8', 'zh-MO'];
        yield 'Traditional script with region' => ['zh-Hant-HK', 'zh-HK'];
        yield 'Traditional script without region' => ['zh-Hant', 'zh-TW'];
        yield 'Simplified script in a Traditional region' => ['zh-Hans-HK', 'zh'];
        yield 'Simplified Chinese, Singapore' => ['zh_SG.UTF-8', 'zh'];
        yield 'locale without region' => ['nl', 'nl'];
        yield 'hyphenated locale' => ['fr-CA', 'fr'];
        yield 'mixed case' => ['EN_gb.UTF-8', 'en-GB'];
        yield 'three-letter language' => ['fil_PH.UTF-8', null];
        yield 'C locale' => ['C', null];
        yield 'C locale with code set' => ['C.UTF-8', null];
        yield 'POSIX locale' => ['POSIX', null];
    }

    #[Test]
    #[DataProvider('localeProvider')]
    public function targetCodeFollowsTheLocale(string $locale, ?string $expected): void
    {
        self::assertSame($expected, SiteTranslationLanguages::targetCode(new Locale($locale)));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function formalityProvider(): iterable
    {
        yield 'unset' => [[], 'default'];
        yield 'formal' => [['cowriterFormality' => 'formal'], 'formal'];
        yield 'informal' => [['cowriterFormality' => 'informal'], 'informal'];
        yield 'default' => [['cowriterFormality' => 'default'], 'default'];
        yield 'unknown value' => [['cowriterFormality' => 'polite'], 'default'];
        yield 'DeepL value nr-llm refuses' => [['cowriterFormality' => 'more'], 'default'];
        yield 'not a string' => [['cowriterFormality' => true], 'default'];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    #[Test]
    #[DataProvider('formalityProvider')]
    public function formalityIsReadFromTheSiteLanguage(array $configuration, string $expected): void
    {
        $language = new SiteLanguage(1, 'de_DE.UTF-8', new Uri('/de/'), $configuration);

        self::assertSame($expected, SiteTranslationLanguages::formality($language));
    }
}
