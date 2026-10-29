<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Form\FieldControl;

use Netresearch\T3Cowriter\Form\FieldControl\PresentationControl;
use Netresearch\T3Cowriter\Service\Presentation\Layout;
use Netresearch\T3Cowriter\Service\Presentation\PresentationConfiguration;
use Netresearch\T3Cowriter\Service\Presentation\PresentationElement;
use Netresearch\T3Cowriter\Service\Presentation\PresentationElementReader;
use Netresearch\T3Cowriter\Service\Presentation\PresentationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;

#[CoversClass(PresentationControl::class)]
#[CoversClass(PresentationConfiguration::class)]
final class PresentationControlTest extends TestCase
{
    private bool $enabled = true;

    protected function setUp(): void
    {
        $languageService = $this->createStub(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn (string $label): string => 'translated:' . substr($label, strrpos($label, '.') + 1),
        );
        $GLOBALS['LANG']    = $languageService;
        $GLOBALS['BE_USER'] = $this->createStub(BackendUserAuthentication::class);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function render(array $data = [], ?PresentationElementReader $reader = null, ?UriBuilder $uriBuilder = null): array
    {
        if (!$reader instanceof PresentationElementReader) {
            $reader = $this->createStub(PresentationElementReader::class);
            $reader->method('read')->willReturn(new PresentationElement(12, 3, 0, 0, 'Header', '<p>a</p>', [Layout::Accordion, Layout::Tabs]));
        }

        if (!$uriBuilder instanceof UriBuilder) {
            $uriBuilder = $this->createStub(UriBuilder::class);
            $uriBuilder->method('buildUriFromRoute')->willReturnCallback(
                static fn (string $route): Uri => new Uri('/typo3/ajax/' . $route . '?token=abc'),
            );
        }

        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($this->enabled ? '1' : '0');

        $subject = new PresentationControl($uriBuilder, new PresentationConfiguration($extensionConfiguration), $reader);
        $subject->setData($data + [
            'tableName'   => 'tt_content',
            'fieldName'   => 'bodytext',
            'databaseRow' => ['uid' => 12, 'pid' => 3],
        ]);

        return $subject->render();
    }

    #[Test]
    public function rendersAnAccessibleButtonForTheElement(): void
    {
        $result     = $this->render();
        $attributes = $result['linkAttributes'];
        self::assertIsArray($attributes);

        self::assertSame('content-tab', $result['iconIdentifier']);
        self::assertSame('LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_be.xlf:presentation.button', $result['title']);
        self::assertIsString($attributes['id']);
        self::assertStringStartsWith('t3js-cowriter-presentation-', $attributes['id']);
        self::assertSame('button', $attributes['role']);
        self::assertSame('false', $attributes['aria-expanded']);
        self::assertSame($attributes['id'] . '-panel', $attributes['aria-controls']);
        self::assertSame('/typo3/ajax/ajax_tx_cowriter_presentation_propose?token=abc', $attributes['data-propose-url']);
        self::assertSame('/typo3/ajax/ajax_tx_cowriter_presentation_create?token=abc', $attributes['data-create-url']);
        self::assertSame('12', $attributes['data-uid']);
        self::assertSame('accordion,tab', $attributes['data-layouts']);

        $modules = $result['javaScriptModules'];
        self::assertIsArray($modules);
        self::assertInstanceOf(JavaScriptModuleInstruction::class, $modules[0]);
        self::assertSame(PresentationControl::JAVASCRIPT_MODULE, $modules[0]->getName());
    }

    #[Test]
    public function passesTranslatedLabelsForTheScript(): void
    {
        $attributes = $this->render()['linkAttributes'];
        self::assertIsArray($attributes);

        self::assertSame('translated:heading', $attributes['data-label-heading']);
        self::assertSame('translated:layoutTab', $attributes['data-label-layout-tab']);
        self::assertSame('translated:proposedHeadings', $attributes['data-label-proposed-headings']);
        self::assertSame('translated:titleRequired', $attributes['data-label-title-required']);
        self::assertSame('translated:original', $attributes['data-label-original']);
    }

    #[Test]
    public function rendersNothingWhileSwitchedOff(): void
    {
        $this->enabled = false;
        $reader        = $this->createMock(PresentationElementReader::class);
        $reader->expects(self::never())->method('read');

        self::assertSame([], $this->render([], $reader));
    }

    #[Test]
    public function rendersNothingForANewRecord(): void
    {
        $reader = $this->createMock(PresentationElementReader::class);
        $reader->expects(self::never())->method('read');

        self::assertSame([], $this->render(['databaseRow' => ['uid' => 'NEW12345', 'pid' => 3]], $reader));
    }

    #[Test]
    public function rendersNothingWithoutBackendUser(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertSame([], $this->render());
    }

    #[Test]
    public function rendersNothingWhenTheEditorMayNotPresentTheElement(): void
    {
        $reader = $this->createStub(PresentationElementReader::class);
        $reader->method('read')->willThrowException(PresentationException::notAvailable());

        self::assertSame([], $this->render([], $reader));
    }

    #[Test]
    public function rendersNothingWithoutTheRoutes(): void
    {
        $uriBuilder = $this->createStub(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willThrowException(new RuntimeException('no route'));

        self::assertSame([], $this->render([], null, $uriBuilder));
    }

    #[Test]
    public function anUnreadableSettingIsOff(): void
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new RuntimeException('not configured'));

        self::assertFalse((new PresentationConfiguration($extensionConfiguration))->isEnabled());

        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['nested']);
        self::assertFalse((new PresentationConfiguration($extensionConfiguration))->isEnabled());

        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(1);
        self::assertTrue((new PresentationConfiguration($extensionConfiguration))->isEnabled());
    }
}
