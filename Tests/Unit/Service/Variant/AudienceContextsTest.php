<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Variant;

use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use Netresearch\T3Cowriter\Service\Variant\AudienceContexts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The settings sent to the DataHandler hook of EXT:contexts, from the TCA it
 * registers: two flat settings here, to prove every flat setting is sent for
 * every context (the hook reads each one) and rebuilt from the record.
 */
#[CoversClass(AudienceContexts::class)]
#[CoversClass(Tca::class)]
final class AudienceContextsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TCA'] = [
            'tt_content' => [
                'ctrl' => ['tx_contexts' => ['flatSettings' => [
                    'tx_contexts'     => ['tx_contexts_disable', 'tx_contexts_enable'],
                    'menu_visibility' => ['menu_disable', 'menu_enable'],
                ]]],
                'columns' => ['tx_contexts_settings' => ['exclude' => 1, 'config' => ['type' => 'user']]],
            ],
            'tx_contexts_contexts' => ['ctrl' => [], 'columns' => []],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
        parent::tearDown();
    }

    private function subject(): AudienceContexts
    {
        return new AudienceContexts($this->createStub(ConnectionPool::class));
    }

    #[Test]
    public function theVariantGetsOneContextAndEveryOtherFlatSettingEmpty(): void
    {
        self::assertSame([5 => ['tx_contexts' => '1', 'menu_visibility' => '']], $this->subject()->onlyIn(5));
    }

    #[Test]
    public function hidingTheOriginalCarriesEveryContextItHasASettingFor(): void
    {
        $row = ['tx_contexts_enable' => '6', 'tx_contexts_disable' => '7, 5', 'menu_enable' => '', 'menu_disable' => '8'];

        self::assertSame(
            [
                7 => ['tx_contexts' => '0', 'menu_visibility' => ''],
                5 => ['tx_contexts' => '0', 'menu_visibility' => ''],
                6 => ['tx_contexts' => '1', 'menu_visibility' => ''],
                8 => ['tx_contexts' => '', 'menu_visibility' => '0'],
            ],
            $this->subject()->hiddenIn($row, 5),
        );
    }

    #[Test]
    public function aContextTheOriginalWasEnabledInIsTurnedToHidden(): void
    {
        $settings = $this->subject()->hiddenIn(['tx_contexts_enable' => '5', 'tx_contexts_disable' => null], 5);

        self::assertSame([5 => ['tx_contexts' => '0', 'menu_visibility' => '']], $settings);
    }

    #[Test]
    public function theFlatColumnsTellWhereARecordIsShownAndHidden(): void
    {
        $row = ['tx_contexts_enable' => '5,12', 'tx_contexts_disable' => '7'];

        self::assertTrue($this->subject()->isEnabledIn($row, 12));
        self::assertFalse($this->subject()->isEnabledIn($row, 1));
        self::assertTrue($this->subject()->isDisabledIn($row, 7));
        self::assertFalse($this->subject()->isDisabledIn($row, 5));
    }

    #[Test]
    public function theSettingsFieldIsAnExcludedField(): void
    {
        $editor            = $this->createStub(BackendUserAuthentication::class);
        $editor->workspace = 0;
        $editor->method('check')->willReturnCallback(static fn (string $type, string $value): bool => $type === 'tables_modify');
        self::assertTrue($this->subject()->isInstalled());
        self::assertFalse($this->subject()->mayAssign($editor));

        $allowed            = $this->createStub(BackendUserAuthentication::class);
        $allowed->workspace = 0;
        $allowed->method('check')->willReturn(true);
        self::assertTrue($this->subject()->mayAssign($allowed));

        $allowed->workspace = 1;
        self::assertFalse($this->subject()->mayAssign($allowed), 'never in a workspace');
    }

    #[Test]
    public function withoutTheVisibilitySettingContextsCountsAsNotInstalled(): void
    {
        unset($GLOBALS['TCA']['tt_content']['ctrl']['tx_contexts']['flatSettings']['tx_contexts']);

        self::assertFalse($this->subject()->isInstalled());
        self::assertSame([], $this->subject()->available());
        self::assertFalse($this->subject()->isEnabledIn(['tx_contexts_enable' => '5'], 5));
    }
}
