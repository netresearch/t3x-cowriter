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
use Psr\Http\Message\UriInterface;
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

    #[Test]
    public function invokeHandlesJsonExceptionGracefully(): void
    {
        // Return a UriInterface whose __toString produces invalid UTF-8,
        // causing json_encode(JSON_THROW_ON_ERROR) to throw JsonException
        $invalidUri = new class implements UriInterface {
            public function __toString(): string
            {
                return "\xB1\x31"; // Invalid UTF-8
            }

            public function getScheme(): string
            {
                return '';
            }

            public function getAuthority(): string
            {
                return '';
            }

            public function getUserInfo(): string
            {
                return '';
            }

            public function getHost(): string
            {
                return '';
            }

            public function getPort(): ?int
            {
                return null;
            }

            public function getPath(): string
            {
                return '';
            }

            public function getQuery(): string
            {
                return '';
            }

            public function getFragment(): string
            {
                return '';
            }

            public function withScheme(string $scheme): static
            {
                return $this;
            }

            public function withUserInfo(string $user, ?string $password = null): static
            {
                return $this;
            }

            public function withHost(string $host): static
            {
                return $this;
            }

            public function withPort(?int $port): static
            {
                return $this;
            }

            public function withPath(string $path): static
            {
                return $this;
            }

            public function withQuery(string $query): static
            {
                return $this;
            }

            public function withFragment(string $fragment): static
            {
                return $this;
            }
        };

        $this->backendUriBuilderMock
            ->method('buildUriFromRoute')
            ->willReturn($invalidUri);

        $this->loggerMock
            ->expects($this->once())
            ->method('error')
            ->with(
                'Cowriter: Failed to encode AJAX URLs as JSON',
                $this->callback(static fn (array $context): bool => isset($context['exception']) && is_string($context['exception'])),
            );

        $assetCollector = $this->createMock(AssetCollector::class);
        // addInlineJavaScript is called by buildJsonData BEFORE json_encode throws,
        // so we cannot assert it is never called. Instead, verify addJavaScript
        // is never called because the exception is caught before that line.
        // Actually, looking at the code flow: buildJsonData() is called inside
        // addInlineJavaScript's argument, so json_encode throws during the call,
        // and addInlineJavaScript itself may or may not receive the result.
        // The key assertion is that the logger IS called.

        $event = new BeforeJavaScriptsRenderingEvent(
            assetCollector: $assetCollector,
            isInline: true,
            priority: false,
        );

        // Should not throw - exception is caught and logged
        ($this->subject)($event);
    }
}
