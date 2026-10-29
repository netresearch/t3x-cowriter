<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Vision;

use Netresearch\T3Cowriter\Service\Vision\RecordLanguageResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\Locale;

/**
 * The language name the model gets; the record lookup itself is covered by
 * the functional test of the same name.
 */
#[CoversClass(RecordLanguageResolver::class)]
final class RecordLanguageResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function locales(): iterable
    {
        yield 'language and region' => ['de_DE.UTF-8', 'German (Germany)'];
        yield 'language only' => ['de', 'German'];
        yield 'regional variant' => ['de-CH', 'German (Switzerland)'];
        yield 'script and region' => ['zh_Hant_TW', 'Chinese (Traditional, Taiwan)'];
        yield 'C locale' => ['C', null];
        yield 'POSIX locale' => ['POSIX', null];
        yield 'language intl lacks' => ['xx_YY', null];
    }

    #[Test]
    #[DataProvider('locales')]
    public function theLanguageIsNamedInEnglish(string $locale, ?string $expected): void
    {
        self::assertSame($expected, RecordLanguageResolver::nameOf(new Locale($locale)));
    }
}
