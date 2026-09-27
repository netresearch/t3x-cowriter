<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Prompt;

use Netresearch\T3Cowriter\Service\Prompt\PromptSharingConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(PromptSharingConfiguration::class)]
final class PromptSharingConfigurationTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function settings(): iterable
    {
        yield 'switched off' => ['0', false];
        yield 'switched on' => ['1', true];
        yield 'not a scalar' => [['x'], true];
    }

    #[Test]
    #[DataProvider('settings')]
    public function theSettingDecides(mixed $value, bool $expected): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->expects(self::once())->method('get')
            ->with('t3_cowriter', 'prompts/sharedNeedApproval')
            ->willReturn($value);

        self::assertSame($expected, (new PromptSharingConfiguration($extensionConfiguration))->sharedNeedApproval());
    }

    #[Test]
    public function anUnreadableSettingRequiresApproval(): void
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new ExtensionConfigurationExtensionNotConfiguredException('not configured', 1));

        self::assertTrue((new PromptSharingConfiguration($extensionConfiguration))->sharedNeedApproval());
    }
}
