<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\EventListener;

use Netresearch\T3Cowriter\EventListener\RegisterVariantControlListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\Event\AfterTcaCompilationEvent;

#[CoversClass(RegisterVariantControlListener::class)]
final class RegisterVariantControlListenerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function tca(): array
    {
        return [
            'tt_content' => [
                'columns' => ['bodytext' => ['config' => ['type' => 'text']]],
                'types'   => [
                    'text'      => ['showitem' => 'header,bodytext'],
                    'textpic'   => ['showitem' => 'header,bodytext', 'columnsOverrides' => ['bodytext' => ['config' => ['enableRichtext' => true]]]],
                    'textmedia' => [
                        'showitem'         => 'header,bodytext',
                        'columnsOverrides' => ['bodytext' => ['config' => ['fieldControl' => ['cowriterVariant' => ['renderType' => 'own', 'disabled' => true]]]]],
                    ],
                    'header' => ['showitem' => 'header'],
                ],
            ],
        ];
    }

    #[Test]
    public function theTextTypesGetTheControlInTheirOverrides(): void
    {
        $tca = (new RegisterVariantControlListener())->register($this->tca());

        $expected = ['renderType' => RegisterVariantControlListener::NODE_NAME];
        self::assertSame($expected, $tca['tt_content']['types']['text']['columnsOverrides']['bodytext']['config']['fieldControl']['cowriterVariant']);
        self::assertSame($expected, $tca['tt_content']['types']['textpic']['columnsOverrides']['bodytext']['config']['fieldControl']['cowriterVariant']);
        // An override of the type is kept.
        self::assertTrue($tca['tt_content']['types']['textpic']['columnsOverrides']['bodytext']['config']['enableRichtext']);
    }

    #[Test]
    public function anIntegratorsOwnControlAndOtherTypesStayUntouched(): void
    {
        $tca = (new RegisterVariantControlListener())->register($this->tca());

        self::assertSame(['renderType' => 'own', 'disabled' => true], $tca['tt_content']['types']['textmedia']['columnsOverrides']['bodytext']['config']['fieldControl']['cowriterVariant']);
        self::assertSame(['showitem' => 'header'], $tca['tt_content']['types']['header']);
        self::assertSame(['config' => ['type' => 'text']], $tca['tt_content']['columns']['bodytext'], 'the shared column carries no control');
    }

    #[Test]
    public function aTcaWithoutContentElementsIsReturnedAsItIs(): void
    {
        $tca = ['pages' => ['ctrl' => []]];

        self::assertSame($tca, (new RegisterVariantControlListener())->register($tca));
    }

    #[Test]
    public function theEventCarriesTheChangedTca(): void
    {
        $event = new AfterTcaCompilationEvent($this->tca());

        (new RegisterVariantControlListener())($event);

        self::assertArrayHasKey('cowriterVariant', $event->getTca()['tt_content']['types']['text']['columnsOverrides']['bodytext']['config']['fieldControl']);
    }
}
