<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Image;

use Netresearch\T3Cowriter\Service\Image\GeneratedImageStorage;
use Netresearch\T3Cowriter\Service\Image\ImageRejectedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Charset\CharsetConverter;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\DefaultUploadFolderResolver;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;

/**
 * The checks of the image storage that run before FAL is touched. Writing
 * the file is covered by the functional GeneratedImageStorageTest.
 */
#[CoversClass(GeneratedImageStorage::class)]
#[CoversClass(ImageRejectedException::class)]
final class GeneratedImageStorageTest extends TestCase
{
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function thereIsNoFolderWithoutABackendUser(): void
    {
        unset($GLOBALS['BE_USER']);
        $resolver = $this->createMock(DefaultUploadFolderResolver::class);
        $resolver->expects(self::never())->method('resolve');

        self::assertNull($this->storage($resolver)->targetFolder());
    }

    #[Test]
    public function thereIsNoFolderWhenTypo3ResolvesNone(): void
    {
        $GLOBALS['BE_USER'] = $this->createStub(BackendUserAuthentication::class);
        $resolver           = $this->createStub(DefaultUploadFolderResolver::class);
        $resolver->method('resolve')->willReturn(false);

        self::assertNull($this->storage($resolver)->targetFolder());
    }

    #[Test]
    public function theUsersUploadFolderIsUsedWhenTheyMayAddFilesToIt(): void
    {
        $user               = $this->createStub(BackendUserAuthentication::class);
        $GLOBALS['BE_USER'] = $user;
        $folder             = $this->folder(public: true, mayAdd: true, mayWrite: true);
        $resolver           = $this->createMock(DefaultUploadFolderResolver::class);
        $resolver->expects(self::once())->method('resolve')->with($user)->willReturn($folder);

        self::assertSame($folder, $this->storage($resolver)->targetFolder());
    }

    #[Test]
    #[DataProvider('foldersThatCannotTakeTheImage')]
    public function aFolderTheUserCannotUseIsNotOffered(bool $public, bool $mayAdd, bool $mayWrite): void
    {
        $GLOBALS['BE_USER'] = $this->createStub(BackendUserAuthentication::class);
        $resolver           = $this->createStub(DefaultUploadFolderResolver::class);
        $resolver->method('resolve')->willReturn($this->folder($public, $mayAdd, $mayWrite));

        self::assertNull($this->storage($resolver)->targetFolder());
    }

    /**
     * @return iterable<string, array{bool, bool, bool}>
     */
    public static function foldersThatCannotTakeTheImage(): iterable
    {
        yield 'storage not public' => [false, true, true];
        yield 'user may not add files' => [true, false, true];
        yield 'folder not writable' => [true, true, false];
    }

    #[Test]
    public function missingImageDataIsRejected(): void
    {
        $this->assertRejected(null, ImageRejectedException::REASON_MISSING);
        $this->assertRejected('', ImageRejectedException::REASON_MISSING);
    }

    #[Test]
    public function invalidBase64IsRejected(): void
    {
        $this->assertRejected('not base64 at all!', ImageRejectedException::REASON_MISSING);
    }

    #[Test]
    public function dataOverTheSizeCapIsRejectedBeforeDecoding(): void
    {
        $this->assertRejected(
            str_repeat('A', intdiv(GeneratedImageStorage::MAX_BYTES + 2, 3) * 4 + 4),
            ImageRejectedException::REASON_TOO_LARGE,
        );
    }

    #[Test]
    #[DataProvider('filesThatAreNoImage')]
    public function contentThatIsNoPngJpegOrWebpIsRejected(string $bytes): void
    {
        $this->assertRejected(base64_encode($bytes), ImageRejectedException::REASON_TYPE);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function filesThatAreNoImage(): iterable
    {
        yield 'html' => ['<html><body><script>alert(1)</script></body></html>'];
        yield 'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'];
        yield 'gif' => [(string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true)];
        yield 'png header only' => ["\x89PNG\r\n\x1a\n" . str_repeat("\0", 16)];
    }

    #[Test]
    public function aValidPngPassesTheChecksAndReachesFal(): void
    {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->expects(self::once())->method('addFile')->willThrowException(new RuntimeException('reached FAL'));
        $folder = $this->createStub(Folder::class);
        $folder->method('getStorage')->willReturn($storage);

        $this->expectExceptionMessage('reached FAL');
        $this->storage()->store(self::PNG_BASE64, 'A lighthouse', $folder);
    }

