<?php

/*
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\EventListener;

use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Page\Event\BeforeJavaScriptsRenderingEvent;

/**
 * Event listener to inject AJAX URLs for the cowriter CKEditor plugin.
 *
 * Injects URLs as a JSON data element (not executable JavaScript) and loads
 * an external module to parse them. This approach is CSP-compliant and does
 * not require 'unsafe-inline' in the Content-Security-Policy.
 */
#[AsEventListener(identifier: 'cowriter-inject-ajax-urls')]
final readonly class InjectAjaxUrlsListener
{
    public function __construct(
        private BackendUriBuilder $backendUriBuilder,
    ) {}

    public function __invoke(BeforeJavaScriptsRenderingEvent $event): void
    {
        // Only inject for inline context (backend pages)
        if (!$event->isInline()) {
            return;
        }

        // Add JSON data element (type="application/json" is not executed)
        $event->getAssetCollector()->addInlineJavaScript(
            'cowriter-ajax-urls-data',
            $this->buildJsonData(),
            ['type'     => 'application/json', 'id' => 'cowriter-ajax-urls-data'],
            ['priority' => true],
        );

        // Load the URL loader module that reads from the JSON data
        $event->getAssetCollector()->addJavaScript(
            'cowriter-url-loader',
            'EXT:t3_cowriter/Resources/Public/JavaScript/Ckeditor/UrlLoader.js',
            ['type'     => 'module'],
            ['priority' => true],
        );
    }

    /**
     * Build JSON data containing AJAX URLs.
     *
     * This is NOT executable JavaScript - it's JSON data that will be
     * parsed by the UrlLoader.js module.
     */
    private function buildJsonData(): string
    {
        $urls = [
            'tx_cowriter_chat' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('tx_cowriter_chat'),
            'tx_cowriter_complete' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('tx_cowriter_complete'),
            'tx_cowriter_stream' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('tx_cowriter_stream'),
            'tx_cowriter_configurations' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('tx_cowriter_configurations'),
        ];

        return json_encode($urls, JSON_THROW_ON_ERROR);
    }
}
