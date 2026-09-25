<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\FieldSuggestion;

use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(FieldSuggestionConfiguration::class)]
final class FieldSuggestionConfigurationTest extends TestCase
{
    /**
     * @param array<string, mixed> $settings keyed by the path below t3_cowriter
     */
    private function subject(array $settings): FieldSuggestionConfiguration
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnCallback(
            static function (string $extension, string $path) use ($settings): mixed {
                self::assertSame('t3_cowriter', $extension);
                if (!array_key_exists($path, $settings)) {
                    throw new RuntimeException('Path ' . $path . ' does not exist in extension configuration', 1509977699);
                }

                return $settings[$path];
            },
        );

        return new FieldSuggestionConfiguration($extensionConfiguration);
    }

    #[Test]
    public function defaultFieldsApplyWhenNothingIsConfigured(): void
    {
        self::assertSame(
            [['pages', 'seo_title'], ['pages', 'description'], ['pages', 'keywords'], ['pages', 'slug']],
            $this->subject([])->fields(),
        );
    }

    #[Test]
    public function configuredFieldsAreParsed(): void
    {
        $fields = $this->subject(['fieldSuggestions/fields' => ' pages.nav_title , tt_content.header,broken,a.b.c,.x,y.'])->fields();

        self::assertSame([['pages', 'nav_title'], ['tt_content', 'header']], $fields);
    }

    #[Test]
    public function emptySettingSwitchesTheButtonOff(): void
    {
        self::assertSame([], $this->subject(['fieldSuggestions/fields' => ''])->fields());
    }

    #[Test]
    public function nonScalarSettingFallsBackToTheDefault(): void
    {
        self::assertCount(4, $this->subject(['fieldSuggestions/fields' => ['x']])->fields());
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function countProvider(): iterable
    {
        yield 'configured value' => ['4', 4];
        yield 'integer value' => [2, 2];
        yield 'surrounding spaces' => [' 5 ', 5];
        yield 'above maximum' => ['9', 5];
        yield 'zero' => ['0', 1];
        yield 'not a number' => ['three', 3];
        yield 'negative' => ['-2', 3];
    }

    #[Test]
    #[DataProvider('countProvider')]
    public function countIsReadAndClamped(mixed $setting, int $expected): void
    {
        self::assertSame($expected, $this->subject(['fieldSuggestions/count' => $setting])->count());
    }

    #[Test]
    public function countDefaultsToThree(): void
    {
        self::assertSame(3, $this->subject([])->count());
    }
}
