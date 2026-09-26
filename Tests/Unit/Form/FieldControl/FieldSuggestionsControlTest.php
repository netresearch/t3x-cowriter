<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Form\FieldControl;

use Netresearch\T3Cowriter\Form\FieldControl\FieldSuggestionsControl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;

#[CoversClass(FieldSuggestionsControl::class)]
final class FieldSuggestionsControlTest extends TestCase
{
    protected function setUp(): void
    {
        $languageService = $this->createStub(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn (string $label): string => 'translated:' . substr($label, strrpos($label, '.') + 1),
        );
        $GLOBALS['LANG'] = $languageService;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(array $data, ?UriBuilder $uriBuilder = null): array
    {
        if ($uriBuilder === null) {
            $uriBuilder = $this->createStub(UriBuilder::class);
            $uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/typo3/ajax/cowriter/suggestions?token=abc'));
        }
        $subject = new FieldSuggestionsControl($uriBuilder);
        $subject->setData($data + [
            'tableName'      => 'pages',
            'fieldName'      => 'seo_title',
            'databaseRow'    => ['uid' => 12, 'pid' => 3],
            'effectivePid'   => 12,
            'parameterArray' => ['itemFormElName' => 'data[pages][12][seo_title]'],
            'renderData'     => ['fieldControlOptions' => ['count' => 3]],
        ]);

        return $subject->render();
    }

    #[Test]
    public function rendersAnAccessibleButtonWithTheFieldIdentity(): void
    {
        $result     = $this->render([]);
        $attributes = $result['linkAttributes'];

        self::assertSame('actions-lightbulb-on', $result['iconIdentifier']);
        self::assertSame('LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_be.xlf:fieldSuggestions.button', $result['title']);
        self::assertStringStartsWith('t3js-cowriter-suggestions-', $attributes['id']);
        self::assertSame('button', $attributes['role']);
        self::assertSame('false', $attributes['aria-expanded']);
        self::assertSame($attributes['id'] . '-panel', $attributes['aria-controls']);
        self::assertSame('/typo3/ajax/cowriter/suggestions?token=abc', $attributes['data-url']);
        self::assertSame('data[pages][12][seo_title]', $attributes['data-item-name']);
        self::assertSame('pages', $attributes['data-table']);
        self::assertSame('seo_title', $attributes['data-field']);
        self::assertSame('12', $attributes['data-uid']);
        self::assertSame('12', $attributes['data-pid']);
        self::assertSame('3', $attributes['data-count']);
    }

    #[Test]
    public function passesTranslatedLabelsForTheScript(): void
    {
        $attributes = $this->render([])['linkAttributes'];

        foreach (['heading', 'loading', 'loaded', 'empty', 'error', 'inserted', 'close'] as $label) {
            self::assertSame('translated:' . $label, $attributes['data-label-' . $label]);
        }
        self::assertSame('translated:loadedSingular', $attributes['data-label-loaded-singular']);
    }

    #[Test]
    public function loadsTheImportMappedModuleForThisButton(): void
    {
        $result = $this->render([]);

        self::assertCount(1, $result['javaScriptModules']);
        $instruction = $result['javaScriptModules'][0];
        self::assertInstanceOf(JavaScriptModuleInstruction::class, $instruction);
        self::assertSame('@netresearch/t3_cowriter/FieldSuggestions', $instruction->getName());
        self::assertSame(
            [['type' => JavaScriptModuleInstruction::ITEM_INSTANCE, 'args' => [$result['linkAttributes']['id']]]],
            $instruction->getItems(),
        );
    }

    #[Test]
    public function newRecordIsSentWithUidZero(): void
    {
        $attributes = $this->render(['databaseRow' => ['uid' => 'NEW64f0a1', 'pid' => 3], 'effectivePid' => 3])['linkAttributes'];

        self::assertSame('0', $attributes['data-uid']);
        self::assertSame('3', $attributes['data-pid']);
    }

    #[Test]
    public function missingUidAndPidAreSentAsZero(): void
    {
        $attributes = $this->render(['databaseRow' => [], 'effectivePid' => null])['linkAttributes'];

        self::assertSame('0', $attributes['data-uid']);
        self::assertSame('0', $attributes['data-pid']);
    }

    #[Test]
    public function numericStringsAreNormalisedToIntegers(): void
    {
        $attributes = $this->render([
            'databaseRow'  => ['uid' => '12.0'],
            'effectivePid' => '3.0',
            'renderData'   => ['fieldControlOptions' => ['count' => '4.0']],
        ])['linkAttributes'];

        self::assertSame('12', $attributes['data-uid']);
        self::assertSame('3', $attributes['data-pid']);
        self::assertSame('4', $attributes['data-count']);
    }

    #[Test]
    public function nonScalarIdentityValuesBecomeEmpty(): void
    {
        $attributes = $this->render(['tableName' => ['pages'], 'parameterArray' => ['itemFormElName' => 5]])['linkAttributes'];

        self::assertSame('', $attributes['data-table']);
        self::assertSame('5', $attributes['data-item-name']);
    }

    #[Test]
    public function countOptionIsClampedToOneToFive(): void
    {
        self::assertSame('5', $this->render(['renderData' => ['fieldControlOptions' => ['count' => 9]]])['linkAttributes']['data-count']);
        self::assertSame('1', $this->render(['renderData' => ['fieldControlOptions' => ['count' => 0]]])['linkAttributes']['data-count']);
        self::assertSame('3', $this->render(['renderData' => []])['linkAttributes']['data-count']);
    }

    #[Test]
    public function missingRouteRendersNoButton(): void
    {
        $uriBuilder = $this->createStub(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willThrowException(new RouteNotFoundException('missing', 1));

        self::assertSame([], $this->render([], $uriBuilder));
    }
}
