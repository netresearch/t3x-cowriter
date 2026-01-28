<?php

/*
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\EventListener;

use Netresearch\T3Cowriter\EventListener\InjectAjaxUrlsListener;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\Event\BeforeJavaScriptsRenderingEvent;

#[CoversClass(InjectAjaxUrlsListener::class)]
#[AllowMockObjectsWithoutExpectations]
final class InjectAjaxUrlsListenerTest extends TestCase
{
    private InjectAjaxUrlsListener $subject;
    private BackendUriBuilder&MockObject $backendUriBuilderMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backendUriBuilderMock = $this->createMock(BackendUriBuilder::class);
        $this->subject               = new InjectAjaxUrlsListener($this->backendUriBuilderMock);
    }

    #[Test]
    public function invokeDoesNothingWhenNotInline(): void
    {
        $assetCollector = $this->createMock(AssetCollector::class);
        $assetCollector->expects($this->never())->method('addInlineJavaScript');

        $event = new BeforeJavaScriptsRenderingEvent(
            assetCollector: $assetCollector,
            isInline: false,
            priority: false,
        );

        ($this->subject)($event);
    }

    #[Test]
    public function invokeAddsInlineJsWhenInline(): void
    {
        $this->backendUriBuilderMock
            ->method('buildUriFromRoute')
            ->willReturnCallback(fn (string $route) => new Uri('/typo3/ajax/' . $route));

        $assetCollector = $this->createMock(AssetCollector::class);
        $assetCollector
            ->expects($this->once())
            ->method('addInlineJavaScript')
            ->with(
                'cowriter-ajax-urls',
                $this->isString(),
                [],
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
    public function invokeGeneratesValidJavaScript(): void
    {
        $this->backendUriBuilderMock
            ->method('buildUriFromRoute')
            ->willReturnCallback(fn (string $route) => new Uri('/typo3/ajax/' . $route));

        $capturedJs     = '';
        $assetCollector = $this->createMock(AssetCollector::class);
        $assetCollector
            ->method('addInlineJavaScript')
            ->willReturnCallback(function (string $identifier, string $js) use (&$capturedJs, $assetCollector): AssetCollector {
                $capturedJs = $js;

                return $assetCollector;
            });

        $event = new BeforeJavaScriptsRenderingEvent(
            assetCollector: $assetCollector,
            isInline: true,
            priority: false,
        );

        ($this->subject)($event);

        // Verify JavaScript contains expected content
        $this->assertStringContainsString('TYPO3.settings', $capturedJs);
        $this->assertStringContainsString('ajaxUrls', $capturedJs);
        $this->assertStringContainsString('tx_cowriter_chat', $capturedJs);
        $this->assertStringContainsString('tx_cowriter_complete', $capturedJs);
        $this->assertStringContainsString('tx_cowriter_configurations', $capturedJs);
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

        $event = new BeforeJavaScriptsRenderingEvent(
            assetCollector: $assetCollector,
            isInline: true,
            priority: false,
        );

        ($this->subject)($event);

        $this->assertContains('tx_cowriter_chat', $generatedRoutes);
        $this->assertContains('tx_cowriter_complete', $generatedRoutes);
        $this->assertContains('tx_cowriter_configurations', $generatedRoutes);
    }

    #[Test]
    public function invokeUsesHighPriority(): void
    {
        $this->backendUriBuilderMock
            ->method('buildUriFromRoute')
            ->willReturn(new Uri('/typo3/ajax/test'));

        $capturedOptions = [];
        $assetCollector  = $this->createMock(AssetCollector::class);
        $assetCollector
            ->method('addInlineJavaScript')
            ->willReturnCallback(function ($id, $js, $attrs, $options) use (&$capturedOptions, $assetCollector): AssetCollector {
                $capturedOptions = $options;

                return $assetCollector;
            });

        $event = new BeforeJavaScriptsRenderingEvent(
            assetCollector: $assetCollector,
            isInline: true,
            priority: false,
        );

        ($this->subject)($event);

        $this->assertArrayHasKey('priority', $capturedOptions);
        $this->assertTrue($capturedOptions['priority']);
    }

    #[Test]
    public function invokeGeneratesValidJson(): void
    {
        $this->backendUriBuilderMock
            ->method('buildUriFromRoute')
            ->willReturnCallback(fn (string $route) => new Uri('/typo3/ajax/' . $route));

        $capturedJs     = '';
        $assetCollector = $this->createMock(AssetCollector::class);
        $assetCollector
            ->method('addInlineJavaScript')
            ->willReturnCallback(function (string $identifier, string $js) use (&$capturedJs, $assetCollector): AssetCollector {
                $capturedJs = $js;

                return $assetCollector;
            });

        $event = new BeforeJavaScriptsRenderingEvent(
            assetCollector: $assetCollector,
            isInline: true,
            priority: false,
        );

        ($this->subject)($event);

        // Extract JSON from the JavaScript
        if (preg_match('/var cowriterUrls = ({[^}]+});/', $capturedJs, $matches)) {
            $json    = $matches[1];
            $decoded = json_decode($json, true);
            $this->assertIsArray($decoded);
            $this->assertArrayHasKey('tx_cowriter_chat', $decoded);
            $this->assertArrayHasKey('tx_cowriter_complete', $decoded);
            $this->assertArrayHasKey('tx_cowriter_configurations', $decoded);
        } else {
            $this->fail('Could not extract JSON from JavaScript output');
        }
    }
}
