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
use PHPUnit\Framework\Attributes\DataProvider;
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
    private function coreTca(): array
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
    private function control(array $tca, string $table, string $field): mixed
    {
        return $tca[$table]['columns'][$field]['config']['fieldControl'][RegisterFieldSuggestionControlsListener::CONTROL_NAME] ?? null;
    }

    #[Test]
    public function defaultFieldsGetTheControlWhenTheyExist(): void
    {
        $event = new AfterTcaCompilationEvent($this->coreTca());

        ($this->subject())($event);
        $tca = $event->getTca();

        self::assertSame(self::EXPECTED_CONTROL, $this->control($tca, 'pages', 'description'));
        self::assertSame(self::EXPECTED_CONTROL, $this->control($tca, 'pages', 'keywords'));
        self::assertSame(self::EXPECTED_CONTROL, $this->control($tca, 'pages', 'slug'));
    }

    #[Test]
    public function missingSeoTitleWithoutExtSeoRegistersNothing(): void
    {
        $event = new AfterTcaCompilationEvent($this->coreTca());

        ($this->subject())($event);
        $tca = $event->getTca();

        self::assertArrayNotHasKey('seo_title', $tca['pages']['columns']);
        self::assertNull($this->control($tca, 'pages', 'seo_title'));
    }

    #[Test]
    public function seoTitleGetsTheControlWhenExtSeoProvidesIt(): void
    {
        $tca                                  = $this->coreTca();
        $tca['pages']['columns']['seo_title'] = ['exclude' => true, 'config' => ['type' => 'input', 'max' => 255]];
        $event                                = new AfterTcaCompilationEvent($tca);

        ($this->subject())($event);

        self::assertSame(self::EXPECTED_CONTROL, $this->control($event->getTca(), 'pages', 'seo_title'));
    }

    #[Test]
    public function otherColumnsAndTheirConfigurationStayUntouched(): void
    {
        $tca    = $this->coreTca();
        $result = $this->subject()->register($tca, [['pages', 'slug']], 3);

        self::assertSame($tca['pages']['columns']['title'], $result['pages']['columns']['title']);
        self::assertSame($tca['pages']['ctrl'], $result['pages']['ctrl']);
        self::assertSame(['fields' => ['title']], $result['pages']['columns']['slug']['config']['generatorOptions']);
        self::assertSame('slug', $result['pages']['columns']['slug']['config']['type']);
    }

    #[Test]
    public function otherTablesStayUntouched(): void
    {
        $tca               = $this->coreTca();
        $tca['tt_content'] = ['columns' => ['header' => ['config' => ['type' => 'input']]]];

        $result = $this->subject()->register($tca, [['pages', 'slug']], 3);

        self::assertSame($tca['tt_content'], $result['tt_content']);
    }

    #[Test]
    public function unknownTableOrFieldIsSkipped(): void
    {
        $tca = $this->coreTca();

        self::assertSame($tca, $this->subject()->register($tca, [['tx_missing', 'title'], ['pages', 'missing']], 3));
    }

    #[Test]
    public function unsupportedFieldTypesAreSkipped(): void
    {
        $tca = $this->coreTca();

        self::assertSame($tca, $this->subject()->register($tca, [['pages', 'categories']], 3));
    }

    #[Test]
    public function richTextFieldsAreSkipped(): void
    {
        $tca                                   = $this->coreTca();
        $tca['pages']['columns']['bodytext']   = ['config' => ['type' => 'text', 'enableRichtext' => true]];
        $tca['pages']['columns']['plain_text'] = ['config' => ['type' => 'text', 'enableRichtext' => false]];
        $tca['pages']['columns']['rte_int']    = ['config' => ['type' => 'text', 'enableRichtext' => 1]];

        $result = $this->subject()->register($tca, [['pages', 'bodytext'], ['pages', 'plain_text'], ['pages', 'rte_int']], 3);

        self::assertNull($this->control($result, 'pages', 'bodytext'));
        self::assertNull($this->control($result, 'pages', 'rte_int'));
        self::assertSame(self::EXPECTED_CONTROL, $this->control($result, 'pages', 'plain_text'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function nonProseColumnProvider(): iterable
    {
        yield 'code editor (pages.TSconfig)' => [['type' => 'text', 'renderType' => 'codeEditor']];
        yield 'table wizard' => [['type' => 'text', 'renderType' => 'textTable']];
        yield 'is_in (pages.cache_tags)' => [['type' => 'input', 'eval' => 'trim,is_in', 'is_in' => 'abc']];
        yield 'integer' => [['type' => 'input', 'eval' => 'int']];
        yield 'nospace identifier' => [['type' => 'input', 'eval' => 'nospace, trim']];
        yield 'value picker (pages.target)' => [['type' => 'input', 'eval' => 'trim', 'valuePicker' => ['items' => [['label' => 'New window', 'value' => '_blank']]]]];
        yield 'read only' => [['type' => 'input', 'readOnly' => true]];
        yield 'email type' => [['type' => 'email']];
        yield 'link type' => [['type' => 'link']];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('nonProseColumnProvider')]
    public function columnsThatDoNotHoldProseGetNoControl(array $config): void
    {
        $tca                                  = $this->coreTca();
        $tca['pages']['columns']['candidate'] = ['config' => $config];

        $result = $this->subject()->register($tca, [['pages', 'candidate']], 3);

        self::assertNull($this->control($result, 'pages', 'candidate'));
    }

    #[Test]
    public function proseColumnsWithHarmlessSettingsGetTheControl(): void
    {
        $tca                                 = $this->coreTca();
        $tca['pages']['columns']['trimmed']  = ['config' => ['type' => 'input', 'eval' => 'trim', 'max' => 255, 'required' => true]];
        $tca['pages']['columns']['rendered'] = ['config' => ['type' => 'text', 'renderType' => 'default']];
        $tca['pages']['columns']['not_ro']   = ['config' => ['type' => 'input', 'readOnly' => false]];

        $result = $this->subject()->register($tca, [['pages', 'trimmed'], ['pages', 'rendered'], ['pages', 'not_ro']], 3);

        self::assertSame(self::EXPECTED_CONTROL, $this->control($result, 'pages', 'trimmed'));
        self::assertSame(self::EXPECTED_CONTROL, $this->control($result, 'pages', 'rendered'));
        self::assertSame(self::EXPECTED_CONTROL, $this->control($result, 'pages', 'not_ro'));
    }

    #[Test]
    public function aFieldThatOnlyARecordTypeTurnsIntoRichTextGetsNoControl(): void
    {
        // tt_content.bodytext: plain text in the column, rich text for "text",
        // "textpic" and "textmedia" through columnsOverrides.
        $tca = ['tt_content' => [
            'columns' => [
                'header'   => ['config' => ['type' => 'input', 'max' => 255]],
                'bodytext' => ['config' => ['type' => 'text']],
            ],
            'types' => [
                'header' => ['showitem' => 'header'],
                'text'   => ['showitem' => 'header,bodytext', 'columnsOverrides' => ['bodytext' => ['config' => ['enableRichtext' => true]]]],
            ],
        ]];

        $result = $this->subject()->register($tca, [['tt_content', 'header'], ['tt_content', 'bodytext']], 3);

        self::assertSame(self::EXPECTED_CONTROL, $this->control($result, 'tt_content', 'header'));
        self::assertNull($this->control($result, 'tt_content', 'bodytext'));
    }

    #[Test]
    public function representativePageAndContentTcaGetsTheControlOnTheDefaultSetOnly(): void
    {
        // Column configurations as TYPO3 14.3 core and EXT:seo define them.
        $tca = [
            'pages' => [
                'ctrl'    => ['label' => 'title'],
                'columns' => [
                    'title'               => ['config' => ['type' => 'input', 'max' => 255, 'eval' => 'trim', 'required' => true]],
                    'slug'                => ['config' => ['type' => 'slug', 'eval' => 'uniqueInSite']],
                    'TSconfig'            => ['config' => ['type' => 'text', 'renderType' => 'codeEditor']],
                    'nav_title'           => ['exclude' => true, 'config' => ['type' => 'input', 'max' => 255, 'eval' => 'trim']],
                    'subtitle'            => ['exclude' => true, 'config' => ['type' => 'input', 'max' => 255, 'eval' => 'trim']],
                    'target'              => ['exclude' => true, 'config' => ['type' => 'input', 'max' => 80, 'eval' => 'trim', 'valuePicker' => ['items' => []]]],
                    'link'                => ['config' => ['type' => 'link']],
                    'cache_tags'          => ['exclude' => true, 'config' => ['type' => 'input', 'max' => 255, 'eval' => 'trim,is_in']],
                    'keywords'            => ['exclude' => true, 'config' => ['type' => 'text']],
                    'description'         => ['exclude' => true, 'config' => ['type' => 'text']],
                    'abstract'            => ['exclude' => true, 'config' => ['type' => 'text']],
                    'author'              => ['exclude' => true, 'config' => ['type' => 'input', 'max' => 255, 'eval' => 'trim']],
                    'author_email'        => ['exclude' => true, 'config' => ['type' => 'email']],
                    'seo_title'           => ['exclude' => true, 'config' => ['type' => 'input', 'max' => 255, 'eval' => 'trim']],
                    'canonical_link'      => ['exclude' => true, 'config' => ['type' => 'link']],
                    'og_title'            => ['exclude' => true, 'config' => ['type' => 'input', 'max' => 255, 'eval' => 'trim']],
                    'og_description'      => ['exclude' => true, 'config' => ['type' => 'text']],
                    'twitter_title'       => ['exclude' => true, 'config' => ['type' => 'input', 'max' => 255, 'eval' => 'trim']],
                    'twitter_description' => ['exclude' => true, 'config' => ['type' => 'text']],
                ],
            ],
            'tt_content' => [
                'columns' => [
                    'header'      => ['config' => ['type' => 'input', 'max' => 255]],
                    'header_link' => ['exclude' => true, 'config' => ['type' => 'link']],
                    'subheader'   => ['exclude' => true, 'config' => ['type' => 'input', 'max' => 255]],
                    'bodytext'    => ['config' => ['type' => 'text']],
                ],
                'types' => ['text' => ['columnsOverrides' => ['bodytext' => ['config' => ['enableRichtext' => true]]]]],
            ],
        ];
        $event = new AfterTcaCompilationEvent($tca);

        ($this->subject())($event);
        $result = $event->getTca();

        $withControl = [];
        foreach ($result as $table => $tableTca) {
            foreach ($tableTca['columns'] as $field => $column) {
                if (isset($column['config']['fieldControl'][RegisterFieldSuggestionControlsListener::CONTROL_NAME])) {
                    $withControl[] = $table . '.' . $field;
                }
            }
        }

        sort($withControl);
        $expected = explode(',', FieldSuggestionConfiguration::DEFAULT_FIELDS);
        sort($expected);

        self::assertSame($expected, $withControl);
    }

    #[Test]
    public function existingFieldControlsArePreserved(): void
    {
        $tca                                                              = $this->coreTca();
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
        $tca                                                           = $this->coreTca();
        $tca['pages']['columns']['keywords']['config']['fieldControl'] = [RegisterFieldSuggestionControlsListener::CONTROL_NAME => $own];

        $result = $this->subject()->register($tca, [['pages', 'keywords'], ['pages', 'description']], 3);

        self::assertSame($own, $this->control($result, 'pages', 'keywords'));
        // The fields after it are still registered.
        self::assertSame(self::EXPECTED_CONTROL, $this->control($result, 'pages', 'description'));
    }
}
