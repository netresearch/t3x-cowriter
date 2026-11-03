<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Overview;

use Netresearch\T3Cowriter\Service\Overview\ExtensionSetting;
use Netresearch\T3Cowriter\Service\Overview\ExtensionSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(ExtensionSettings::class)]
#[CoversClass(ExtensionSetting::class)]
final class ExtensionSettingsTest extends TestCase
{
    /**
     * Every key of the real template is listed, in its order.
     */
    #[Test]
    public function everyKeyOfTheTemplateIsListed(): void
    {
        preg_match_all('/^([A-Za-z0-9_.]+)\s*=/m', (string) file_get_contents(ExtensionSettings::TEMPLATE), $keys);

        self::assertNotSame([], $keys[1]);
        self::assertSame($keys[1], array_map(static fn (ExtensionSetting $setting): string => $setting->key, $this->settings([])->all()));
    }

    #[Test]
    public function aValueEqualToTheTemplatesDefaultIsTheDefault(): void
    {
        $setting = $this->find($this->settings(['fieldSuggestions/count' => '3']), 'fieldSuggestions.count');

        self::assertSame('3', $setting->default);
        self::assertSame('int+', $setting->type);
        self::assertTrue($setting->isUnchanged());
    }

    #[Test]
    public function aChangedValueIsReportedWithItsValue(): void
    {
        $setting = $this->find($this->settings(['image/enable' => '1']), 'image.enable');

        self::assertSame('1', $setting->value);
        self::assertSame('0', $setting->default);
        self::assertFalse($setting->isUnchanged());
        self::assertTrue($setting->isBoolean());
        self::assertTrue($setting->isOn());
    }

    #[Test]
    public function anUnreadableValueCountsAsTheDefault(): void
    {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willThrowException(new RuntimeException('not configured'));

        $setting = $this->find(new ExtensionSettings($configuration), 'fieldSuggestions.fields');

        self::assertSame('default', $setting->value);
        self::assertTrue($setting->isUnchanged());
        self::assertFalse($setting->isBoolean());
    }

    /**
     * @param array<string, string> $values
     */
    private function settings(array $values): ExtensionSettings
    {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturnCallback(static fn (string $extension, string $path = ''): mixed => $values[$path] ?? null);

        return new ExtensionSettings($configuration);
    }

    private function find(ExtensionSettings $settings, string $key): ExtensionSetting
    {
        foreach ($settings->all() as $setting) {
            if ($setting->key === $key) {
                return $setting;
            }
        }

        self::fail($key . ' is not listed');
    }
}
