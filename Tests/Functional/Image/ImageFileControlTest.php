<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Image;

use Netresearch\T3Cowriter\EventListener\AddImageGenerationFileControlListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Form\Event\CustomFileControlsEvent;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The "Generate image" control as FormEngine's file container gets it: the
 * event TYPO3 dispatches for every file field, through the real event
 * dispatcher, with the editor's real file mounts deciding whether there is a
 * folder the route could store in.
 */
#[CoversClass(AddImageGenerationFileControlListener::class)]
final class ImageFileControlTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            't3_cowriter' => [
                'image' => ['enable' => '1', 'model' => ''],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/storage_and_users.csv');
        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin/user_upload');

        // FormEngine runs in a backend request; only there TYPO3 applies the
        // user's file mounts and permissions to a storage. The icon of the
        // control needs the normalized parameters for its sprite URL.
        $request = (new ServerRequest('https://example.com/typo3/record/edit', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $GLOBALS['TYPO3_REQUEST'] = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function anEditorWithAnUploadFolderGetsTheControlOnAnImageField(): void
    {
        $this->setUpBackendUser(2);

        $event = $this->dispatch();

        $controls = $event->getControls();
        self::assertArrayHasKey('cowriterGenerateImage', $controls);
        self::assertStringContainsString(AddImageGenerationFileControlListener::CONTROL_CLASS, $controls['cowriterGenerateImage']);
        // The label comes from locallang_be.xlf, not as an LLL reference.
        self::assertStringContainsString('Generate image', $controls['cowriterGenerateImage']);
        self::assertStringNotContainsString('LLL:', $controls['cowriterGenerateImage']);
        self::assertStringContainsString('data-allowed-extensions="png"', $controls['cowriterGenerateImage']);

        $modules = array_map(
            static fn (mixed $module): string => is_object($module) && method_exists($module, 'getName') ? (string) $module->getName() : '',
            $event->getResultArray()['javaScriptModules'],
        );
        self::assertContains(AddImageGenerationFileControlListener::JAVASCRIPT_MODULE, $modules);
    }

    #[Test]
    public function anEditorWithoutFileMountGetsNoControl(): void
    {
        $this->setUpBackendUser(3);

        self::assertSame([], $this->dispatch()->getControls());
    }

    #[Test]
    public function anEditorWithAReadOnlyMountGetsNoControl(): void
    {
        $this->setUpBackendUser(4);

        self::assertSame([], $this->dispatch()->getControls());
    }

    /**
     * The whole chain FormEngine runs for a record: TCA preparation, the
     * data providers, FilesControlContainer and its event. The control must
     * name the object group the container listens to, or core drops the
     * hand-over message.
     */
    #[Test]
    public function formEngineRendersTheControlForTheObjectGroupOfTheField(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/formengine_record.csv');
        $backendUser     = $this->setUpBackendUser(5);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $formData = $this->get(FormDataCompiler::class)->compile(
            [
                // FormEngine's templates are resolved by the route's package.
                'request'    => $GLOBALS['TYPO3_REQUEST']->withAttribute('route', new Route('/record/edit', ['packageName' => 'typo3/cms-backend'])),
                'tableName'  => 'tt_content',
                'vanillaUid' => 1,
                'command'    => 'edit',
            ],
            $this->get(TcaDatabaseRecord::class),
        );
        $formData['renderType'] = 'fullRecordContainer';
        $html                   = (string) $this->get(NodeFactory::class)->create($formData)->render()['html'];

        self::assertSame(1, preg_match('/<button[^>]*' . preg_quote(AddImageGenerationFileControlListener::CONTROL_CLASS, '/') . '[^>]*>/', $html, $button), 'control rendered');
        self::assertSame(1, preg_match('/data-file-irre-object="([^"]+)"/', $button[0], $irreObject));
        self::assertStringEndsWith('-assets-sys_file_reference', $irreObject[1]);

        // The element FilesControlContainer renders for the field carries the
        // object group its JavaScript compares the message with.
        self::assertSame(
            1,
            preg_match('/<[^>]+data-object-group="' . preg_quote($irreObject[1], '/') . '"[^>]*>/', $html),
            'a container with data-object-group="' . $irreObject[1] . '"',
        );
        self::assertSame(1, preg_match('/data-form-field="([^"]+)"/', $button[0], $formField));
        self::assertSame('data[tt_content][1][assets]', html_entity_decode($formField[1]));
        self::assertStringContainsString('name="data[tt_content][1][assets]"', $html);
    }

    private function dispatch(): CustomFileControlsEvent
    {
        $event = new CustomFileControlsEvent(
            ['javaScriptModules' => []],
            'tt_content',
            'image',
            ['uid' => 1],
            ['type' => 'file', 'allowed' => 'gif,jpg,jpeg,png,webp', 'maxitems' => 99],
            'data-1-tt_content-1-image',
            'data[tt_content][1][image]',
        );

        $dispatched = $this->get(EventDispatcherInterface::class)->dispatch($event);
        self::assertInstanceOf(CustomFileControlsEvent::class, $dispatched);

        return $dispatched;
    }
}
