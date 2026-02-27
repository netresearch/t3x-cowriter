// vim: ts=4 sw=4 expandtab colorcolumn=120
// @ts-check

/**
 * URL Loader - Reads cowriter AJAX URLs from JSON data element.
 *
 * This module reads URLs from a <script type="application/json"> element
 * injected by the backend, avoiding the need for inline JavaScript execution.
 *
 * @module UrlLoader
 */

/**
 * Load cowriter AJAX URLs into TYPO3.settings.ajaxUrls.
 *
 * Reads from a JSON script element with id="cowriter-ajax-urls-data"
 * and merges the URLs into the global TYPO3 settings object.
 */
export function loadCowriterUrls() {
    if (typeof TYPO3 === 'undefined') {
        return;
    }

    const dataElement = document.getElementById('cowriter-ajax-urls-data');
    if (!dataElement) {
        console.warn('Cowriter: URL data element not found');
        return;
    }

    try {
        const urls = JSON.parse(dataElement.textContent || '{}');

        // Initialize settings structure if needed
        TYPO3.settings = TYPO3.settings || {};
        TYPO3.settings.ajaxUrls = TYPO3.settings.ajaxUrls || {};

        // Merge only expected cowriter URL keys (defense-in-depth against prototype pollution).
        // Keep in sync with Configuration/Backend/AjaxRoutes.php
        const allowedKeys = ['tx_cowriter_chat', 'tx_cowriter_complete', 'tx_cowriter_stream', 'tx_cowriter_configurations'];
        for (const key of allowedKeys) {
            if (Object.prototype.hasOwnProperty.call(urls, key) && typeof urls[key] === 'string') {
                TYPO3.settings.ajaxUrls[key] = urls[key];
            }
        }
    } catch (error) {
        console.error('Cowriter: Failed to parse URL data', error);
    }
}

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadCowriterUrls);
} else {
    loadCowriterUrls();
}
