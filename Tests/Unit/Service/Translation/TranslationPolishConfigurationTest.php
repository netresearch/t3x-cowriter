<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Translation;

use Netresearch\T3Cowriter\Service\Translation\TranslationPolishConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(TranslationPolishConfiguration::class)]
final class TranslationPolishConfigurationTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function switchValues(): iterable
    {
        yield 'switched off' => ['0', false];
        yield 'switched on' => ['1', true];
        yield 'switched on as a boolean' => [true, true];
        yield 'switched on as an integer' => [1, true];
        yield 'empty' => ['', false];
        yield 'not configured' => [null, false];
        yield 'not a scalar' => [['1'], false];
    }

    #[Test]
    #[DataProvider('switchValues')]
    public function theSwitchIsOnOnlyForOne(mixed $value, bool $expected): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->expects(self::once())->method('get')
            ->with('t3_cowriter', 'translation/polish')
            ->willReturn($value);

        self::assertSame($expected, (new TranslationPolishConfiguration($extensionConfiguration))->isEnabled());
    }

    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function instructionValues(): iterable
    {
        yield 'an instruction' => ['Address the reader directly.', 'Address the reader directly.'];
        yield 'surrounding whitespace' => ["  Keep it short.\n", 'Keep it short.'];
        yield 'empty' => ['', null];
        yield 'only whitespace' => ['   ', null];
        yield 'not configured' => [null, null];
        yield 'not a scalar' => [['x'], null];
    }

    #[Test]
    #[DataProvider('instructionValues')]
    public function theInstructionIsTheTrimmedSettingOrNone(mixed $value, ?string $expected): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->expects(self::once())->method('get')
            ->with('t3_cowriter', 'translation/polishInstruction')
            ->willReturn($value);

        self::assertSame($expected, (new TranslationPolishConfiguration($extensionConfiguration))->instruction());
    }

    /**
     * An instance upgraded from a release without these settings has no value
     * stored until an administrator saves the settings; the revision stays off.
     */
    #[Test]
    public function anUnreadableSettingIsOffWithoutAnInstruction(): void
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new ExtensionConfigurationPathDoesNotExistException('missing', 1));

        $configuration = new TranslationPolishConfiguration($extensionConfiguration);

        self::assertFalse($configuration->isEnabled());
        self::assertNull($configuration->instruction());
    }
}
