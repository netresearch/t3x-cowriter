<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\EventListener;

use Netresearch\T3Cowriter\EventListener\InjectAjaxUrlsListener;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\Event\BeforeJavaScriptsRenderingEvent;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(InjectAjaxUrlsListener::class)]
final class InjectAjaxUrlsListenerTest extends TestCase
{
    private InjectAjaxUrlsListener $subject;
    private BackendUriBuilder&MockObject $backendUriBuilderMock;
    private LoggerInterface&MockObject $loggerMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backendUriBuilderMock = $this->createMock(BackendUriBuilder::class);
        $this->loggerMock            = $this->createMock(LoggerInterface::class);
        $this->subject               = new InjectAjaxUrlsListener($this->backendUriBuilderMock, $this->loggerMock);
    }

    #[Test]
    public function invokeDoesNothingWhenNotInline(): void
    {
        $assetCollector = $this->createMock(AssetCollector::class);
        $assetCollector->expects($this->never())->method('addInlineJavaScript');
        $assetCollector->expects($this->never())->method('addJavaScript');

        $event = new BeforeJavaScriptsRenderingEvent(
            assetCollector: $assetCollector,
            isInline: false,
            priority: false,
        );

        ($this->subject)($event);
    }

    #[Test]
    public function invokeAddsJsonDataElementWhenInline(): void
    {
        $this->backendUriBuilderMock
            ->method('buildUriFromRoute')
            ->willReturnCallback(fn (string $route) => new Uri('/typo3/ajax/' . $route));

        $assetCollector = $this->createMock(AssetCollector::class);
        $assetCollector
            ->expects($this->once())
            ->method('addInlineJavaScript')
            ->with(
                'cowriter-ajax-urls-data',
                $this->isString(),
                ['type'     => 'application/json', 'id' => 'cowriter-ajax-urls-data'],
                ['priority' => true],
            );

        $assetCollector
            ->expects($this->once())
            ->method('addJavaScript')
            ->with(
                'cowriter-url-loader',
                'EXT:t3_cowriter/Resources/Public/JavaScript/Ckeditor/UrlLoader.js',
                ['type'     => 'module'],
                ['priority' => true],
            );

        $event = new BeforeJavaScriptsRenderingEvent(
            assetCollector: $assetCollector,
            isInline: true,
            priority: false,
        );

        ($this->subject)($event);
    }

    #[Test]
    public function invokeGeneratesValidJsonData(): void
    {
        $this->backendUriBuilderMock
            ->method('buildUriFromRoute')
            ->willReturnCallback(fn (string $route) => new Uri('/typo3/ajax/' . $route));

        $capturedJson   = '';
        $assetCollector = $this->createMock(AssetCollector::class);
        $assetCollector
            ->method('addInlineJavaScript')
            ->willReturnCallback(function (string $identifier, string $json) use (&$capturedJson, $assetCollector): AssetCollector {
                $capturedJson = $json;

                return $assetCollector;
            });
        $assetCollector->method('addJavaScript');

        $event = new BeforeJavaScriptsRenderingEvent(
            assetCollector: $assetCollector,
            isInline: true,
            priority: false,
        );

        ($this->subject)($event);

        // Verify JSON is valid and contains expected keys
        $decoded = json_decode($capturedJson, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('tx_cowriter_chat', $decoded);
        $this->assertArrayHasKey('tx_cowriter_complete', $decoded);
        $this->assertArrayHasKey('tx_cowriter_stream', $decoded);
        $this->assertArrayHasKey('tx_cowriter_configurations', $decoded);
    }

    #[Test]
    public function invokeGeneratesCorrectRouteUrls(): void
    {
        $generatedRoutes = [];
        $this->backendUriBuilderMock
            ->method('buildUriFromRoute')
            ->willReturnCallback(function (string $route) use (&$generatedRoutes) {
                $generatedRoutes[] = $route;

                return new Uri('/typo3/ajax/' . $route);
            });

        $assetCollector = $this->createMock(AssetCollector::class);
        $assetCollector->method('addInlineJavaScript');
        $assetCollector->method('addJavaScript');

        $event = new BeforeJavaScriptsRenderingEvent(
            assetCollector: $assetCollector,
            isInline: true,
            priority: false,
        );

        ($this->subject)($event);

        $this->assertContains('tx_cowriter_chat', $generatedRoutes);
        $this->assertContains('tx_cowriter_complete', $generatedRoutes);
        $this->assertContains('tx_cowriter_stream', $generatedRoutes);
        $this->assertContains('tx_cowriter_configurations', $generatedRoutes);
    }

    #[Test]
    public function invokeUsesHighPriorityForBothAssets(): void
    {
        $this->backendUriBuilderMock
            ->method('buildUriFromRoute')
            ->willReturn(new Uri('/typo3/ajax/test'));

        $inlineOptions  = [];
        $jsOptions      = [];
        $assetCollector = $this->createMock(AssetCollector::class);
        $assetCollector
            ->method('addInlineJavaScript')
            ->willReturnCallback(function ($id, $json, $attrs, $options) use (&$inlineOptions, $assetCollector): AssetCollector {
                $inlineOptions = $options;

                return $assetCollector;
            });
        $assetCollector
            ->method('addJavaScript')
            ->willReturnCallback(function ($id, $src, $attrs, $options) use (&$jsOptions, $assetCollector): AssetCollector {
                $jsOptions = $options;

                return $assetCollector;
            });

        $event = new BeforeJavaScriptsRenderingEvent(
            assetCollector: $assetCollector,
            isInline: true,
            priority: false,
        );

        ($this->subject)($event);

        // Both inline JSON data and external JS should have high priority
        $this->assertArrayHasKey('priority', $inlineOptions);
        $this->assertTrue($inlineOptions['priority']);
        $this->assertArrayHasKey('priority', $jsOptions);
        $this->assertTrue($jsOptions['priority']);
    }

    #[Test]
    public function invokeJsonContainsCorrectUrls(): void
    {
        $this->backendUriBuilderMock
            ->method('buildUriFromRoute')
            ->willReturnCallback(fn (string $route) => new Uri('/typo3/ajax/' . $route));

        $capturedJson   = '';
        $assetCollector = $this->createMock(AssetCollector::class);
        $assetCollector
            ->method('addInlineJavaScript')
            ->willReturnCallback(function (string $identifier, string $json) use (&$capturedJson, $assetCollector): AssetCollector {
                $capturedJson = $json;

                return $assetCollector;
            });
        $assetCollector->method('addJavaScript');

        $event = new BeforeJavaScriptsRenderingEvent(
            assetCollector: $assetCollector,
            isInline: true,
            priority: false,
        );

        ($this->subject)($event);

        $decoded = json_decode($capturedJson, true);
        $this->assertSame('/typo3/ajax/tx_cowriter_chat', $decoded['tx_cowriter_chat']);
        $this->assertSame('/typo3/ajax/tx_cowriter_complete', $decoded['tx_cowriter_complete']);
        $this->assertSame('/typo3/ajax/tx_cowriter_stream', $decoded['tx_cowriter_stream']);
        $this->assertSame('/typo3/ajax/tx_cowriter_configurations', $decoded['tx_cowriter_configurations']);
    }

    #[Test]
    public function invokeAddsExternalModuleWithCorrectPath(): void
    {
        $this->backendUriBuilderMock
            ->method('buildUriFromRoute')
            ->willReturn(new Uri('/typo3/ajax/test'));

        $capturedSrc    = '';
        $assetCollector = $this->createMock(AssetCollector::class);
        $assetCollector->method('addInlineJavaScript');
        $assetCollector
            ->method('addJavaScript')
            ->willReturnCallback(function ($id, $src) use (&$capturedSrc, $assetCollector): AssetCollector {
                $capturedSrc = $src;

                return $assetCollector;
            });

        $event = new BeforeJavaScriptsRenderingEvent(
            assetCollector: $assetCollector,
            isInline: true,
            priority: false,
        );

        ($this->subject)($event);

        $this->assertSame(
            'EXT:t3_cowriter/Resources/Public/JavaScript/Ckeditor/UrlLoader.js',
            $capturedSrc,
        );
    }

    #[Test]
    public function invokeAddsExternalModuleWithTypeModule(): void
    {
        $this->backendUriBuilderMock
            ->method('buildUriFromRoute')
            ->willReturn(new Uri('/typo3/ajax/test'));

        $capturedAttrs  = [];
        $assetCollector = $this->createMock(AssetCollector::class);
        $assetCollector->method('addInlineJavaScript');
        $assetCollector
            ->method('addJavaScript')
            ->willReturnCallback(function ($id, $src, $attrs) use (&$capturedAttrs, $assetCollector): AssetCollector {
                $capturedAttrs = $attrs;

                return $assetCollector;
            });

        $event = new BeforeJavaScriptsRenderingEvent(
            assetCollector: $assetCollector,
            isInline: true,
            priority: false,
        );

        ($this->subject)($event);

        $this->assertArrayHasKey('type', $capturedAttrs);
        $this->assertSame('module', $capturedAttrs['type']);
    }
}
