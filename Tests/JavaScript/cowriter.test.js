/**
 * Tests for Cowriter CKEditor plugin.
 *
 * CKEditor bundle is mocked via vitest.config.js alias.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';

describe('Cowriter Plugin', () => {
    let Cowriter;

    beforeEach(async () => {
        vi.resetModules();
        const module = await import('../../Resources/Public/JavaScript/Ckeditor/cowriter.js');
        Cowriter = module.Cowriter;
    });

    describe('static properties', () => {
        it('should have pluginName set to "cowriter"', () => {
            expect(Cowriter.pluginName).toBe('cowriter');
        });
    });

    describe('_sanitizeContent', () => {
        let plugin;

        beforeEach(() => {
            plugin = new Cowriter();
        });

        it('should return empty string for null input', () => {
            expect(plugin._sanitizeContent(null)).toBe('');
        });

        it('should return empty string for undefined input', () => {
            expect(plugin._sanitizeContent(undefined)).toBe('');
        });

        it('should return empty string for non-string input', () => {
            expect(plugin._sanitizeContent(123)).toBe('');
            expect(plugin._sanitizeContent({})).toBe('');
            expect(plugin._sanitizeContent([])).toBe('');
        });

        it('should return empty string for empty string input', () => {
            expect(plugin._sanitizeContent('')).toBe('');
        });

        it('should remove simple HTML tags', () => {
            expect(plugin._sanitizeContent('<p>Hello</p>')).toBe('Hello');
            expect(plugin._sanitizeContent('<div>World</div>')).toBe('World');
        });

        it('should remove self-closing tags', () => {
            expect(plugin._sanitizeContent('Hello<br/>World')).toBe('HelloWorld');
            expect(plugin._sanitizeContent('Line<br />break')).toBe('Linebreak');
        });

        it('should remove tags with attributes', () => {
            expect(plugin._sanitizeContent('<a href="http://example.com">Link</a>')).toBe('Link');
            expect(plugin._sanitizeContent('<div class="test" id="main">Content</div>')).toBe(
                'Content'
            );
        });

        it('should remove nested tags', () => {
            expect(plugin._sanitizeContent('<div><p><span>Nested</span></p></div>')).toBe('Nested');
        });

        it('should remove script tags', () => {
            expect(plugin._sanitizeContent('<script>alert("xss")</script>')).toBe('alert("xss")');
        });

        it('should handle mixed content', () => {
            const input = 'Hello <b>bold</b> and <i>italic</i> text';
            expect(plugin._sanitizeContent(input)).toBe('Hello bold and italic text');
        });

        it('should preserve text without tags', () => {
            expect(plugin._sanitizeContent('Plain text without tags')).toBe(
                'Plain text without tags'
            );
        });

        it('should handle angle brackets in content', () => {
            // Numeric comparisons are preserved (no letter after <)
            expect(plugin._sanitizeContent('5 < 10 > 3')).toBe('5 < 10 > 3');
            // Tag-like patterns starting with letter are stripped
            // This is intentional for security - err on side of removing potential tags
            expect(plugin._sanitizeContent('a<b && c>d')).toBe('ad');
        });

        it('should handle malformed tags safely', () => {
            expect(plugin._sanitizeContent('<div<span>test</span>')).toBe('test');
            // Double angle brackets: outer < not part of tag, inner <div> stripped
            expect(plugin._sanitizeContent('<<div>>test</div>')).toBe('<>test');
        });

        it('should have iteration limit to prevent infinite loops', () => {
            // Create content that could cause many iterations
            const nested = '<'.repeat(50) + 'a'.repeat(50) + '>'.repeat(50);
            // Should complete without hanging
            const result = plugin._sanitizeContent(nested);
            expect(typeof result).toBe('string');
        });
    });

    describe('_sanitizeContent edge cases', () => {
        let plugin;

        beforeEach(() => {
            plugin = new Cowriter();
        });

        it('should handle content with only opening tags (no closing)', () => {
            const result = plugin._sanitizeContent('<div><p>unclosed');
            expect(result).toBe('unclosed');
        });

        it('should handle self-referencing nested tags', () => {
            const result = plugin._sanitizeContent('<div><div>inner</div></div>');
            expect(result).toBe('inner');
        });

        it('should complete within iteration limit for pathological input', () => {
            // Create input that strips one tag per iteration
            const nested = '<a>'.repeat(100) + 'core' + '</a>'.repeat(100);
            const start = performance.now();
            const result = plugin._sanitizeContent(nested);
            const duration = performance.now() - start;

            expect(result).toBe('core');
            // Should complete quickly (under 100ms)
            expect(duration).toBeLessThan(100);
        });

        it('should handle empty tags', () => {
            expect(plugin._sanitizeContent('<br><hr><img src="x">')).toBe('');
        });

        it('should handle tags with newlines in attributes', () => {
            const input = '<div\nclass="test"\nid="main">content</div>';
            expect(plugin._sanitizeContent(input)).toBe('content');
        });

        it('should preserve plain text with angle brackets in math', () => {
            expect(plugin._sanitizeContent('2 < 3 and 5 > 4')).toBe('2 < 3 and 5 > 4');
        });
    });

    describe('button execute handler', () => {
        let plugin;
        let mockEditor;
        let buttonCallback;
        let capturedButton;
        let mockDialogShow;

        beforeEach(async () => {
            vi.resetModules();

            mockDialogShow = vi.fn().mockResolvedValue({ content: 'AI response' });

            // Mock AIService and CowriterDialog before importing cowriter
            vi.doMock('../../Resources/Public/JavaScript/Ckeditor/AIService.js', () => ({
                AIService: class MockAIService {},
            }));
            vi.doMock('../../Resources/Public/JavaScript/Ckeditor/CowriterDialog.js', () => ({
                CowriterDialog: class MockCowriterDialog {
                    constructor() {
                        this.show = mockDialogShow;
                    }
                },
            }));

            const module = await import('../../Resources/Public/JavaScript/Ckeditor/cowriter.js');
            Cowriter = module.Cowriter;

            mockEditor = {
                model: {
                    document: {
                        selection: {
                            getRanges: vi.fn().mockReturnValue([]),
                            getFirstPosition: vi.fn().mockReturnValue({}),
                        },
                    },
                    change: vi.fn((callback) => callback(mockEditor._writer)),
                },
                _writer: {
                    remove: vi.fn(),
                    insert: vi.fn(),
                },
                view: {},
                ui: {
                    componentFactory: {
                        add: vi.fn((name, callback) => {
                            buttonCallback = callback;
                        }),
                    },
                },
                getData: vi.fn().mockReturnValue('<p>Full editor content</p>'),
            };

            plugin = new Cowriter();
            plugin.editor = mockEditor;
            await plugin.init();
            capturedButton = buttonCallback();
        });

        afterEach(() => {
            vi.doUnmock('../../Resources/Public/JavaScript/Ckeditor/AIService.js');
            vi.doUnmock('../../Resources/Public/JavaScript/Ckeditor/CowriterDialog.js');
        });

        it('should do nothing when _isProcessing is true', async () => {
            plugin._isProcessing = true;
            await capturedButton.fire('execute');
            expect(mockEditor.model.document.selection.getRanges).not.toHaveBeenCalled();
        });

        it('should open dialog even without text selection', async () => {
            mockEditor.model.document.selection.getRanges.mockReturnValue([
                { getItems: () => [{ data: '' }] },
            ]);
            await capturedButton.fire('execute');

            expect(mockDialogShow).toHaveBeenCalledWith('', '<p>Full editor content</p>');
        });

        it('should open dialog with selected text', async () => {
            const mockRange = {
                getItems: () => [{ data: 'write about cats' }],
            };
            mockEditor.model.document.selection.getRanges.mockReturnValue([mockRange]);

            await capturedButton.fire('execute');

            expect(mockDialogShow).toHaveBeenCalledWith(
                'write about cats',
                '<p>Full editor content</p>',
            );
        });

        it('should insert sanitized dialog result into editor', async () => {
            const mockRange = {
                getItems: () => [{ data: 'test prompt' }],
            };
            mockEditor.model.document.selection.getRanges.mockReturnValue([mockRange]);

            await capturedButton.fire('execute');

            expect(mockEditor._writer.remove).toHaveBeenCalledWith(mockRange);
            expect(mockEditor._writer.insert).toHaveBeenCalledWith('AI response', {});
        });

        it('should sanitize HTML from dialog result', async () => {
            mockDialogShow.mockResolvedValue({
                content: '<script>alert(1)</script>safe text',
            });
            const mockRange = {
                getItems: () => [{ data: 'prompt' }],
            };
            mockEditor.model.document.selection.getRanges.mockReturnValue([mockRange]);

            await capturedButton.fire('execute');

            expect(mockEditor._writer.insert).toHaveBeenCalledWith(
                expect.stringContaining('safe text'),
                expect.anything(),
            );
            expect(mockEditor._writer.insert).toHaveBeenCalledWith(
                expect.not.stringContaining('<script>'),
                expect.anything(),
            );
        });

        it('should not insert when dialog is cancelled', async () => {
            mockDialogShow.mockRejectedValue(new Error('User cancelled'));
            const mockRange = {
                getItems: () => [{ data: 'prompt' }],
            };
            mockEditor.model.document.selection.getRanges.mockReturnValue([mockRange]);

            await capturedButton.fire('execute');

            expect(mockEditor.model.change).not.toHaveBeenCalled();
        });

        it('should reset _isProcessing after completion', async () => {
            const mockRange = {
                getItems: () => [{ data: 'prompt' }],
            };
            mockEditor.model.document.selection.getRanges.mockReturnValue([mockRange]);

            await capturedButton.fire('execute');
            expect(plugin._isProcessing).toBe(false);
        });

        it('should reset _isProcessing after cancel', async () => {
            mockDialogShow.mockRejectedValue(new Error('User cancelled'));
            const mockRange = {
                getItems: () => [{ data: 'prompt' }],
            };
            mockEditor.model.document.selection.getRanges.mockReturnValue([mockRange]);

            await capturedButton.fire('execute');
            expect(plugin._isProcessing).toBe(false);
        });

        it('should handle null insertPosition gracefully', async () => {
            const mockRange = {
                getItems: () => [{ data: 'prompt' }],
            };
            mockEditor.model.document.selection.getRanges.mockReturnValue([mockRange]);
            mockEditor.model.document.selection.getFirstPosition.mockReturnValue(null);

            await capturedButton.fire('execute');

            expect(mockEditor._writer.remove).toHaveBeenCalled();
            // insert should not be called when position is null
            expect(mockEditor._writer.insert).not.toHaveBeenCalled();
        });

        it('should handle empty content from dialog result', async () => {
            mockDialogShow.mockResolvedValue({ content: '' });
            const mockRange = {
                getItems: () => [{ data: 'prompt' }],
            };
            mockEditor.model.document.selection.getRanges.mockReturnValue([mockRange]);

            await capturedButton.fire('execute');

            // Empty content means no insert
            expect(mockEditor.model.change).not.toHaveBeenCalled();
        });

        it('should use first range item with data from multiple ranges', async () => {
            const range1 = { getItems: () => [{ data: '' }] };
            const range2 = { getItems: () => [{ data: 'found in range 2' }] };
            mockEditor.model.document.selection.getRanges.mockReturnValue([range1, range2]);

            await capturedButton.fire('execute');

            expect(mockDialogShow).toHaveBeenCalledWith(
                'found in range 2',
                '<p>Full editor content</p>',
            );
        });

        it('should not remove range when no selection exists', async () => {
            mockEditor.model.document.selection.getRanges.mockReturnValue([
                { getItems: () => [{}] },
            ]);

            await capturedButton.fire('execute');

            expect(mockEditor._writer.remove).not.toHaveBeenCalled();
            expect(mockEditor._writer.insert).toHaveBeenCalledWith('AI response', {});
        });
    });

    describe('init', () => {
        it('should register button with editor UI', async () => {
            const addedComponents = {};
            const mockEditor = {
                model: {
                    document: {
                        selection: {},
                    },
                    change: vi.fn(),
                },
                view: {},
                ui: {
                    componentFactory: {
                        add: vi.fn((name, callback) => {
                            addedComponents[name] = callback;
                        }),
                    },
                },
            };

            const plugin = new Cowriter();
            plugin.editor = mockEditor;
            await plugin.init();

            expect(mockEditor.ui.componentFactory.add).toHaveBeenCalledWith(
                'cowriter',
                expect.any(Function)
            );
        });

        it('should create button with correct properties', async () => {
            let createdButton = null;
            const mockEditor = {
                model: {
                    document: { selection: {} },
                    change: vi.fn(),
                },
                view: {},
                ui: {
                    componentFactory: {
                        add: vi.fn((name, callback) => {
                            createdButton = callback();
                        }),
                    },
                },
            };

            const plugin = new Cowriter();
            plugin.editor = mockEditor;
            await plugin.init();

            expect(createdButton.label).toBe('Cowriter - AI text completion');
            expect(createdButton.tooltip).toBe(true);
            expect(createdButton.icon).toContain('svg');
            expect(createdButton.icon).toContain('aria-hidden="true"');
        });
    });
});