    #[Test]
    public function theTemporaryFileIsRemovedWhenFalFails(): void
    {
        $pattern = Environment::getVarPath() . '/transient/cowriter_image_*';
        $before  = glob($pattern) ?: [];

        $handedOver = null;
        $storage    = $this->createStub(ResourceStorage::class);
        $storage->method('addFile')->willReturnCallback(static function (string $path) use (&$handedOver): never {
            $handedOver = $path;

            throw new RuntimeException('refused');
        });
        $folder = $this->createStub(Folder::class);
        $folder->method('getStorage')->willReturn($storage);

        try {
            $this->storage()->store(self::PNG_BASE64, 'A lighthouse', $folder);
            self::fail('FAL did not refuse.');
        } catch (RuntimeException $e) {
            self::assertSame('refused', $e->getMessage());
        }

        self::assertIsString($handedOver);
        self::assertFileDoesNotExist($handedOver);
        self::assertSame($before, glob($pattern) ?: []);
    }

    #[Test]
    public function dataOfExactlyTheSizeCapPassesTheSizeChecks(): void
    {
        // Decodes to exactly MAX_BYTES zero bytes: no image, but not too large.
        $length = intdiv(GeneratedImageStorage::MAX_BYTES + 2, 3) * 4;
        $this->assertRejected(str_repeat('A', $length - 2) . '==', ImageRejectedException::REASON_TYPE);
    }

    #[Test]
    public function decodedDataOverTheSizeCapIsRejected(): void
    {
        // Within the encoded length, but two bytes over the cap once decoded.
        $length = intdiv(GeneratedImageStorage::MAX_BYTES + 2, 3) * 4;
        $this->assertRejected(str_repeat('A', $length), ImageRejectedException::REASON_TOO_LARGE);
    }

    #[Test]
    #[DataProvider('fileNames')]
    public function theFileNameIsASlugOfThePromptWithARandomSuffix(string $prompt, string $expectedStart): void
    {
        $name = $this->storage()->fileName($prompt, 'png');

        self::assertMatchesRegularExpression('/^' . preg_quote($expectedStart, '/') . '-[0-9a-f]{8}\.png$/', $name);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fileNames(): iterable
    {
        yield 'plain words' => ['A lighthouse at dusk', 'a-lighthouse-at-dusk'];
        yield 'path and markup characters are dropped' => ['../../etc/<script>', 'etc-script'];
        yield 'only symbols' => ['!!! ???', 'image'];
        yield 'leading and trailing symbols' => ['!!Hello!!', 'hello'];
        yield 'the cut lands on a separator' => [str_repeat('abcdefghi ', 5), 'abcdefghi-abcdefghi-abcdefghi-abcdefghi'];
        yield 'long prompts are cut' => [str_repeat('lighthouse ', 10), 'lighthouse-lighthouse-lighthouse-lightho'];
    }

    #[Test]
    public function twoImagesFromOnePromptGetTwoNames(): void
    {
        $storage = $this->storage();

        self::assertNotSame($storage->fileName('A lighthouse', 'png'), $storage->fileName('A lighthouse', 'png'));
    }

    private function assertRejected(?string $base64, string $reason): void
    {
        $folder = $this->createMock(Folder::class);
        $folder->expects(self::never())->method('getStorage');

        try {
            $this->storage()->store($base64, 'A lighthouse', $folder);
            self::fail('The data was not rejected.');
        } catch (ImageRejectedException $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    private function folder(bool $public, bool $mayAdd, bool $mayWrite): Folder
    {
        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('isPublic')->willReturn($public);
        $storage->method('checkUserActionPermission')->willReturnMap([['add', 'File', $mayAdd]]);

        $folder = $this->createStub(Folder::class);
        $folder->method('getStorage')->willReturn($storage);
        $folder->method('checkActionPermission')->willReturnMap([['write', $mayWrite]]);

        return $folder;
    }

    private function storage(?DefaultUploadFolderResolver $resolver = null): GeneratedImageStorage
    {
        return new GeneratedImageStorage(
            $resolver ?? $this->createStub(DefaultUploadFolderResolver::class),
            $this->charsetConverter(),
        );
    }

    /**
     * The converter passes the prompt through unchanged: on TYPO3 13.4 the
     * real one reads its tables from EXT:core, which a unit test does not
     * load. The functional GeneratedImageStorageTest covers transliteration.
     */
    private function charsetConverter(): CharsetConverter
    {
        $converter = $this->createStub(CharsetConverter::class);
        $converter->method('utf8_char_mapping')->willReturnArgument(0);

        return $converter;
    }
}
