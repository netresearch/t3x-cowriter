<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\FieldSuggestion;

use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldKind;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\LanguageService;

#[CoversClass(FieldProfile::class)]
#[CoversClass(FieldKind::class)]
final class FieldProfileTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, array<string, mixed>, FieldKind, int}>
     */
    public static function tcaProvider(): iterable
    {
        yield 'pages.seo_title (EXT:seo, max 255)' => [
            'pages', 'seo_title', ['config' => ['type' => 'input', 'max' => 255]], FieldKind::SeoTitle, 60,
        ];
        yield 'pages.description (core text field)' => [
            'pages', 'description', ['config' => ['type' => 'text']], FieldKind::MetaDescription, 160,
        ];
        yield 'pages.keywords' => [
            'pages', 'keywords', ['config' => ['type' => 'text']], FieldKind::Keywords, 255,
        ];
        yield 'pages.slug' => [
            'pages', 'slug', ['config' => ['type' => 'slug']], FieldKind::Slug, 60,
        ];
        yield 'description outside pages is plain text' => [
            'sys_file_metadata', 'description', ['config' => ['type' => 'text']], FieldKind::Text, 255,
        ];
        yield 'generic input of another table' => [
            'tx_news_domain_model_news', 'teaser', ['config' => ['type' => 'text']], FieldKind::Text, 255,
        ];
        yield 'pages.title' => [
            'pages', 'title', ['config' => ['type' => 'input', 'max' => 255]], FieldKind::PageTitle, 70,
        ];
        yield 'pages.nav_title' => [
            'pages', 'nav_title', ['config' => ['type' => 'input', 'max' => 255]], FieldKind::NavTitle, 30,
        ];
        yield 'pages.subtitle' => [
            'pages', 'subtitle', ['config' => ['type' => 'input', 'max' => 255]], FieldKind::Subtitle, 120,
        ];
        yield 'pages.abstract' => [
            'pages', 'abstract', ['config' => ['type' => 'text']], FieldKind::Abstract, 500,
        ];
        yield 'pages.og_title' => [
            'pages', 'og_title', ['config' => ['type' => 'input', 'max' => 255]], FieldKind::OgTitle, 70,
        ];
        yield 'pages.og_description' => [
            'pages', 'og_description', ['config' => ['type' => 'text']], FieldKind::OgDescription, 200,
        ];
        yield 'pages.twitter_title' => [
            'pages', 'twitter_title', ['config' => ['type' => 'input', 'max' => 255]], FieldKind::TwitterTitle, 70,
        ];
        yield 'pages.twitter_description' => [
            'pages', 'twitter_description', ['config' => ['type' => 'text']], FieldKind::TwitterDescription, 200,
        ];
        yield 'tt_content.header' => [
            'tt_content', 'header', ['config' => ['type' => 'input', 'max' => 255]], FieldKind::Headline, 80,
        ];
        yield 'tt_content.subheader' => [
            'tt_content', 'subheader', ['config' => ['type' => 'input', 'max' => 255]], FieldKind::Subheader, 120,
        ];
        yield 'a page field name on another table is generic' => [
            'tt_content', 'title', ['config' => ['type' => 'input']], FieldKind::Text, 255,
        ];
        yield 'TCA max lower than the guidance wins' => [
            'pages', 'seo_title', ['config' => ['type' => 'input', 'max' => 40]], FieldKind::SeoTitle, 40,
        ];
        yield 'TCA max of zero is ignored' => [
            'pages', 'seo_title', ['config' => ['type' => 'input', 'max' => 0]], FieldKind::SeoTitle, 60,
        ];
        yield 'numeric-string TCA max is honoured' => [
            'pages', 'seo_title', ['config' => ['type' => 'input', 'max' => '30']], FieldKind::SeoTitle, 30,
        ];
        yield 'non-numeric TCA max is ignored' => [
            'pages', 'seo_title', ['config' => ['type' => 'input', 'max' => 'long']], FieldKind::SeoTitle, 60,
        ];
        yield 'slug type wins over the field name' => [
            'tx_news_domain_model_news', 'keywords', ['config' => ['type' => 'slug']], FieldKind::Slug, 60,
        ];
        yield 'missing config' => [
            'tx_foo', 'subtitle', [], FieldKind::Text, 255,
        ];
    }

    /**
     * @param array<string, mixed> $column
     */
    #[Test]
    #[DataProvider('tcaProvider')]
    public function fromTcaDerivesKindAndLengthLimit(string $table, string $field, array $column, FieldKind $kind, int $maxLength): void
    {
        $profile = FieldProfile::fromTca($table, $field, $column);

        self::assertSame($kind, $profile->kind);
        self::assertSame($maxLength, $profile->maxLength);
    }

    #[Test]
    public function everyInstructionNamesItsLengthLimit(): void
    {
        foreach (FieldKind::cases() as $kind) {
            self::assertStringContainsString('at most 42 characters', $kind->instruction(42, 'subtitle'), $kind->name);
        }
    }

    #[Test]
    public function instructionsDescribeTheirField(): void
    {
        self::assertStringContainsString('SEO title', FieldKind::SeoTitle->instruction(60, 'seo_title'));
        self::assertStringContainsString('meta description', FieldKind::MetaDescription->instruction(160, 'description'));
        self::assertStringContainsString('comma-separated list', FieldKind::Keywords->instruction(255, 'keywords'));
        self::assertStringContainsString('without slashes', FieldKind::Slug->instruction(60, 'slug'));
        self::assertStringContainsString('navigation menus', FieldKind::NavTitle->instruction(30, 'nav_title'));
        self::assertStringContainsString('(Open Graph)', FieldKind::OgDescription->instruction(200, 'og_description'));
        self::assertStringContainsString('X (Twitter)', FieldKind::TwitterTitle->instruction(70, 'twitter_title'));
        self::assertStringContainsString('content element', FieldKind::Headline->instruction(80, 'header'));
        self::assertStringContainsString('without repeating it', FieldKind::Subheader->instruction(120, 'subheader'));
        self::assertStringContainsString('"Teaser text"', FieldKind::Text->instruction(255, 'Teaser text'));
    }

    #[Test]
    public function everyKindHasItsOwnPurpose(): void
    {
        $purposes = array_map(static fn (FieldKind $kind): string => $kind->purpose(50, 'label'), FieldKind::cases());

        self::assertSame($purposes, array_values(array_unique($purposes)));
    }

    #[Test]
    public function theLabelIsTheTranslatedTcaLabel(): void
    {
        $languageService = $this->createStub(LanguageService::class);
        $languageService->method('sL')->willReturnMap([
            ['LLL:EXT:news/locallang_db.xlf:teaser', 'Teaser'],
            ['LLL:EXT:missing/locallang.xlf:x', ''],
        ]);
        $GLOBALS['LANG'] = $languageService;

        try {
            self::assertSame('Teaser', FieldProfile::fromTca('tx_news', 'teaser', ['label' => 'LLL:EXT:news/locallang_db.xlf:teaser'])->label);
            self::assertSame('x_field', FieldProfile::fromTca('tx_news', 'x_field', ['label' => 'LLL:EXT:missing/locallang.xlf:x'])->label);
        } finally {
            unset($GLOBALS['LANG']);
        }
    }

    #[Test]
    public function withoutLanguageServiceAPlainLabelIsKeptAndAReferenceFallsBackToTheFieldName(): void
    {
        self::assertSame('Teaser', FieldProfile::fromTca('tx_news', 'teaser', ['label' => ' Teaser '])->label);
        self::assertSame('teaser', FieldProfile::fromTca('tx_news', 'teaser', ['label' => 'LLL:EXT:news/x.xlf:teaser'])->label);
        self::assertSame('teaser', FieldProfile::fromTca('tx_news', 'teaser', [])->label);
        self::assertSame('bodytext', FieldProfile::fromTca('tt_content', 'bodytext', ['label' => 'frontend.db.tt_content:bodytext'])->label);
        self::assertSame('Note: short', FieldProfile::fromTca('tx_news', 'note', ['label' => 'Note: short'])->label);
    }
}
