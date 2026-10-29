<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\EventListener;

use Netresearch\T3Cowriter\EventListener\RegisterPresentationControlListener;
use Netresearch\T3Cowriter\Service\Presentation\Layout;
use Netresearch\T3Cowriter\Service\Presentation\PresentationAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\Event\AfterTcaCompilationEvent;

#[CoversClass(RegisterPresentationControlListener::class)]
#[CoversClass(PresentationAccess::class)]
#[CoversClass(Layout::class)]
final class RegisterPresentationControlListenerTest extends TestCase
{
    private const CONTROL = ['renderType' => RegisterPresentationControlListener::NODE_NAME];

    /**
     * tt_content with the text types, a header type and — unless $withTabs is
     * false — bootstrap_package's tab element and its item table.
     *
     * @return array<string, mixed>
     */
    public static function tca(bool $withTabs = true, bool $withAccordion = false): array
    {
        $items   = [['label' => 'Text', 'value' => 'text'], ['label' => 'Header', 'value' => 'header']];
        $columns = [
            'bodytext' => ['config' => ['type' => 'text']],
        ];
        $tca = [];
        foreach (['tab' => $withTabs, 'accordion' => $withAccordion] as $ctype => $present) {
            if (!$present) {
                continue;
            }

            $items[]                                            = ['label' => $ctype, 'value' => $ctype];
            $columns['tx_bootstrappackage_' . $ctype . '_item'] = ['config' => [
                'type'          => 'inline',
                'foreign_table' => 'tx_bootstrappackage_' . $ctype . '_item',
                'foreign_field' => 'tt_content',
            ]];
            $tca['tx_bootstrappackage_' . $ctype . '_item'] = ['columns' => [
                'header'   => ['config' => ['type' => 'input']],
                'bodytext' => ['config' => ['type' => 'text', 'enableRichtext' => true]],
            ]];
        }

        $columns['CType'] = ['config' => ['type' => 'select', 'items' => $items]];

        return $tca + ['tt_content' => [
            'columns' => $columns,
            'types'   => [
                'text'      => ['showitem' => 'bodytext', 'columnsOverrides' => ['bodytext' => ['config' => ['enableRichtext' => true]]]],
                'textmedia' => ['showitem' => 'bodytext'],
                'header'    => ['showitem' => 'header'],
            ],
        ]];
    }

    /**
     * @param array<array-key, mixed> $tca
     */
    private function control(array $tca, string $type): mixed
    {
        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        return $tca['tt_content']['types'][$type]['columnsOverrides']['bodytext']['config']['fieldControl'][RegisterPresentationControlListener::CONTROL_NAME] ?? null;
    }

    #[Test]
    public function theTextTypesGetTheControlInTheirColumnsOverrides(): void
    {
        $tca = (new RegisterPresentationControlListener())->register(self::tca());

        self::assertSame(self::CONTROL, $this->control($tca, 'text'));
        self::assertSame(self::CONTROL, $this->control($tca, 'textmedia'));
        // The existing override stays.
        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        self::assertTrue($tca['tt_content']['types']['text']['columnsOverrides']['bodytext']['config']['enableRichtext']);
    }

    #[Test]
    public function otherTypesAndTheSharedColumnStayWithoutIt(): void
    {
        $tca = (new RegisterPresentationControlListener())->register(self::tca());

        self::assertNull($this->control($tca, 'header'));
        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        self::assertArrayNotHasKey('fieldControl', $tca['tt_content']['columns']['bodytext']['config']);
        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        self::assertArrayNotHasKey('textpic', $tca['tt_content']['types'], 'a type that does not exist is not created');
    }

    #[Test]
    public function withoutTabOrAccordionElementNothingIsRegistered(): void
    {
        $tca = self::tca(false) + ['pages' => ['columns' => []]];

        self::assertSame($tca, (new RegisterPresentationControlListener())->register($tca));
    }

    #[Test]
    public function theAccordionAloneIsEnough(): void
    {
        $tca = (new RegisterPresentationControlListener())->register(self::tca(false, true));

        self::assertSame(self::CONTROL, $this->control($tca, 'text'));
    }

    #[Test]
    public function aControlAnIntegratorConfiguredStaysUntouched(): void
    {
        $tca = self::tca();
        // @phpstan-ignore offsetAccess.nonOffsetAccessible
        $tca['tt_content']['types']['text']['columnsOverrides']['bodytext']['config']['fieldControl'][RegisterPresentationControlListener::CONTROL_NAME] = ['disabled' => true];

        $result = (new RegisterPresentationControlListener())->register($tca);

        self::assertSame(['disabled' => true], $this->control($result, 'text'));
        self::assertSame(self::CONTROL, $this->control($result, 'textmedia'), 'the next type still gets it');
    }

    #[Test]
    public function theEventGetsTheChangedTca(): void
    {
        $event = new AfterTcaCompilationEvent(self::tca());

        (new RegisterPresentationControlListener())($event);

        self::assertSame(self::CONTROL, $this->control($event->getTca(), 'text'));
    }
}
