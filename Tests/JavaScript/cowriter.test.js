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

        /**
         * Create a mock selection range.
         * @param {boolean} collapsed - Whether the range is collapsed (no selection)
         * @param {string} html - HTML content the selection should return via stringify
         */
        function mockSelection(collapsed, html = '') {
            const range = { isCollapsed: collapsed };
            mockEditor.model.document.selection.getFirstRange.mockReturnValue(range);
            mockEditor.model.getSelectedContent.mockReturnValue({ type: 'content' });
            mockEditor.data.stringify.mockReturnValue(html);
        }

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
                            getFirstRange: vi.fn().mockReturnValue({ isCollapsed: true }),
                            getFirstPosition: vi.fn().mockReturnValue({}),
                        },
                    },
                    change: vi.fn((callback) => callback(mockEditor._writer)),
                    insertContent: vi.fn(),
                    getSelectedContent: vi.fn().mockReturnValue({ type: 'content' }),
                },
                _writer: {
                    remove: vi.fn(),
                    insert: vi.fn(),
                    setSelection: vi.fn(),
                },
                data: {
                    processor: {
                        toView: vi.fn().mockReturnValue({ type: 'viewFragment' }),
                    },
                    toModel: vi.fn().mockReturnValue({ type: 'modelFragment' }),
                    stringify: vi.fn().mockReturnValue(''),
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
                sourceElement: null,
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
            expect(mockEditor.model.document.selection.getFirstRange).not.toHaveBeenCalled();
        });

        it('should open dialog even without text selection', async () => {
            mockSelection(true);
            await capturedButton.fire('execute');

            expect(mockDialogShow).toHaveBeenCalledWith('', '<p>Full editor content</p>', '', null);
        });

        it('should open dialog with selected HTML', async () => {
            mockSelection(false, '<p>write about <strong>cats</strong></p>');
            await capturedButton.fire('execute');

            expect(mockDialogShow).toHaveBeenCalledWith(
                '<p>write about <strong>cats</strong></p>',
                '<p>Full editor content</p>',
                '',
                null,
            );
        });

        it('should insert dialog result via CKEditor HTML pipeline', async () => {
            mockSelection(false, '<p>test prompt</p>');
            await capturedButton.fire('execute');

            // Content inserted via CKEditor's HTML processing pipeline
            expect(mockEditor.data.processor.toView).toHaveBeenCalledWith('AI response');
            expect(mockEditor.data.toModel).toHaveBeenCalledWith({ type: 'viewFragment' });
            expect(mockEditor.model.insertContent).toHaveBeenCalledWith({ type: 'modelFragment' });
        });

        it('should use CKEditor pipeline for HTML content', async () => {
            mockDialogShow.mockResolvedValue({
                content: '<p>Formatted <strong>response</strong></p>',
            });
            mockSelection(false, '<p>prompt</p>');

            await capturedButton.fire('execute');

            expect(mockEditor.data.processor.toView).toHaveBeenCalledWith(
                '<p>Formatted <strong>response</strong></p>',
            );
            expect(mockEditor.model.insertContent).toHaveBeenCalled();
        });

        it('should not insert when dialog is cancelled', async () => {
            mockDialogShow.mockRejectedValue(new Error('User cancelled'));
            mockSelection(false, '<p>prompt</p>');

            await capturedButton.fire('execute');

            expect(mockEditor.model.change).not.toHaveBeenCalled();
        });

        it('should reset _isProcessing after completion', async () => {
            mockSelection(false, '<p>prompt</p>');

            await capturedButton.fire('execute');
            expect(plugin._isProcessing).toBe(false);
        });

        it('should reset _isProcessing after cancel', async () => {
            mockDialogShow.mockRejectedValue(new Error('User cancelled'));
            mockSelection(false, '<p>prompt</p>');

            await capturedButton.fire('execute');
            expect(plugin._isProcessing).toBe(false);
        });

        it('should set selection to range before inserting replacement', async () => {
            const range = { isCollapsed: false };
            mockEditor.model.document.selection.getFirstRange.mockReturnValue(range);
            mockEditor.model.getSelectedContent.mockReturnValue({ type: 'content' });
            mockEditor.data.stringify.mockReturnValue('<p>prompt</p>');

            await capturedButton.fire('execute');

            // Selection set to original range so insertContent replaces it
            expect(mockEditor._writer.setSelection).toHaveBeenCalledWith(range);
            expect(mockEditor.model.insertContent).toHaveBeenCalled();
        });

        it('should handle empty content from dialog result', async () => {
            mockDialogShow.mockResolvedValue({ content: '' });
            mockSelection(false, '<p>prompt</p>');

            await capturedButton.fire('execute');

            // Empty content means no insert
            expect(mockEditor.model.change).not.toHaveBeenCalled();
        });

        it('should not set selection when no selection exists', async () => {
            mockSelection(true);

            await capturedButton.fire('execute');

            expect(mockEditor._writer.setSelection).not.toHaveBeenCalled();
            // Content still inserted via CKEditor pipeline
            expect(mockEditor.model.insertContent).toHaveBeenCalled();
        });

        it('should pass record context to dialog', async () => {
            // Create a textarea to extract context from
            const textarea = document.createElement('textarea');
            textarea.name = 'data[tt_content][99][bodytext]';
            const wrapper = document.createElement('div');
            wrapper.appendChild(textarea);
            mockEditor.sourceElement = wrapper;

            mockSelection(false, '<p>selected</p>');

            await capturedButton.fire('execute');

            expect(mockDialogShow).toHaveBeenCalledWith(
                '<p>selected</p>',
                '<p>Full editor content</p>',
                '',
                { table: 'tt_content', uid: 99, field: 'bodytext' },
            );
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

    describe('_getRecordContext', () => {
        it('should parse record context from textarea name attribute', () => {
            const plugin = new Cowriter();

            const textarea = document.createElement('textarea');
            textarea.name = 'data[tt_content][123][bodytext]';
            const wrapper = document.createElement('div');
            wrapper.appendChild(textarea);

            plugin.editor = {
                sourceElement: wrapper,
            };

            const context = plugin._getRecordContext();
            expect(context).toEqual({
                table: 'tt_content',
                uid: 123,
                field: 'bodytext',
            });
        });

        it('should return null when no textarea found', () => {
            const plugin = new Cowriter();
            plugin.editor = {
                sourceElement: document.createElement('div'),
            };

            const context = plugin._getRecordContext();
            expect(context).toBeNull();
        });

        it('should return null when name pattern does not match', () => {
            const plugin = new Cowriter();
            const textarea = document.createElement('textarea');
            textarea.name = 'some-other-format';
            const wrapper = document.createElement('div');
            wrapper.appendChild(textarea);

            plugin.editor = { sourceElement: wrapper };

            const context = plugin._getRecordContext();
            expect(context).toBeNull();
        });

        it('should handle sourceElement being the textarea itself', () => {
            const plugin = new Cowriter();
            const textarea = document.createElement('textarea');
            textarea.name = 'data[pages][5][description]';

            plugin.editor = { sourceElement: textarea };

            const context = plugin._getRecordContext();
            expect(context).toEqual({
                table: 'pages',
                uid: 5,
                field: 'description',
            });
        });

        it('should return null when sourceElement is null', () => {
            const plugin = new Cowriter();
            plugin.editor = { sourceElement: null };

            const context = plugin._getRecordContext();
            expect(context).toBeNull();
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
