/**
 * Tests for AIService module.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

describe('AIService', () => {
    let AIService;
    let TYPO3Mock;

    beforeEach(async () => {
        // Setup TYPO3 mock with routes
        TYPO3Mock = {
            settings: {
                ajaxUrls: {
                    tx_cowriter_chat: '/typo3/ajax/tx_cowriter_chat',
                    tx_cowriter_complete: '/typo3/ajax/tx_cowriter_complete',
                },
            },
        };
        globalThis.TYPO3 = TYPO3Mock;

        // Reset modules and re-import
        vi.resetModules();
        const module = await import('../../Resources/Public/JavaScript/Ckeditor/AIService.js');
        AIService = module.AIService;
    });

    afterEach(() => {
        delete globalThis.TYPO3;
        vi.restoreAllMocks();
    });

    describe('constructor', () => {
        it('should initialize routes from TYPO3.settings.ajaxUrls', () => {
            const service = new AIService();
            expect(service._routes.chat).toBe('/typo3/ajax/tx_cowriter_chat');
            expect(service._routes.complete).toBe('/typo3/ajax/tx_cowriter_complete');
        });

        it('should handle missing TYPO3 global', async () => {
            delete globalThis.TYPO3;
            vi.resetModules();
            const module = await import('../../Resources/Public/JavaScript/Ckeditor/AIService.js');
            const ServiceClass = module.AIService;

            const service = new ServiceClass();
            expect(service._routes.chat).toBeNull();
            expect(service._routes.complete).toBeNull();
        });

        it('should handle missing ajaxUrls', async () => {
            globalThis.TYPO3 = { settings: {} };
            vi.resetModules();
            const module = await import('../../Resources/Public/JavaScript/Ckeditor/AIService.js');
            const ServiceClass = module.AIService;

            const service = new ServiceClass();
            expect(service._routes.chat).toBeNull();
            expect(service._routes.complete).toBeNull();
        });
    });

    describe('_validateRoutes', () => {
        it('should throw when routes are not configured', async () => {
            delete globalThis.TYPO3;
            vi.resetModules();
            const module = await import('../../Resources/Public/JavaScript/Ckeditor/AIService.js');
            const ServiceClass = module.AIService;

            const service = new ServiceClass();
            expect(() => service._validateRoutes()).toThrow(
                'TYPO3 AJAX routes not configured. Ensure the cowriter extension is properly installed.'
            );
        });

        it('should not throw when routes are configured', () => {
            const service = new AIService();
            expect(() => service._validateRoutes()).not.toThrow();
        });
    });

    describe('complete', () => {
        it('should send POST request with prompt and options', async () => {
            const mockResponse = {
                success: true,
                content: 'Generated text',
                model: 'gpt-4',
            };

            globalThis.fetch = vi.fn().mockResolvedValue({
                ok: true,
                json: () => Promise.resolve(mockResponse),
            });

            const service = new AIService();
            const result = await service.complete('Hello', { maxTokens: 100 });

            expect(globalThis.fetch).toHaveBeenCalledWith('/typo3/ajax/tx_cowriter_complete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ prompt: 'Hello', options: { maxTokens: 100 } }),
            });
            expect(result).toEqual(mockResponse);
        });

        it('should throw error when response is not ok', async () => {
            globalThis.fetch = vi.fn().mockResolvedValue({
                ok: false,
                status: 500,
                json: () => Promise.resolve({ error: 'Server error' }),
            });

            const service = new AIService();
            await expect(service.complete('Hello')).rejects.toThrow('Server error');
        });

        it('should handle JSON parse error in error response', async () => {
            globalThis.fetch = vi.fn().mockResolvedValue({
                ok: false,
                status: 500,
                json: () => Promise.reject(new Error('Parse error')),
            });

            const service = new AIService();
            // Falls back to 'Unknown error' when JSON parsing fails
            await expect(service.complete('Hello')).rejects.toThrow('Unknown error');
        });

        it('should validate routes before making request', async () => {
            delete globalThis.TYPO3;
            vi.resetModules();
            const module = await import('../../Resources/Public/JavaScript/Ckeditor/AIService.js');
            const ServiceClass = module.AIService;

            const service = new ServiceClass();
            await expect(service.complete('Hello')).rejects.toThrow(
                'TYPO3 AJAX routes not configured'
            );
        });
    });

    describe('chat', () => {
        it('should send POST request with messages and options', async () => {
            const mockResponse = {
                content: 'Assistant response',
                role: 'assistant',
            };

            globalThis.fetch = vi.fn().mockResolvedValue({
                ok: true,
                json: () => Promise.resolve(mockResponse),
            });

            const service = new AIService();
            const messages = [
                { role: 'user', content: 'Hello' },
                { role: 'assistant', content: 'Hi there!' },
            ];
            const result = await service.chat(messages, { temperature: 0.7 });

            expect(globalThis.fetch).toHaveBeenCalledWith('/typo3/ajax/tx_cowriter_chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ messages, options: { temperature: 0.7 } }),
            });
            expect(result).toEqual(mockResponse);
        });

        it('should throw error when response is not ok', async () => {
            globalThis.fetch = vi.fn().mockResolvedValue({
                ok: false,
                status: 400,
                json: () => Promise.resolve({ error: 'Bad request' }),
            });

            const service = new AIService();
            await expect(service.chat([{ role: 'user', content: 'Hi' }])).rejects.toThrow(
                'Bad request'
            );
        });

        it('should validate routes before making request', async () => {
            delete globalThis.TYPO3;
            vi.resetModules();
            const module = await import('../../Resources/Public/JavaScript/Ckeditor/AIService.js');
            const ServiceClass = module.AIService;

            const service = new ServiceClass();
            await expect(service.chat([{ role: 'user', content: 'Hi' }])).rejects.toThrow(
                'TYPO3 AJAX routes not configured'
            );
        });
    });

    describe('legacy exports', () => {
        it('should export APIType for backward compatibility', async () => {
            const module = await import('../../Resources/Public/JavaScript/Ckeditor/AIService.js');
            expect(module.APIType).toEqual({
                OPENAI: 'openai',
                OLLAMA: 'ollama',
            });
        });

        it('should export AIServiceOptions with deprecation warning', async () => {
            const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
            const module = await import('../../Resources/Public/JavaScript/Ckeditor/AIService.js');

            new module.AIServiceOptions();

            expect(warnSpy).toHaveBeenCalledWith(
                'AIServiceOptions is deprecated. Configuration is now handled by the nr-llm extension.'
            );
            warnSpy.mockRestore();
        });

        it('should have no-op validate method on AIServiceOptions', async () => {
            const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
            const module = await import('../../Resources/Public/JavaScript/Ckeditor/AIService.js');

            const options = new module.AIServiceOptions();
            expect(() => options.validate()).not.toThrow();

            warnSpy.mockRestore();
        });
    });
});
