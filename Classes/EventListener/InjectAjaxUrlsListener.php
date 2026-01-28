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
 * Event listener to inject AJAX URLs into the JavaScript settings.
 *
 * This makes AJAX routes available to the CKEditor cowriter plugin
 * via TYPO3.settings.ajaxUrls.
 */
#[AsEventListener(identifier: 'cowriter-inject-ajax-urls')]
final readonly class InjectAjaxUrlsListener
{
    public function __construct(
        private BackendUriBuilder $backendUriBuilder,
    ) {}

    public function __invoke(BeforeJavaScriptsRenderingEvent $event): void
    {
        // Only inject for inline JS (not external files)
        if (!$event->isInline()) {
            return;
        }

        $event->getAssetCollector()->addInlineJavaScript(
            'cowriter-ajax-urls',
            $this->buildInlineJs(),
            [],
            ['priority' => true],
        );
    }

    /**
     * Build inline JavaScript to inject AJAX URLs.
     */
    private function buildInlineJs(): string
    {
        $urls = [
            'tx_cowriter_chat' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('tx_cowriter_chat'),
            'tx_cowriter_complete' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('tx_cowriter_complete'),
            'tx_cowriter_configurations' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('tx_cowriter_configurations'),
        ];

        $urlsJson = json_encode($urls, JSON_THROW_ON_ERROR);

        return <<<JS
            (function() {
                if (typeof TYPO3 === 'undefined') return;
                TYPO3.settings = TYPO3.settings || {};
                TYPO3.settings.ajaxUrls = TYPO3.settings.ajaxUrls || {};
                var cowriterUrls = {$urlsJson};
                for (var key in cowriterUrls) {
                    if (cowriterUrls.hasOwnProperty(key)) {
                        TYPO3.settings.ajaxUrls[key] = cowriterUrls[key];
                    }
                }
            })();
            JS;
    }
}
