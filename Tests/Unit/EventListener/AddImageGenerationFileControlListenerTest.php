<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\EventListener;

use DOMDocument;
use DOMElement;
use Netresearch\T3Cowriter\EventListener\AddImageGenerationFileControlListener;
use Netresearch\T3Cowriter\Service\Image\GeneratedImageStorageInterface;
use Netresearch\T3Cowriter\Service\Image\ImageGenerationConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Form\Event\CustomFileControlsEvent;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Resource\Folder;

#[CoversClass(AddImageGenerationFileControlListener::class)]
final class AddImageGenerationFileControlListenerTest extends TestCase
{
    private const FIELD_ID = 'data-12-tt_content-34-image';

    private const FIELD_NAME = 'data[tt_content][34][image]';

    private const IMAGE_CONFIG = [
        'type'       => 'file',
        'allowed'    => 'gif,jpg,jpeg,png,svg,webp',
        'maxitems'   => 5,
        'appearance' => ['createNewRelationLinkTitle' => 'Add image'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $languageService = $this->createStub(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn (string $reference): string => 'LBL:' . substr($reference, (int) strrpos($reference, ':') + 1),
        );
        $GLOBALS['LANG'] = $languageService;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function aFileFieldGetsTheControl(): void
    {
        $event = $this->dispatch(self::IMAGE_CONFIG);

        $button = $this->button($event);
        self::assertSame('button', $button->getAttribute('type'));
        self::assertStringContainsString(AddImageGenerationFileControlListener::CONTROL_CLASS, $button->getAttribute('class'));
        self::assertFalse($button->hasAttribute('hidden'));
        self::assertSame('LBL:fileControl.image.button', $button->getAttribute('title'));
        self::assertStringContainsString('LBL:fileControl.image.button', $button->textContent);
        self::assertStringContainsString('<span class="icon">actions-image</span>', $this->control($event));
    }

    #[Test]
    public function theControlNamesTheFieldTheFileIsHandedTo(): void
    {
        $button = $this->button($this->dispatch(self::IMAGE_CONFIG));

        self::assertSame(self::FIELD_ID . '-sys_file_reference', $button->getAttribute('data-file-irre-object'));
        self::assertSame(self::FIELD_ID, $button->getAttribute('data-form-field-id'));
        self::assertSame(self::FIELD_NAME, $button->getAttribute('data-form-field'));
        self::assertSame('5', $button->getAttribute('data-max-items'));
        self::assertSame('png', $button->getAttribute('data-allowed-extensions'));
    }

    #[Test]
    public function theControlNamesTheRecordTheImageIsFor(): void
    {
        $button = $this->button($this->dispatch(self::IMAGE_CONFIG, databaseRow: ['uid' => 34, 'pid' => 7]));

        self::assertSame(['tt_content', 'image', '34', '7'], [
            $button->getAttribute('data-table'),
            $button->getAttribute('data-field'),
            $button->getAttribute('data-uid'),
            $button->getAttribute('data-pid'),
        ]);
    }

    #[Test]
    public function aNewRecordHasNoUidYet(): void
    {
        $button = $this->button($this->dispatch(self::IMAGE_CONFIG, databaseRow: ['uid' => 'NEW5f1', 'pid' => '7']));

        self::assertSame(['0', '7'], [$button->getAttribute('data-uid'), $button->getAttribute('data-pid')]);
        self::assertSame('0', $this->button($this->dispatch(self::IMAGE_CONFIG, databaseRow: []))->getAttribute('data-pid'));
    }

    #[Test]
    public function theControlCarriesTheTextsOfTheModule(): void
    {
        $button = $this->button($this->dispatch(self::IMAGE_CONFIG));

        self::assertSame('LBL:fileControl.image.dialog.promptHelp', $button->getAttribute('data-label-prompt-help'));
        self::assertSame('LBL:fileControl.image.dialog.generating', $button->getAttribute('data-label-generating'));
        self::assertSame('LBL:fileControl.image.full.title', $button->getAttribute('data-label-full-title'));
        self::assertSame('LBL:fileControl.image.full.message', $button->getAttribute('data-label-full-message'));
        self::assertSame('LBL:fileControl.image.notAccepted', $button->getAttribute('data-label-not-accepted'));
        self::assertSame('LBL:fileControl.image.notAdded', $button->getAttribute('data-label-not-added'));
        self::assertSame('LBL:fileControl.image.done.title', $button->getAttribute('data-label-done-title'));
        self::assertSame('LBL:fileControl.image.done.message', $button->getAttribute('data-label-done-message'));
    }

    #[Test]
    public function theModuleIsLoaded(): void
    {
        $event = $this->dispatch(self::IMAGE_CONFIG, resultArray: ['javaScriptModules' => ['core-module']]);

        $modules = $event->getResultArray()['javaScriptModules'];
        self::assertCount(2, $modules);
        self::assertSame('core-module', $modules[0]);
        self::assertInstanceOf(JavaScriptModuleInstruction::class, $modules[1]);
        self::assertSame(AddImageGenerationFileControlListener::JAVASCRIPT_MODULE, $modules[1]->getName());
    }

    #[Test]
    public function nothingIsAddedWhileImageGenerationIsOff(): void
    {
        $storage = $this->createMock(GeneratedImageStorageInterface::class);
        $storage->expects(self::never())->method('targetFolder');

        $event = $this->dispatch(self::IMAGE_CONFIG, enable: '0', storage: $storage);

        self::assertSame([], $event->getControls());
        self::assertSame([], $event->getResultArray()['javaScriptModules']);
    }

    #[Test]
    public function nothingIsAddedWithoutAFolderTheRouteCouldStoreIn(): void
    {
        $event = $this->dispatch(self::IMAGE_CONFIG, folder: false);

        self::assertSame([], $event->getControls());
        self::assertSame([], $event->getResultArray()['javaScriptModules']);
    }

    #[Test]
    public function nothingIsAddedToAReadOnlyField(): void
    {
        $event = $this->dispatch(['readOnly' => true] + self::IMAGE_CONFIG);

        self::assertSame([], $event->getControls());
    }

    #[Test]
    public function nothingIsAddedWhereTheFieldHidesItsAddControls(): void
    {
        $event = $this->dispatch(['appearance' => ['showFileSelectors' => false]] + self::IMAGE_CONFIG);

        self::assertSame([], $event->getControls());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function uploadSwitchedOff(): iterable
    {
        yield 'false' => [false];
        yield '0 from TCA' => [0];
        yield "'0' from TSconfig" => ['0'];
        yield 'empty string' => [''];
    }

    #[Test]
    #[DataProvider('uploadSwitchedOff')]
    public function nothingIsAddedWhereTheFieldAllowsNoUpload(mixed $fileUploadAllowed): void
    {
        $event = $this->dispatch(['appearance' => ['fileUploadAllowed' => $fileUploadAllowed]] + self::IMAGE_CONFIG);

        self::assertSame([], $event->getControls());
    }

    #[Test]
    public function theControlIsAddedWhereUploadIsSwitchedOnExplicitly(): void
    {
        $event = $this->dispatch(['appearance' => ['fileUploadAllowed' => '1', 'showFileSelectors' => 0]] + self::IMAGE_CONFIG);

        // showFileSelectors is only off when it is false, as in core.
        self::assertCount(1, $event->getControls());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fieldsWithoutPng(): iterable
    {
        yield 'media without images' => ['pdf,mp4,youtube'];
        yield 'JPEG and WebP only' => ['jpg,jpeg,webp'];
    }

    #[Test]
    #[DataProvider('fieldsWithoutPng')]
    public function nothingIsAddedToAFieldThatDoesNotTakePng(string $allowed): void
    {
        $event = $this->dispatch(['allowed' => $allowed] + self::IMAGE_CONFIG);

        self::assertSame([], $event->getControls());
    }

    #[Test]
    public function aFullFieldGetsTheControlHidden(): void
    {
        $button = $this->button($this->dispatch(['inline' => ['showCreateNewRelationButton' => false]] + self::IMAGE_CONFIG));

        self::assertTrue($button->hasAttribute('hidden'));
    }

    /**
     * @return iterable<string, array{mixed, mixed, list<string>}>
     */
    public static function extensionLists(): iterable
    {
        yield 'no restriction' => [null, null, ['png']];
        yield 'empty list' => ['', '', ['png']];
        yield 'png only' => ['png', '', ['png']];
        yield 'upper case and blanks' => [' JPG , Png ', null, ['png']];
        yield 'png disallowed' => [null, 'png,webp', []];
        yield 'other type disallowed' => ['png,jpg,gif', 'jpg', ['png']];
        yield 'as array' => [['webp', 'png'], null, ['png']];
        yield 'JPEG only' => ['jpg,jpeg', null, []];
        yield 'unresolved placeholder' => ['common-image-types', null, []];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('extensionLists')]
    public function acceptedExtensionsFollowTheAllowedAndDisallowedLists(mixed $allowed, mixed $disallowed, array $expected): void
    {
        self::assertSame($expected, AddImageGenerationFileControlListener::acceptedExtensions($allowed, $disallowed));
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $resultArray
     */
    private function dispatch(
        array $config,
        string $enable = '1',
        bool $folder = true,
        ?GeneratedImageStorageInterface $storage = null,
        array $resultArray = ['javaScriptModules' => []],
        array $databaseRow = ['uid' => 34],
    ): CustomFileControlsEvent {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnMap([['t3_cowriter', 'image/enable', $enable]]);

        if (!$storage instanceof GeneratedImageStorageInterface) {
            $storage = $this->createStub(GeneratedImageStorageInterface::class);
            $storage->method('targetFolder')->willReturn($folder ? $this->createStub(Folder::class) : null);
        }

        $iconFactory = $this->createStub(IconFactory::class);
        $iconFactory->method('getIcon')->willReturnCallback(function (string $identifier): Icon {
            $icon = $this->createStub(Icon::class);
            $icon->method('render')->willReturn('<span class="icon">' . $identifier . '</span>');

            return $icon;
        });

        $event = new CustomFileControlsEvent(
            $resultArray,
            'tt_content',
            'image',
            $databaseRow,
            $config,
            self::FIELD_ID,
            self::FIELD_NAME,
        );

        (new AddImageGenerationFileControlListener(
            new ImageGenerationConfiguration($extensionConfiguration),
            $storage,
            $iconFactory,
        ))($event);

        return $event;
    }

    private function control(CustomFileControlsEvent $event): string
    {
        $controls = $event->getControls();
        self::assertCount(1, $controls);
        self::assertArrayHasKey('cowriterGenerateImage', $controls);

        return $controls['cowriterGenerateImage'];
    }

    private function button(CustomFileControlsEvent $event): DOMElement
    {
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $this->control($event), LIBXML_NOERROR);

        $button = $document->getElementsByTagName('button')->item(0);
        self::assertInstanceOf(DOMElement::class, $button);

        return $button;
    }
}
