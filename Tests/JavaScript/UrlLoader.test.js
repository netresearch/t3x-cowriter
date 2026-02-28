/**
 * Tests for UrlLoader module.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// Mock TYPO3 global before importing module
let TYPO3Mock;

describe('UrlLoader', () => {
    beforeEach(() => {
        // Reset DOM by removing all children
        while (document.body.firstChild) {
            document.body.removeChild(document.body.firstChild);
        }

        // Setup TYPO3 mock
        TYPO3Mock = {
            settings: {
                ajaxUrls: {},
            },
        };
        globalThis.TYPO3 = TYPO3Mock;
    });

    afterEach(() => {
        delete globalThis.TYPO3;
        vi.resetModules();
    });

    describe('loadCowriterUrls', () => {
        it('should do nothing when TYPO3 is undefined', async () => {
            delete globalThis.TYPO3;

            const { loadCowriterUrls } = await import(
                '../../Resources/Public/JavaScript/Ckeditor/UrlLoader.js'
            );
            // Should not throw
            expect(() => loadCowriterUrls()).not.toThrow();
        });

        it('should warn when data element is not found', async () => {
            const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

            // Re-import to get fresh module
            vi.resetModules();
            globalThis.TYPO3 = TYPO3Mock;

            const { loadCowriterUrls } = await import(
                '../../Resources/Public/JavaScript/Ckeditor/UrlLoader.js'
            );
            loadCowriterUrls();

            expect(warnSpy).toHaveBeenCalledWith('Cowriter: URL data element not found');
            warnSpy.mockRestore();
        });

        it('should parse JSON data and merge into TYPO3.settings.ajaxUrls', async () => {
            // Create data element with JSON
            const dataElement = document.createElement('script');
            dataElement.type = 'application/json';
            dataElement.id = 'cowriter-ajax-urls-data';
            dataElement.textContent = JSON.stringify({
                tx_cowriter_chat: '/typo3/ajax/tx_cowriter_chat',
                tx_cowriter_complete: '/typo3/ajax/tx_cowriter_complete',
            });
            document.body.appendChild(dataElement);

            vi.resetModules();
            globalThis.TYPO3 = TYPO3Mock;

            const { loadCowriterUrls } = await import(
                '../../Resources/Public/JavaScript/Ckeditor/UrlLoader.js'
            );
            loadCowriterUrls();

            expect(TYPO3Mock.settings.ajaxUrls.tx_cowriter_chat).toBe('/typo3/ajax/tx_cowriter_chat');
            expect(TYPO3Mock.settings.ajaxUrls.tx_cowriter_complete).toBe(
                '/typo3/ajax/tx_cowriter_complete'
            );
        });

        it('should initialize TYPO3.settings if not present', async () => {
            globalThis.TYPO3 = {};

            const dataElement = document.createElement('script');
            dataElement.type = 'application/json';
            dataElement.id = 'cowriter-ajax-urls-data';
            dataElement.textContent = JSON.stringify({ tx_cowriter_chat: '/test' });
            document.body.appendChild(dataElement);

            vi.resetModules();

            const { loadCowriterUrls } = await import(
                '../../Resources/Public/JavaScript/Ckeditor/UrlLoader.js'
            );
            loadCowriterUrls();

            expect(globalThis.TYPO3.settings).toBeDefined();
            expect(globalThis.TYPO3.settings.ajaxUrls).toBeDefined();
            expect(globalThis.TYPO3.settings.ajaxUrls.tx_cowriter_chat).toBe('/test');
        });

        it('should handle invalid JSON gracefully', async () => {
            const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

            const dataElement = document.createElement('script');
            dataElement.type = 'application/json';
            dataElement.id = 'cowriter-ajax-urls-data';
            dataElement.textContent = 'invalid json {{{';
            document.body.appendChild(dataElement);

            vi.resetModules();
            globalThis.TYPO3 = TYPO3Mock;

            const { loadCowriterUrls } = await import(
                '../../Resources/Public/JavaScript/Ckeditor/UrlLoader.js'
            );
            loadCowriterUrls();

            expect(errorSpy).toHaveBeenCalledWith(
                'Cowriter: Failed to parse URL data',
                expect.any(Error)
            );
            errorSpy.mockRestore();
        });

        it('should handle empty data element', async () => {
            const dataElement = document.createElement('script');
            dataElement.type = 'application/json';
            dataElement.id = 'cowriter-ajax-urls-data';
            dataElement.textContent = '';
            document.body.appendChild(dataElement);

            vi.resetModules();
            globalThis.TYPO3 = TYPO3Mock;

            const { loadCowriterUrls } = await import(
                '../../Resources/Public/JavaScript/Ckeditor/UrlLoader.js'
            );

            // Should not throw - empty string defaults to '{}'
            expect(() => loadCowriterUrls()).not.toThrow();
        });

        it('should preserve existing ajaxUrls when merging', async () => {
            TYPO3Mock.settings.ajaxUrls = {
                existing_route: '/existing',
            };

            const dataElement = document.createElement('script');
            dataElement.type = 'application/json';
            dataElement.id = 'cowriter-ajax-urls-data';
            dataElement.textContent = JSON.stringify({ tx_cowriter_chat: '/new' });
            document.body.appendChild(dataElement);

            vi.resetModules();
            globalThis.TYPO3 = TYPO3Mock;

            const { loadCowriterUrls } = await import(
                '../../Resources/Public/JavaScript/Ckeditor/UrlLoader.js'
            );
            loadCowriterUrls();

            expect(TYPO3Mock.settings.ajaxUrls.existing_route).toBe('/existing');
            expect(TYPO3Mock.settings.ajaxUrls.tx_cowriter_chat).toBe('/new');
        });

        it('should only accept allowed cowriter URL keys', async () => {
            const dataElement = document.createElement('script');
            dataElement.type = 'application/json';
            dataElement.id = 'cowriter-ajax-urls-data';
            dataElement.textContent = JSON.stringify({
                tx_cowriter_chat: '/chat',
                tx_cowriter_complete: '/complete',
                tx_cowriter_stream: '/stream',
                tx_cowriter_configurations: '/configs',
                __proto__: { polluted: true },
                malicious_route: '/evil',
                constructor: '/hack',
            });
            document.body.appendChild(dataElement);

            vi.resetModules();
            globalThis.TYPO3 = TYPO3Mock;

            const { loadCowriterUrls } = await import(
                '../../Resources/Public/JavaScript/Ckeditor/UrlLoader.js'
            );
            loadCowriterUrls();

            // Allowed keys should be set
            expect(TYPO3Mock.settings.ajaxUrls.tx_cowriter_chat).toBe('/chat');
            expect(TYPO3Mock.settings.ajaxUrls.tx_cowriter_complete).toBe('/complete');
            expect(TYPO3Mock.settings.ajaxUrls.tx_cowriter_stream).toBe('/stream');
            expect(TYPO3Mock.settings.ajaxUrls.tx_cowriter_configurations).toBe('/configs');

            // Non-allowed keys should be filtered out
            expect(TYPO3Mock.settings.ajaxUrls.malicious_route).toBeUndefined();
            expect(TYPO3Mock.settings.ajaxUrls.constructor).not.toBe('/hack');
        });
    });

    describe('auto-initialization', () => {
        it('should add DOMContentLoaded listener when document is loading', async () => {
            const addEventListenerSpy = vi.spyOn(document, 'addEventListener');

            // Override readyState to 'loading'
            Object.defineProperty(document, 'readyState', {
                value: 'loading',
                writable: true,
                configurable: true,
            });

            vi.resetModules();
            globalThis.TYPO3 = TYPO3Mock;

            await import('../../Resources/Public/JavaScript/Ckeditor/UrlLoader.js');

            expect(addEventListenerSpy).toHaveBeenCalledWith(
                'DOMContentLoaded',
                expect.any(Function)
            );

            // Restore readyState
            Object.defineProperty(document, 'readyState', {
                value: 'complete',
                writable: true,
                configurable: true,
            });
            addEventListenerSpy.mockRestore();
        });
    });

    describe('edge cases', () => {
        it('should reject non-string values in URL data', async () => {
            const dataElement = document.createElement('script');
            dataElement.type = 'application/json';
            dataElement.id = 'cowriter-ajax-urls-data';
            dataElement.textContent = JSON.stringify({
                tx_cowriter_chat: 123,
                tx_cowriter_complete: null,
                tx_cowriter_stream: true,
                tx_cowriter_configurations: '/valid',
            });
            document.body.appendChild(dataElement);

            vi.resetModules();
            globalThis.TYPO3 = TYPO3Mock;

            const { loadCowriterUrls } = await import(
                '../../Resources/Public/JavaScript/Ckeditor/UrlLoader.js'
            );
            loadCowriterUrls();

            // Only string values should be accepted
            expect(TYPO3Mock.settings.ajaxUrls.tx_cowriter_chat).toBeUndefined();
            expect(TYPO3Mock.settings.ajaxUrls.tx_cowriter_complete).toBeUndefined();
            expect(TYPO3Mock.settings.ajaxUrls.tx_cowriter_stream).toBeUndefined();
            expect(TYPO3Mock.settings.ajaxUrls.tx_cowriter_configurations).toBe('/valid');
        });

        it('should handle data element with whitespace around JSON', async () => {
            const dataElement = document.createElement('script');
            dataElement.type = 'application/json';
            dataElement.id = 'cowriter-ajax-urls-data';
            dataElement.textContent = '  \n  {"tx_cowriter_chat": "/chat"}  \n  ';
            document.body.appendChild(dataElement);

            vi.resetModules();
            globalThis.TYPO3 = TYPO3Mock;

            const { loadCowriterUrls } = await import(
                '../../Resources/Public/JavaScript/Ckeditor/UrlLoader.js'
            );
            loadCowriterUrls();

            expect(TYPO3Mock.settings.ajaxUrls.tx_cowriter_chat).toBe('/chat');
        });

        it('should be idempotent when called multiple times', async () => {
            const dataElement = document.createElement('script');
            dataElement.type = 'application/json';
            dataElement.id = 'cowriter-ajax-urls-data';
            dataElement.textContent = JSON.stringify({
                tx_cowriter_chat: '/chat',
                tx_cowriter_complete: '/complete',
            });
            document.body.appendChild(dataElement);

            vi.resetModules();
            globalThis.TYPO3 = TYPO3Mock;

            const { loadCowriterUrls } = await import(
                '../../Resources/Public/JavaScript/Ckeditor/UrlLoader.js'
            );

            // Call twice
            loadCowriterUrls();
            loadCowriterUrls();

            // Should still have correct values
            expect(TYPO3Mock.settings.ajaxUrls.tx_cowriter_chat).toBe('/chat');
            expect(TYPO3Mock.settings.ajaxUrls.tx_cowriter_complete).toBe('/complete');
        });

        it('should not set URL for allowed key missing from data', async () => {
            const dataElement = document.createElement('script');
            dataElement.type = 'application/json';
            dataElement.id = 'cowriter-ajax-urls-data';
            dataElement.textContent = JSON.stringify({
                tx_cowriter_chat: '/chat',
                // tx_cowriter_complete intentionally omitted
            });
            document.body.appendChild(dataElement);

            vi.resetModules();
            globalThis.TYPO3 = TYPO3Mock;

            const { loadCowriterUrls } = await import(
                '../../Resources/Public/JavaScript/Ckeditor/UrlLoader.js'
            );
            loadCowriterUrls();

            expect(TYPO3Mock.settings.ajaxUrls.tx_cowriter_chat).toBe('/chat');
            expect(TYPO3Mock.settings.ajaxUrls.tx_cowriter_complete).toBeUndefined();
        });
    });
});
