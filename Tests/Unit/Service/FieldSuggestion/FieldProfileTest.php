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
        yield 'generic input' => [
            'pages', 'nav_title', ['config' => ['type' => 'input', 'max' => 255]], FieldKind::Text, 255,
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
            'pages', 'subtitle', [], FieldKind::Text, 255,
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
        self::assertStringContainsString('SEO titles', FieldKind::SeoTitle->instruction(60, 'seo_title'));
        self::assertStringContainsString('meta descriptions', FieldKind::MetaDescription->instruction(160, 'description'));
        self::assertStringContainsString('comma-separated list', FieldKind::Keywords->instruction(255, 'keywords'));
        self::assertStringContainsString('without slashes', FieldKind::Slug->instruction(60, 'slug'));
        self::assertStringContainsString('"subtitle"', FieldKind::Text->instruction(255, 'subtitle'));
    }
}
