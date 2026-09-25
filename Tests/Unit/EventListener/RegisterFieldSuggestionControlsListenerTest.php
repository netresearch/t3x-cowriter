<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\EventListener;

use Netresearch\T3Cowriter\EventListener\RegisterFieldSuggestionControlsListener;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionConfiguration;
use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\Event\AfterTcaCompilationEvent;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(RegisterFieldSuggestionControlsListener::class)]
#[CoversClass(FieldSuggestionConfiguration::class)]
#[CoversClass(Tca::class)]
final class RegisterFieldSuggestionControlsListenerTest extends TestCase
{
    private const EXPECTED_CONTROL = [
        'renderType' => RegisterFieldSuggestionControlsListener::NODE_NAME,
        'options'    => ['count' => 3],
    ];

    /**
     * pages as TYPO3 core defines it, without EXT:seo.
     *
     * @return array<string, mixed>
     */
    private static function coreTca(): array
    {
        return [
            'pages' => [
                'ctrl'    => ['title' => 'pages'],
                'columns' => [
                    'title'       => ['config' => ['type' => 'input', 'max' => 255]],
                    'slug'        => ['config' => ['type' => 'slug', 'generatorOptions' => ['fields' => ['title']]]],
                    'keywords'    => ['exclude' => true, 'config' => ['type' => 'text']],
                    'description' => ['exclude' => true, 'config' => ['type' => 'text']],
                    'categories'  => ['config' => ['type' => 'category']],
                ],
            ],
        ];
    }

    private function subject(): RegisterFieldSuggestionControlsListener
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new RuntimeException('not configured'));

        return new RegisterFieldSuggestionControlsListener(new FieldSuggestionConfiguration($extensionConfiguration));
    }

    /**
     * @param array<array-key, mixed> $tca
     */
    private static function control(array $tca, string $table, string $field): mixed
    {
        return $tca[$table]['columns'][$field]['config']['fieldControl'][RegisterFieldSuggestionControlsListener::CONTROL_NAME] ?? null;
    }

    #[Test]
    public function defaultFieldsGetTheControlWhenTheyExist(): void
    {
        $event = new AfterTcaCompilationEvent(self::coreTca());

        ($this->subject())($event);
        $tca = $event->getTca();

        self::assertSame(self::EXPECTED_CONTROL, self::control($tca, 'pages', 'description'));
        self::assertSame(self::EXPECTED_CONTROL, self::control($tca, 'pages', 'keywords'));
        self::assertSame(self::EXPECTED_CONTROL, self::control($tca, 'pages', 'slug'));
    }

    #[Test]
    public function missingSeoTitleWithoutExtSeoRegistersNothing(): void
    {
        $event = new AfterTcaCompilationEvent(self::coreTca());

        ($this->subject())($event);
        $tca = $event->getTca();

        self::assertArrayNotHasKey('seo_title', $tca['pages']['columns']);
        self::assertNull(self::control($tca, 'pages', 'seo_title'));
    }

    #[Test]
    public function seoTitleGetsTheControlWhenExtSeoProvidesIt(): void
    {
        $tca                                  = self::coreTca();
        $tca['pages']['columns']['seo_title'] = ['exclude' => true, 'config' => ['type' => 'input', 'max' => 255]];
        $event                                = new AfterTcaCompilationEvent($tca);

        ($this->subject())($event);

        self::assertSame(self::EXPECTED_CONTROL, self::control($event->getTca(), 'pages', 'seo_title'));
    }

    #[Test]
    public function otherColumnsAndTheirConfigurationStayUntouched(): void
    {
        $tca    = self::coreTca();
        $result = $this->subject()->register($tca, [['pages', 'slug']], 3);

        self::assertSame($tca['pages']['columns']['title'], $result['pages']['columns']['title']);
        self::assertSame($tca['pages']['ctrl'], $result['pages']['ctrl']);
        self::assertSame(['fields' => ['title']], $result['pages']['columns']['slug']['config']['generatorOptions']);
        self::assertSame('slug', $result['pages']['columns']['slug']['config']['type']);
    }

    #[Test]
    public function otherTablesStayUntouched(): void
    {
        $tca               = self::coreTca();
        $tca['tt_content'] = ['columns' => ['header' => ['config' => ['type' => 'input']]]];

        $result = $this->subject()->register($tca, [['pages', 'slug']], 3);

        self::assertSame($tca['tt_content'], $result['tt_content']);
    }

    #[Test]
    public function unknownTableOrFieldIsSkipped(): void
    {
        $tca = self::coreTca();

        self::assertSame($tca, $this->subject()->register($tca, [['tx_missing', 'title'], ['pages', 'missing']], 3));
    }

    #[Test]
    public function unsupportedFieldTypesAreSkipped(): void
    {
        $tca = self::coreTca();

        self::assertSame($tca, $this->subject()->register($tca, [['pages', 'categories']], 3));
    }

    #[Test]
    public function richTextFieldsAreSkipped(): void
    {
        $tca                                   = self::coreTca();
        $tca['pages']['columns']['bodytext']   = ['config' => ['type' => 'text', 'enableRichtext' => true]];
        $tca['pages']['columns']['plain_text'] = ['config' => ['type' => 'text', 'enableRichtext' => false]];
        $tca['pages']['columns']['rte_int']    = ['config' => ['type' => 'text', 'enableRichtext' => 1]];

        $result = $this->subject()->register($tca, [['pages', 'bodytext'], ['pages', 'plain_text'], ['pages', 'rte_int']], 3);

        self::assertNull(self::control($result, 'pages', 'bodytext'));
        self::assertNull(self::control($result, 'pages', 'rte_int'));
        self::assertSame(self::EXPECTED_CONTROL, self::control($result, 'pages', 'plain_text'));
    }

    #[Test]
    public function existingFieldControlsArePreserved(): void
    {
        $tca                                                              = self::coreTca();
        $tca['pages']['columns']['description']['config']['fieldControl'] = ['editPopup' => ['disabled' => false]];

        $result = $this->subject()->register($tca, [['pages', 'description']], 5);

        self::assertSame(
            [
                'editPopup'                                           => ['disabled' => false],
                RegisterFieldSuggestionControlsListener::CONTROL_NAME => [
                    'renderType' => RegisterFieldSuggestionControlsListener::NODE_NAME,
                    'options'    => ['count' => 5],
                ],
            ],
            $result['pages']['columns']['description']['config']['fieldControl'],
        );
    }

    #[Test]
    public function aControlConfiguredByTheIntegratorIsNotOverwritten(): void
    {
        $own                                                           = ['renderType' => RegisterFieldSuggestionControlsListener::NODE_NAME, 'disabled' => true];
        $tca                                                           = self::coreTca();
        $tca['pages']['columns']['keywords']['config']['fieldControl'] = [RegisterFieldSuggestionControlsListener::CONTROL_NAME => $own];

        $result = $this->subject()->register($tca, [['pages', 'keywords'], ['pages', 'description']], 3);

        self::assertSame($own, self::control($result, 'pages', 'keywords'));
        // The fields after it are still registered.
        self::assertSame(self::EXPECTED_CONTROL, self::control($result, 'pages', 'description'));
    }
}
