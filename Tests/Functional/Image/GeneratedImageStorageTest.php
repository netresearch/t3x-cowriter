<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Image;

use Netresearch\T3Cowriter\Controller\ImageGenerationController;
use Netresearch\T3Cowriter\Service\Image\GeneratedImageStorage;
use Netresearch\T3Cowriter\Service\Image\GeneratedImageStorageInterface;
use Netresearch\T3Cowriter\Service\Image\StoredImage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A generated image written through FAL as a real backend user: into the
 * folder TYPO3 resolves for the user's uploads, with the user's file mounts
 * and permissions, indexed with a metadata record.
 */
#[CoversClass(GeneratedImageStorage::class)]
#[CoversClass(StoredImage::class)]
final class GeneratedImageStorageTest extends FunctionalTestCase
{
    /**
     * A 2x1 PNG.
     */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAADUlEQVR4nGP4zwAE/wEHAAH/4iOeWQAAAABJRU5ErkJggg==';

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

        // The route is a backend AJAX request. Only for a backend request does
        // TYPO3 apply the user's file mounts and permissions to a storage
        // (StoragePermissionsAspect); without it every check below would pass.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://example.com/typo3/ajax/cowriter/image-generate', 'POST'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function anEditorsImageIsStoredInTheirUploadFolderAndIndexed(): void
    {
        $this->setUpBackendUser(2);
        $storage = $this->subject();

        $folder = $storage->targetFolder();
        self::assertInstanceOf(Folder::class, $folder);
        self::assertSame('1:/user_upload/', $folder->getCombinedIdentifier());

        $stored = $storage->store(self::PNG_BASE64, 'Grüner Leuchtturm bei Nacht', $folder);

        self::assertSame(2, $stored->width);
        self::assertSame(1, $stored->height);
        self::assertSame('image/png', $stored->mimeType);
        self::assertMatchesRegularExpression('#^1:/user_upload/gruener-leuchtturm-bei-nacht-[0-9a-f]{8}\.png$#', $stored->combinedIdentifier);
        self::assertStringContainsString('fileadmin/user_upload/gruener-leuchtturm-bei-nacht-', $stored->publicUrl);

        $path = Environment::getPublicPath() . '/fileadmin' . substr($stored->combinedIdentifier, 2);
        self::assertFileExists($path);
        self::assertSame(base64_decode(self::PNG_BASE64, true), file_get_contents($path));

        $row = $this->getConnectionPool()->getConnectionForTable('sys_file')
            ->select(['storage', 'identifier', 'mime_type'], 'sys_file', ['uid' => $stored->fileUid])
            ->fetchAssociative();
        self::assertSame(
            ['storage' => 1, 'identifier' => substr($stored->combinedIdentifier, 2), 'mime_type' => 'image/png'],
            ['storage' => (int) ($row['storage'] ?? 0), 'identifier' => $row['identifier'] ?? null, 'mime_type' => $row['mime_type'] ?? null],
        );

        // The metadata record FAL creates for a new file; creating it
        // dispatches AfterFileMetaDataCreatedEvent.
        $metadata = $this->getConnectionPool()->getConnectionForTable('sys_file_metadata')
            ->count('uid', 'sys_file_metadata', ['file' => $stored->fileUid]);
        self::assertSame(1, $metadata);
    }

    #[Test]
    public function anEditorWithoutFileMountHasNoFolder(): void
    {
        $this->setUpBackendUser(3);

        self::assertNull($this->subject()->targetFolder());
    }

    #[Test]
    public function anEditorWithAReadOnlyMountHasNoFolder(): void
    {
        $this->setUpBackendUser(4);

        self::assertNull($this->subject()->targetFolder());
    }

    #[Test]
    public function theContainerWiresTheStorageIntoTheController(): void
    {
        self::assertInstanceOf(GeneratedImageStorage::class, $this->get(GeneratedImageStorageInterface::class));
        self::assertInstanceOf(ImageGenerationController::class, $this->get(ImageGenerationController::class));
    }

    private function subject(): GeneratedImageStorage
    {
        $storage = $this->get(GeneratedImageStorageInterface::class);
        self::assertInstanceOf(GeneratedImageStorage::class, $storage);

        return $storage;
    }
}
