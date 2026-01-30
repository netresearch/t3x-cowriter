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
            dataElement.textContent = JSON.stringify({ test_route: '/test' });
            document.body.appendChild(dataElement);

            vi.resetModules();

            const { loadCowriterUrls } = await import(
                '../../Resources/Public/JavaScript/Ckeditor/UrlLoader.js'
            );
            loadCowriterUrls();

            expect(globalThis.TYPO3.settings).toBeDefined();
            expect(globalThis.TYPO3.settings.ajaxUrls).toBeDefined();
            expect(globalThis.TYPO3.settings.ajaxUrls.test_route).toBe('/test');
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
            dataElement.textContent = JSON.stringify({ new_route: '/new' });
            document.body.appendChild(dataElement);

            vi.resetModules();
            globalThis.TYPO3 = TYPO3Mock;

            const { loadCowriterUrls } = await import(
                '../../Resources/Public/JavaScript/Ckeditor/UrlLoader.js'
            );
            loadCowriterUrls();

            expect(TYPO3Mock.settings.ajaxUrls.existing_route).toBe('/existing');
            expect(TYPO3Mock.settings.ajaxUrls.new_route).toBe('/new');
        });
    });
});
