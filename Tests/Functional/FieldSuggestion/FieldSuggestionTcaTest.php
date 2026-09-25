<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\FieldSuggestion;

use Netresearch\T3Cowriter\EventListener\RegisterFieldSuggestionControlsListener;
use Netresearch\T3Cowriter\Form\FieldControl\FieldSuggestionsControl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The compiled TCA of a TYPO3 without EXT:seo — the case where one of the
 * default fields (pages.seo_title) does not exist.
 */
#[CoversClass(RegisterFieldSuggestionControlsListener::class)]
final class FieldSuggestionTcaTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
    ];

    private static function control(string $table, string $field): mixed
    {
        return $GLOBALS['TCA'][$table]['columns'][$field]['config']['fieldControl'][RegisterFieldSuggestionControlsListener::CONTROL_NAME] ?? null;
    }

    #[Test]
    public function coreSeoFieldsAndTheSlugCarryTheControl(): void
    {
        $expected = ['renderType' => RegisterFieldSuggestionControlsListener::NODE_NAME, 'options' => ['count' => 3]];

        self::assertSame($expected, self::control('pages', 'description'));
        self::assertSame($expected, self::control('pages', 'keywords'));
        self::assertSame($expected, self::control('pages', 'slug'));
    }

    #[Test]
    public function seoTitleWithoutExtSeoGetsNoControl(): void
    {
        self::assertArrayNotHasKey('seo_title', $GLOBALS['TCA']['pages']['columns']);
    }

    #[Test]
    public function unconfiguredFieldsStayWithoutTheControl(): void
    {
        self::assertNull(self::control('pages', 'title'));
        self::assertNull(self::control('pages', 'abstract'));
        self::assertNull(self::control('pages', 'categories'));
    }

    #[Test]
    public function theFormEngineNodeIsRegistered(): void
    {
        $nodes = array_filter(
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'],
            static fn (array $node): bool => $node['nodeName'] === RegisterFieldSuggestionControlsListener::NODE_NAME,
        );

        self::assertCount(1, $nodes);
        self::assertSame(FieldSuggestionsControl::class, array_values($nodes)[0]['class']);
    }
}
