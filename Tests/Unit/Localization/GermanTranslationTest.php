<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Localization;

use Netresearch\T3Cowriter\Tests\Support\XliffFile;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every English language file has a German `de.` counterpart with the same
 * trans-units, and every German text keeps the placeholders of its English
 * source.
 */
#[CoversNothing]
final class GermanTranslationTest extends TestCase
{
    /**
     * printf conversions (%s, %d, %1$s …) and ICU-style {0}.
     */
    private const PLACEHOLDER_PATTERN = '/%(?:\d+\$)?[sdfu]|\{\d+\}/';

    /**
     * @return list<string>
     */
    private static function englishFiles(): array
    {
        $files = [];
        foreach (glob(XliffFile::LANGUAGE_DIRECTORY . '/*.xlf') ?: [] as $path) {
            // A language prefix ("de.", "de_CH.") marks a translation.
            if (preg_match('/^[a-z]{2}(_[A-Z]{2})?\./', basename($path)) !== 1) {
                $files[] = basename($path);
            }
        }

        return $files;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function englishFileProvider(): iterable
    {
        foreach (self::englishFiles() as $file) {
            yield $file => [$file];
        }
    }

    #[Test]
    public function languageDirectoryHoldsEnglishFiles(): void
    {
        self::assertNotSame([], self::englishFiles());
    }

    #[Test]
    public function everyGermanFileHasAnEnglishFile(): void
    {
        foreach (glob(XliffFile::LANGUAGE_DIRECTORY . '/de.*.xlf') ?: [] as $path) {
            self::assertFileExists(
                XliffFile::LANGUAGE_DIRECTORY . '/' . substr(basename($path), 3),
                basename($path) . ' has no English file',
            );
        }
    }

    #[Test]
    #[DataProvider('englishFileProvider')]
    public function germanFileDeclaresItsLanguages(string $file): void
    {
        $german = XliffFile::LANGUAGE_DIRECTORY . '/de.' . $file;

        self::assertFileExists($german);
        self::assertSame('en', XliffFile::fileAttribute($german, 'source-language'));
        self::assertSame('de', XliffFile::fileAttribute($german, 'target-language'));
        self::assertSame(
            XliffFile::fileAttribute(XliffFile::LANGUAGE_DIRECTORY . '/' . $file, 'product-name'),
            XliffFile::fileAttribute($german, 'product-name'),
        );
    }

    #[Test]
    #[DataProvider('englishFileProvider')]
    public function germanFileHasTheSameTransUnits(string $file): void
    {
        $englishIds = array_keys(XliffFile::units(XliffFile::LANGUAGE_DIRECTORY . '/' . $file));
        $germanIds  = array_keys(XliffFile::units(XliffFile::LANGUAGE_DIRECTORY . '/de.' . $file));

        self::assertSame([], array_values(array_diff($englishIds, $germanIds)), 'Missing in de.' . $file);
        self::assertSame([], array_values(array_diff($germanIds, $englishIds)), 'Only in de.' . $file);
    }

    #[Test]
    #[DataProvider('englishFileProvider')]
    public function germanUnitsTranslateTheCurrentEnglishText(string $file): void
    {
        $english = XliffFile::units(XliffFile::LANGUAGE_DIRECTORY . '/' . $file);

        foreach (XliffFile::units(XliffFile::LANGUAGE_DIRECTORY . '/de.' . $file) as $id => $unit) {
            self::assertNotSame('', trim($unit['target'] ?? ''), $id . ' has no German target');
            // A changed English text leaves the German translation stale.
            self::assertSame($english[$id]['source'] ?? null, $unit['source'], $id . ': source differs from the English file');
        }
    }

    #[Test]
    #[DataProvider('englishFileProvider')]
    public function germanTextsKeepThePlaceholders(string $file): void
    {
        $english = XliffFile::units(XliffFile::LANGUAGE_DIRECTORY . '/' . $file);

        foreach (XliffFile::units(XliffFile::LANGUAGE_DIRECTORY . '/de.' . $file) as $id => $unit) {
            self::assertSame(
                self::placeholders($english[$id]['source'] ?? ''),
                self::placeholders($unit['target'] ?? ''),
                $id . ': placeholders differ',
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $text): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $text, $matches);
        $placeholders = $matches[0];
        sort($placeholders);

        return $placeholders;
    }
}
