<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\FieldSuggestion;

use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Tca::class)]
final class TcaTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TCA'] = [
            'pages' => [
                'ctrl'    => ['languageField' => 'sys_language_uid', 'transOrigPointerField' => 'l10n_parent'],
                'columns' => ['title' => ['config' => ['type' => 'input']], 'broken' => 'not an array'],
            ],
            'no_language' => ['ctrl' => ['languageField' => '', 'transOrigPointerField' => ''], 'columns' => 'not an array'],
            'odd_ctrl'    => ['ctrl' => ['languageField' => 5]],
            'scalar'      => 'not an array',
        ];
    }

    #[Test]
    public function columnIsReadFromTheGlobalTca(): void
    {
        self::assertSame(['config' => ['type' => 'input']], Tca::column('pages', 'title'));
        self::assertNull(Tca::column('pages', 'missing'));
        self::assertNull(Tca::column('pages', 'broken'));
        self::assertNull(Tca::column('no_language', 'title'));
        self::assertNull(Tca::column('scalar', 'title'));
        self::assertNull(Tca::column('missing', 'title'));
    }

    #[Test]
    public function columnIsReadFromAGivenTca(): void
    {
        $tca = ['tx_other' => ['columns' => ['header' => ['label' => 'Header']]]];

        self::assertSame(['label' => 'Header'], Tca::column('tx_other', 'header', $tca));
        self::assertNull(Tca::column('pages', 'title', $tca));
    }

    #[Test]
    public function configIsAnArrayInAnyCase(): void
    {
        self::assertSame(['type' => 'input'], Tca::config(['config' => ['type' => 'input']]));
        self::assertSame([], Tca::config(['config' => 'input']));
        self::assertSame([], Tca::config([]));
    }

    #[Test]
    public function hasTableRequiresAnArray(): void
    {
        self::assertTrue(Tca::hasTable('pages'));
        self::assertFalse(Tca::hasTable('scalar'));
        self::assertFalse(Tca::hasTable('missing'));
    }

    #[Test]
    public function languageFieldMustBeANonEmptyString(): void
    {
        self::assertSame('sys_language_uid', Tca::languageField('pages'));
        self::assertNull(Tca::languageField('no_language'));
        self::assertNull(Tca::languageField('odd_ctrl'));
        self::assertNull(Tca::languageField('missing'));
    }

    #[Test]
    public function transOrigPointerFieldMustBeANonEmptyString(): void
    {
        self::assertSame('l10n_parent', Tca::transOrigPointerField('pages'));
        self::assertNull(Tca::transOrigPointerField('no_language'));
        self::assertNull(Tca::transOrigPointerField('odd_ctrl'));
    }

    #[Test]
    public function missingGlobalTcaIsAnEmptyTca(): void
    {
        unset($GLOBALS['TCA']);

        self::assertNull(Tca::column('pages', 'title'));
        self::assertFalse(Tca::hasTable('pages'));
    }
}
