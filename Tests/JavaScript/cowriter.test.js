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

    describe('no _sanitizeContent method', () => {
        it('should not have _sanitizeContent (removed in favor of CKEditor pipeline)', () => {
            const plugin = new Cowriter();
            expect(plugin._sanitizeContent).toBeUndefined();
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
                    insertContent: vi.fn(),
                },
                _writer: {
                    remove: vi.fn(),
                    insert: vi.fn(),
                },
                data: {
                    processor: {
                        toView: vi.fn().mockReturnValue({ type: 'viewFragment' }),
                    },
                    toModel: vi.fn().mockReturnValue({ type: 'modelFragment' }),
                },
                plugins: { has: vi.fn().mockReturnValue(false) },
                config: { get: vi.fn().mockReturnValue(undefined) },
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

            expect(mockDialogShow).toHaveBeenCalledWith('', '<p>Full editor content</p>', '');
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
                '',
            );
        });

        it('should insert dialog result via CKEditor HTML pipeline', async () => {
            const mockRange = {
                getItems: () => [{ data: 'test prompt' }],
            };
            mockEditor.model.document.selection.getRanges.mockReturnValue([mockRange]);

            await capturedButton.fire('execute');

            // Selection range should be removed first
            expect(mockEditor._writer.remove).toHaveBeenCalledWith(mockRange);
            // Content inserted via CKEditor's HTML processing pipeline
            expect(mockEditor.data.processor.toView).toHaveBeenCalledWith('AI response');
            expect(mockEditor.data.toModel).toHaveBeenCalledWith({ type: 'viewFragment' });
            expect(mockEditor.model.insertContent).toHaveBeenCalledWith({ type: 'modelFragment' });
        });

        it('should use CKEditor pipeline for HTML content', async () => {
            mockDialogShow.mockResolvedValue({
                content: '<p>Formatted <strong>response</strong></p>',
            });
            const mockRange = {
                getItems: () => [{ data: 'prompt' }],
            };
            mockEditor.model.document.selection.getRanges.mockReturnValue([mockRange]);

            await capturedButton.fire('execute');

            expect(mockEditor.data.processor.toView).toHaveBeenCalledWith(
                '<p>Formatted <strong>response</strong></p>',
            );
            expect(mockEditor.model.insertContent).toHaveBeenCalled();
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

        it('should remove selection and insert via CKEditor pipeline', async () => {
            const mockRange = {
                getItems: () => [{ data: 'prompt' }],
            };
            mockEditor.model.document.selection.getRanges.mockReturnValue([mockRange]);

            await capturedButton.fire('execute');

            expect(mockEditor._writer.remove).toHaveBeenCalled();
            // Insertion handled by CKEditor's insertContent (handles position internally)
            expect(mockEditor.model.insertContent).toHaveBeenCalled();
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
                '',
            );
        });

        it('should not remove range when no selection exists', async () => {
            mockEditor.model.document.selection.getRanges.mockReturnValue([
                { getItems: () => [{}] },
            ]);

            await capturedButton.fire('execute');

            expect(mockEditor._writer.remove).not.toHaveBeenCalled();
            // Content still inserted via CKEditor pipeline
            expect(mockEditor.model.insertContent).toHaveBeenCalled();
        });
    });

    describe('_getEditorCapabilities', () => {
        it('should detect available plugins and return capabilities string', () => {
            const plugin = new Cowriter();
            plugin.editor = {
                plugins: {
                    has: vi.fn((name) => ['HeadingEditing', 'BoldEditing', 'ItalicEditing',
                        'ListEditing', 'TableEditing', 'BlockQuoteEditing'].includes(name)),
                },
                config: { get: vi.fn().mockReturnValue(undefined) },
            };

            const caps = plugin._getEditorCapabilities();
            expect(caps).toContain('headings');
            expect(caps).toContain('bold');
            expect(caps).toContain('tables');
            expect(caps).toContain('lists');
            expect(caps).not.toContain('highlight');
        });

        it('should include custom style definitions when configured', () => {
            const plugin = new Cowriter();
            plugin.editor = {
                plugins: { has: vi.fn().mockReturnValue(false) },
                config: {
                    get: vi.fn((key) => {
                        if (key === 'style') {
                            return {
                                definitions: [
                                    { name: 'Lead', element: 'p', classes: ['lead'] },
                                    { name: 'Muted', element: 'span', classes: ['text-muted'] },
                                ],
                            };
                        }
                        return undefined;
                    }),
                },
            };

            const caps = plugin._getEditorCapabilities();
            expect(caps).toContain('Lead');
            expect(caps).toContain('Muted');
        });

        it('should return empty string when no plugins available', () => {
            const plugin = new Cowriter();
            plugin.editor = {
                plugins: { has: vi.fn().mockReturnValue(false) },
                config: { get: vi.fn().mockReturnValue(undefined) },
            };

            const caps = plugin._getEditorCapabilities();
            expect(caps).toBe('');
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
