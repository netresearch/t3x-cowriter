<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\InlineSuggestion;

use Netresearch\T3Cowriter\Service\InlineSuggestion\InlineSuggestionConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(InlineSuggestionConfiguration::class)]
final class InlineSuggestionConfigurationTest extends TestCase
{
    #[Test]
    public function isOffByDefault(): void
    {
        $subject = $this->subject([]);

        self::assertFalse($subject->isEnabled());
        self::assertSame(1200, $subject->delay());
        self::assertSame(30, $subject->minChars());
        self::assertNull($subject->configuration());
    }

    #[Test]
    public function readsTheSettings(): void
    {
        $subject = $this->subject(['enable' => '1', 'delay' => '1500', 'minChars' => '40', 'configuration' => ' fast ']);

        self::assertTrue($subject->isEnabled());
        self::assertSame(1500, $subject->delay());
        self::assertSame(40, $subject->minChars());
        self::assertSame('fast', $subject->configuration());
    }

    #[Test]
    public function acceptsAnIntegerFromTheSettings(): void
    {
        self::assertSame(2000, $this->subject(['delay' => 2000])->delay());
    }

    #[Test]
    #[DataProvider('clampedNumbers')]
    public function clampsNumbersToTheirRange(string $delay, int $expectedDelay, string $minChars, int $expectedMinChars): void
    {
        $subject = $this->subject(['delay' => $delay, 'minChars' => $minChars]);

        self::assertSame($expectedDelay, $subject->delay());
        self::assertSame($expectedMinChars, $subject->minChars());
    }

    /**
     * @return iterable<string, array{string, int, string, int}>
     */
    public static function clampedNumbers(): iterable
    {
        yield 'lowest' => ['800', 800, '10', 10];
        yield 'below' => ['799', 800, '9', 10];
        yield 'zero' => ['0', 800, '0', 10];
        yield 'highest' => ['3000', 3000, '500', 500];
        yield 'above' => ['3001', 3000, '501', 500];
        yield 'not a number' => ['fast', 1200, 'many', 30];
        yield 'negative' => ['-5', 1200, '-5', 30];
        yield 'with a unit' => ['1500ms', 1200, '40 chars', 30];
        yield 'surrounding spaces' => [' 1500 ', 1500, ' 40 ', 40];
    }

    #[Test]
    public function anUnreadableSettingIsOff(): void
    {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willThrowException(new RuntimeException('not configured'));
        $subject = new InlineSuggestionConfiguration($configuration);

        self::assertFalse($subject->isEnabled());
        self::assertSame(1200, $subject->delay());
        self::assertNull($subject->configuration());
    }

    #[Test]
    public function aNonScalarSettingIsIgnored(): void
    {
        $subject = $this->subject(['enable' => ['1'], 'configuration' => ['x']]);

        self::assertFalse($subject->isEnabled());
        self::assertNull($subject->configuration());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function subject(array $settings): InlineSuggestionConfiguration
    {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturnCallback(
            static function (string $extension, string $path = '') use ($settings): mixed {
                if ($extension !== 't3_cowriter' || !str_starts_with($path, 'inlineSuggestions/')) {
                    return null;
                }

                return $settings[substr($path, strlen('inlineSuggestions/'))] ?? null;
            },
        );

        return new InlineSuggestionConfiguration($configuration);
    }
}
