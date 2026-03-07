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
        let componentCallbacks;
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
                            if (!componentCallbacks) componentCallbacks = {};
                            componentCallbacks[name] = callback;
                        }),
                    },
                },
                getData: vi.fn().mockReturnValue('<p>Full editor content</p>'),
                sourceElement: null,
            };

            plugin = new Cowriter();
            plugin.editor = mockEditor;
            await plugin.init();
            capturedButton = componentCallbacks['cowriter']();
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

        it('should log non-cancel errors to console', async () => {
            const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
            mockDialogShow.mockRejectedValue(new Error('Network failure'));
            mockSelection(false, '<p>prompt</p>');

            await capturedButton.fire('execute');

            expect(consoleSpy).toHaveBeenCalledWith('[Cowriter]', expect.any(Error));
            consoleSpy.mockRestore();
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

        it('should find textarea via Strategy 2 (walk up from editable DOM root)', () => {
            const plugin = new Cowriter();

            // Create a DOM structure where textarea is an ancestor-sibling of editableRoot
            const grandparent = document.createElement('div');
            const textarea = document.createElement('textarea');
            textarea.name = 'data[tx_news_domain_model_news][42][bodytext]';
            grandparent.appendChild(textarea);

            const editorWrapper = document.createElement('div');
            grandparent.appendChild(editorWrapper);
            const editableRoot = document.createElement('div');
            editorWrapper.appendChild(editableRoot);

            plugin.editor = {
                sourceElement: document.createElement('div'), // no textarea child
                editing: { view: { getDomRoot: () => editableRoot } },
            };

            const context = plugin._getRecordContext();
            expect(context).toEqual({
                table: 'tx_news_domain_model_news',
                uid: 42,
                field: 'bodytext',
            });
        });

        it('should find textarea via Strategy 3 (search all textareas in document)', () => {
            const plugin = new Cowriter();

            // Put a textarea in the document body
            const textarea = document.createElement('textarea');
            textarea.name = 'data[pages][7][abstract]';
            document.body.appendChild(textarea);

            plugin.editor = {
                sourceElement: document.createElement('div'),
                editing: { view: { getDomRoot: () => null } },
            };

            const context = plugin._getRecordContext();
            expect(context).toEqual({
                table: 'pages',
                uid: 7,
                field: 'abstract',
            });

            // Cleanup
            textarea.remove();
        });

        it('should find record context via Strategy 4 (URL parsing)', () => {
            const plugin = new Cowriter();

            // Mock window.location.search
            const origSearch = window.location.search;
            Object.defineProperty(window, 'location', {
                value: { ...window.location, search: '?edit[tt_content][55]=edit' },
                writable: true,
                configurable: true,
            });

            plugin.editor = {
                sourceElement: null,
                editing: { view: { getDomRoot: () => null } },
            };

            const context = plugin._getRecordContext();
            expect(context).toEqual({
                table: 'tt_content',
                uid: 55,
                field: 'bodytext',
            });

            // Restore
            Object.defineProperty(window, 'location', {
                value: { ...window.location, search: origSearch },
                writable: true,
                configurable: true,
            });
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
            const createdButtons = {};
            const mockEditor = {
                model: {
                    document: { selection: {} },
                    change: vi.fn(),
                },
                view: {},
                ui: {
                    componentFactory: {
                        add: vi.fn((name, callback) => {
                            createdButtons[name] = callback();
                        }),
                    },
                },
            };

            const plugin = new Cowriter();
            plugin.editor = mockEditor;
            await plugin.init();

            const cowriterButton = createdButtons['cowriter'];
            expect(cowriterButton.label).toBe('Cowriter - AI text completion');
            expect(cowriterButton.tooltip).toBe(true);
            expect(cowriterButton.icon).toContain('svg');
            expect(cowriterButton.icon).toContain('aria-hidden="true"');
        });

        it('should register all four toolbar components', async () => {
            const addedComponents = {};
            const mockEditor = {
                model: {
                    document: { selection: {} },
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

            expect(addedComponents).toHaveProperty('cowriter');
            expect(addedComponents).toHaveProperty('cowriterVision');
            expect(addedComponents).toHaveProperty('cowriterTranslate');
            expect(addedComponents).toHaveProperty('cowriterTemplates');
        });
    });

    describe('cowriterVision button', () => {
        let plugin;
        let mockEditor;
        let componentCallbacks;
        let mockAnalyzeImage;

        beforeEach(async () => {
            vi.resetModules();

            mockAnalyzeImage = vi.fn().mockResolvedValue({ success: true, altText: 'A cat' });

            vi.doMock('../../Resources/Public/JavaScript/Ckeditor/AIService.js', () => ({
                AIService: class MockAIService {
                    analyzeImage = mockAnalyzeImage;
                },
            }));
            vi.doMock('../../Resources/Public/JavaScript/Ckeditor/CowriterDialog.js', () => ({
                CowriterDialog: class MockCowriterDialog {},
            }));

            const module = await import('../../Resources/Public/JavaScript/Ckeditor/cowriter.js');
            Cowriter = module.Cowriter;

            componentCallbacks = {};
            mockEditor = {
                model: {
                    document: { selection: { getFirstRange: vi.fn(), getFirstPosition: vi.fn(), getSelectedElement: vi.fn().mockReturnValue(null) } },
                    change: vi.fn((cb) => cb(mockEditor._writer)),
                    insertContent: vi.fn(),
                    getSelectedContent: vi.fn(),
                },
                _writer: { remove: vi.fn(), insert: vi.fn(), insertText: vi.fn(), setSelection: vi.fn(), setAttribute: vi.fn() },
                editing: { view: { document: { selection: { getSelectedElement: vi.fn().mockReturnValue(null) } } } },
                data: {
                    processor: { toView: vi.fn().mockReturnValue({ type: 'viewFragment' }) },
                    toModel: vi.fn().mockReturnValue({ type: 'modelFragment' }),
                    stringify: vi.fn().mockReturnValue(''),
                },
                plugins: { has: vi.fn().mockReturnValue(false) },
                config: { get: vi.fn().mockReturnValue(undefined) },
                view: {},
                ui: {
                    componentFactory: {
                        add: vi.fn((name, callback) => { componentCallbacks[name] = callback; }),
                    },
                },
                getData: vi.fn().mockReturnValue(''),
                sourceElement: null,
            };

            plugin = new Cowriter();
            plugin.editor = mockEditor;
            await plugin.init();
        });

        afterEach(() => {
            vi.doUnmock('../../Resources/Public/JavaScript/Ckeditor/AIService.js');
            vi.doUnmock('../../Resources/Public/JavaScript/Ckeditor/CowriterDialog.js');
        });

        it('should create vision button with correct label', () => {
            const button = componentCallbacks['cowriterVision']();
            expect(button.label).toBe('Cowriter - Generate alt text');
        });

        it('should call analyzeImage and set alt attribute on selected image', async () => {
            const selectedImage = {
                is: vi.fn((_, name) => name === 'imageBlock'),
                getAttribute: vi.fn().mockReturnValue('https://example.com/img.jpg'),
            };
            mockEditor.model.document.selection.getSelectedElement = vi.fn().mockReturnValue(selectedImage);

            const button = componentCallbacks['cowriterVision']();
            await button.fire('execute');

            expect(mockAnalyzeImage).toHaveBeenCalledWith('https://example.com/img.jpg');
            expect(mockEditor.model.change).toHaveBeenCalled();
            expect(mockEditor._writer.setAttribute).toHaveBeenCalledWith('alt', 'A cat', selectedImage);
        });

        it('should do nothing when no image is selected', async () => {
            mockEditor.model.document.selection.getSelectedElement = vi.fn().mockReturnValue(null);
            mockEditor.editing = { view: { document: { selection: { getSelectedElement: vi.fn().mockReturnValue(null) } } } };

            const button = componentCallbacks['cowriterVision']();
            await button.fire('execute');

            expect(mockAnalyzeImage).not.toHaveBeenCalled();
        });

        it('should not execute when _isProcessing is true', async () => {
            plugin._isProcessing = true;
            const button = componentCallbacks['cowriterVision']();
            await button.fire('execute');
            expect(mockAnalyzeImage).not.toHaveBeenCalled();
        });

        it('should log error when analyzeImage throws', async () => {
            const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
            mockAnalyzeImage.mockRejectedValue(new Error('Vision API failed'));

            const selectedImage = {
                is: vi.fn((_, name) => name === 'imageBlock'),
                getAttribute: vi.fn().mockReturnValue('https://example.com/img.jpg'),
            };
            mockEditor.model.document.selection.getSelectedElement = vi.fn().mockReturnValue(selectedImage);

            const button = componentCallbacks['cowriterVision']();
            await button.fire('execute');

            expect(consoleSpy).toHaveBeenCalledWith('[Cowriter Vision]', expect.any(Error));
            expect(plugin._isProcessing).toBe(false);
            consoleSpy.mockRestore();
        });

        it('should detect image from editing view fallback (img element)', async () => {
            // No image in model selection
            mockEditor.model.document.selection.getSelectedElement = vi.fn().mockReturnValue(null);

            // But editing view has an img element
            const viewImg = {
                is: vi.fn((_, name) => name === 'img'),
                getAttribute: vi.fn().mockReturnValue('https://example.com/fallback.jpg'),
                getChild: vi.fn(),
            };
            mockEditor.editing = {
                view: {
                    document: {
                        selection: {
                            getSelectedElement: vi.fn().mockReturnValue(viewImg),
                        },
                    },
                },
            };

            const button = componentCallbacks['cowriterVision']();
            await button.fire('execute');

            expect(mockAnalyzeImage).toHaveBeenCalledWith('https://example.com/fallback.jpg');
        });

        it('should detect image from editing view fallback (child img element)', async () => {
            mockEditor.model.document.selection.getSelectedElement = vi.fn().mockReturnValue(null);

            const childImg = {
                is: vi.fn((_, name) => name === 'img'),
                getAttribute: vi.fn().mockReturnValue('https://example.com/child.jpg'),
            };
            const viewWrapper = {
                is: vi.fn(() => false),
                getAttribute: vi.fn().mockReturnValue(''),
                getChild: vi.fn((idx) => idx === 0 ? childImg : null),
            };
            mockEditor.editing = {
                view: {
                    document: {
                        selection: {
                            getSelectedElement: vi.fn().mockReturnValue(viewWrapper),
                        },
                    },
                },
            };

            const button = componentCallbacks['cowriterVision']();
            await button.fire('execute');

            expect(mockAnalyzeImage).toHaveBeenCalledWith('https://example.com/child.jpg');
        });

        it('should use insertText when selected element is not an image', async () => {
            // Model selection returns a non-image element
            const nonImageElement = {
                is: vi.fn(() => false),
                getAttribute: vi.fn().mockReturnValue(''),
            };
            mockEditor.model.document.selection.getSelectedElement = vi.fn().mockReturnValue(nonImageElement);

            // But editing view has an img (for URL detection)
            const viewImg = {
                is: vi.fn((_, name) => name === 'img'),
                getAttribute: vi.fn().mockReturnValue('https://example.com/img.jpg'),
                getChild: vi.fn(),
            };
            mockEditor.editing = {
                view: {
                    document: {
                        selection: {
                            getSelectedElement: vi.fn().mockReturnValue(viewImg),
                        },
                    },
                },
            };

            // Mock insertText on writer
            mockEditor._writer.insertText = vi.fn();
            const mockPosition = { offset: 0 };
            mockEditor.model.document.selection.getFirstPosition = vi.fn().mockReturnValue(mockPosition);

            const button = componentCallbacks['cowriterVision']();
            await button.fire('execute');

            expect(mockAnalyzeImage).toHaveBeenCalledWith('https://example.com/img.jpg');
            // Since selectedElement.is('element', 'imageBlock') returns false, insertText is used
            expect(mockEditor._writer.insertText).toHaveBeenCalledWith('A cat', mockPosition);
        });
    });

    describe('cowriterTranslate dropdown', () => {
        let plugin;
        let mockEditor;
        let componentCallbacks;
        let mockTranslate;

        beforeEach(async () => {
            vi.resetModules();

            mockTranslate = vi.fn().mockResolvedValue({ success: true, translation: 'Hallo Welt' });

            vi.doMock('../../Resources/Public/JavaScript/Ckeditor/AIService.js', () => ({
                AIService: class MockAIService {
                    translate = mockTranslate;
                },
            }));
            vi.doMock('../../Resources/Public/JavaScript/Ckeditor/CowriterDialog.js', () => ({
                CowriterDialog: class MockCowriterDialog {},
            }));

            const module = await import('../../Resources/Public/JavaScript/Ckeditor/cowriter.js');
            Cowriter = module.Cowriter;

            componentCallbacks = {};
            mockEditor = {
                model: {
                    document: {
                        selection: {
                            getFirstRange: vi.fn().mockReturnValue({ isCollapsed: false }),
                            getFirstPosition: vi.fn().mockReturnValue({}),
                        },
                    },
                    change: vi.fn((cb) => cb(mockEditor._writer)),
                    insertContent: vi.fn(),
                    getSelectedContent: vi.fn().mockReturnValue({ type: 'content' }),
                },
                _writer: { remove: vi.fn(), insert: vi.fn(), insertText: vi.fn(), setSelection: vi.fn() },
                data: {
                    processor: { toView: vi.fn().mockReturnValue({ type: 'viewFragment' }) },
                    toModel: vi.fn().mockReturnValue({ type: 'modelFragment' }),
                    stringify: vi.fn().mockReturnValue('<p>Hello World</p>'),
                },
                plugins: { has: vi.fn().mockReturnValue(false) },
                config: { get: vi.fn().mockReturnValue(undefined) },
                view: {},
                ui: {
                    componentFactory: {
                        add: vi.fn((name, callback) => { componentCallbacks[name] = callback; }),
                    },
                },
                getData: vi.fn().mockReturnValue(''),
                sourceElement: null,
            };

            plugin = new Cowriter();
            plugin.editor = mockEditor;
            await plugin.init();
        });

        afterEach(() => {
            vi.doUnmock('../../Resources/Public/JavaScript/Ckeditor/AIService.js');
            vi.doUnmock('../../Resources/Public/JavaScript/Ckeditor/CowriterDialog.js');
        });

        it('should create translate dropdown with correct label', () => {
            const dropdown = componentCallbacks['cowriterTranslate']();
            expect(dropdown.buttonView.label).toBe('Cowriter - Translate');
        });

        it('should translate selected text on execute', async () => {
            const dropdown = componentCallbacks['cowriterTranslate']();
            // Simulate selecting German from dropdown
            await dropdown.fire('execute', { source: { languageCode: 'de' } });

            expect(mockTranslate).toHaveBeenCalledWith('<p>Hello World</p>', 'de');
            expect(mockEditor.model.insertContent).toHaveBeenCalled();
        });

        it('should not translate when no text is selected', async () => {
            mockEditor.model.document.selection.getFirstRange.mockReturnValue({ isCollapsed: true });
            mockEditor.data.stringify.mockReturnValue('');

            const dropdown = componentCallbacks['cowriterTranslate']();
            await dropdown.fire('execute', { source: { languageCode: 'fr' } });

            expect(mockTranslate).not.toHaveBeenCalled();
        });

        it('should not execute when _isProcessing is true', async () => {
            plugin._isProcessing = true;
            const dropdown = componentCallbacks['cowriterTranslate']();
            await dropdown.fire('execute', { source: { languageCode: 'de' } });
            expect(mockTranslate).not.toHaveBeenCalled();
        });

        it('should log error when translate throws', async () => {
            const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
            mockTranslate.mockRejectedValue(new Error('Translation API failed'));

            const dropdown = componentCallbacks['cowriterTranslate']();
            await dropdown.fire('execute', { source: { languageCode: 'de' } });

            expect(consoleSpy).toHaveBeenCalledWith('[Cowriter Translate]', expect.any(Error));
            expect(plugin._isProcessing).toBe(false);
            consoleSpy.mockRestore();
        });
    });

    describe('cowriterTemplates dropdown', () => {
        let plugin;
        let mockEditor;
        let componentCallbacks;
        let mockGetTemplates;
        let mockDialogShow;

        beforeEach(async () => {
            vi.resetModules();

            mockGetTemplates = vi.fn().mockResolvedValue({
                success: true,
                templates: [{ identifier: 'improve', name: 'Improve', description: '' }],
            });
            mockDialogShow = vi.fn().mockResolvedValue({ content: 'Improved text' });

            vi.doMock('../../Resources/Public/JavaScript/Ckeditor/AIService.js', () => ({
                AIService: class MockAIService {
                    getTemplates = mockGetTemplates;
                },
            }));
            vi.doMock('../../Resources/Public/JavaScript/Ckeditor/CowriterDialog.js', () => ({
                CowriterDialog: class MockCowriterDialog {
                    constructor() { this.show = mockDialogShow; }
                },
            }));

            const module = await import('../../Resources/Public/JavaScript/Ckeditor/cowriter.js');
            Cowriter = module.Cowriter;

            componentCallbacks = {};
            mockEditor = {
                model: {
                    document: { selection: { getFirstRange: vi.fn(), getFirstPosition: vi.fn() } },
                    change: vi.fn((cb) => cb(mockEditor._writer)),
                    insertContent: vi.fn(),
                    getSelectedContent: vi.fn(),
                },
                _writer: { remove: vi.fn(), insert: vi.fn(), insertText: vi.fn(), setSelection: vi.fn() },
                data: {
                    processor: { toView: vi.fn().mockReturnValue({ type: 'viewFragment' }) },
                    toModel: vi.fn().mockReturnValue({ type: 'modelFragment' }),
                    stringify: vi.fn().mockReturnValue(''),
                },
                plugins: { has: vi.fn().mockReturnValue(false) },
                config: { get: vi.fn().mockReturnValue(undefined) },
                view: {},
                ui: {
                    componentFactory: {
                        add: vi.fn((name, callback) => { componentCallbacks[name] = callback; }),
                    },
                },
                getData: vi.fn().mockReturnValue('<p>Full content</p>'),
                sourceElement: null,
            };

            plugin = new Cowriter();
            plugin.editor = mockEditor;
            await plugin.init();
        });

        afterEach(() => {
            vi.doUnmock('../../Resources/Public/JavaScript/Ckeditor/AIService.js');
            vi.doUnmock('../../Resources/Public/JavaScript/Ckeditor/CowriterDialog.js');
        });

        it('should create templates dropdown with correct label', () => {
            const dropdown = componentCallbacks['cowriterTemplates']();
            expect(dropdown.buttonView.label).toBe('Cowriter - Templates');
        });

        it('should load templates on dropdown open', async () => {
            const dropdown = componentCallbacks['cowriterTemplates']();
            dropdown.isOpen = true;
            await dropdown.fire('change:isOpen');

            expect(mockGetTemplates).toHaveBeenCalled();
        });

        it('should not load templates when dropdown closes', async () => {
            const dropdown = componentCallbacks['cowriterTemplates']();
            dropdown.isOpen = false;
            await dropdown.fire('change:isOpen');

            expect(mockGetTemplates).not.toHaveBeenCalled();
        });

        it('should open dialog with template on execute', async () => {
            const dropdown = componentCallbacks['cowriterTemplates']();
            await dropdown.fire('execute', { source: { templateIdentifier: 'improve' } });

            expect(mockDialogShow).toHaveBeenCalledWith(
                '', '<p>Full content</p>', '', null,
                { templateIdentifier: 'improve' },
            );
            expect(mockEditor.model.insertContent).toHaveBeenCalled();
        });

        it('should not insert content when dialog is cancelled', async () => {
            mockDialogShow.mockRejectedValue(new Error('User cancelled'));

            const dropdown = componentCallbacks['cowriterTemplates']();
            await dropdown.fire('execute', { source: { templateIdentifier: 'improve' } });

            expect(mockEditor.model.insertContent).not.toHaveBeenCalled();
        });

        it('should not execute when _isProcessing is true', async () => {
            plugin._isProcessing = true;
            const dropdown = componentCallbacks['cowriterTemplates']();
            await dropdown.fire('execute', { source: { templateIdentifier: 'improve' } });
            expect(mockDialogShow).not.toHaveBeenCalled();
        });

        it('should log error when getTemplates throws', async () => {
            const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
            mockGetTemplates.mockRejectedValue(new Error('Templates API failed'));

            const dropdown = componentCallbacks['cowriterTemplates']();
            dropdown.isOpen = true;
            await dropdown.fire('change:isOpen');

            expect(consoleSpy).toHaveBeenCalledWith('[Cowriter Templates]', expect.any(Error));
            consoleSpy.mockRestore();
        });

        it('should log non-cancel errors from template execute', async () => {
            const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
            mockDialogShow.mockRejectedValue(new Error('Network error'));

            const dropdown = componentCallbacks['cowriterTemplates']();
            await dropdown.fire('execute', { source: { templateIdentifier: 'improve' } });

            expect(consoleSpy).toHaveBeenCalledWith('[Cowriter Templates]', expect.any(Error));
            expect(plugin._isProcessing).toBe(false);
            consoleSpy.mockRestore();
        });
    });
});
