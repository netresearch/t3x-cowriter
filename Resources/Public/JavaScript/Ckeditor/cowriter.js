// vim: ts=4 sw=4 expandtab colorcolumn=120
// @ts-check

import { Plugin } from "@ckeditor/ckeditor5-core";
import { ButtonView, createDropdown, addListToDropdown, ViewModel } from "@ckeditor/ckeditor5-ui";
import { Collection } from "@ckeditor/ckeditor5-utils";
import { AIService } from "@netresearch/t3_cowriter/AIService";
import { CowriterDialog } from "@netresearch/t3_cowriter/CowriterDialog";
import Notification from "@typo3/backend/notification.js";

/**
 * Target languages for translation dropdown.
 * @type {ReadonlyArray<{code: string, label: string}>}
 */
const TRANSLATION_LANGUAGES = [
    { code: 'de', label: 'German' },
    { code: 'en', label: 'English' },
    { code: 'fr', label: 'French' },
    { code: 'es', label: 'Spanish' },
    { code: 'it', label: 'Italian' },
    { code: 'nl', label: 'Dutch' },
    { code: 'pt', label: 'Portuguese' },
    { code: 'pl', label: 'Polish' },
    { code: 'ja', label: 'Japanese' },
    { code: 'zh', label: 'Chinese' },
];

/**
 * Cowriter CKEditor plugin.
 *
 * Provides AI-powered text completion within the TYPO3 backend RTE.
 * Uses the nr-llm extension for LLM provider abstraction.
 */
export class Cowriter extends Plugin {
    static pluginName = 'cowriter';

    /**
     * @type {AIService}
     * @private
     */
    _service;

    /**
     * Guards against concurrent LLM requests from double-clicks.
     * @type {boolean}
     * @private
     */
    _isProcessing = false;

    /**
     * Detect available CKEditor formatting capabilities at runtime.
     *
     * @returns {string} Comma-separated list of available formatting features
     * @private
     */
    _getEditorCapabilities() {
        const editor = this.editor;
        const caps = [];

        const pluginMap = [
            ['HeadingEditing', 'headings (h2, h3, h4)'],
            ['BoldEditing', 'bold'],
            ['ItalicEditing', 'italic'],
            ['UnderlineEditing', 'underline'],
            ['StrikethroughEditing', 'strikethrough'],
            ['ListEditing', 'bulleted and numbered lists'],
            ['TableEditing', 'tables with headers and merged cells'],
            ['BlockQuoteEditing', 'blockquotes'],
            ['AlignmentEditing', 'text alignment (left, center, right, justify)'],
            ['LinkEditing', 'hyperlinks'],
            ['HorizontalLineEditing', 'horizontal rules (<hr>)'],
            ['HighlightEditing', 'text highlighting'],
            ['FontColorEditing', 'font colors'],
            ['FontBackgroundColorEditing', 'background colors'],
            ['SubscriptEditing', 'subscript'],
            ['SuperscriptEditing', 'superscript'],
        ];

        for (const [plugin, label] of pluginMap) {
            if (editor.plugins.has(plugin)) {
                caps.push(label);
            }
        }

        const styleConfig = editor.config.get('style');
        if (styleConfig?.definitions?.length) {
            const styleNames = styleConfig.definitions.map((d) => d.name);
            caps.push(`styles: ${styleNames.join(', ')}`);
        }

        return caps.join(', ');
    }

    /**
     * Extract record context (table, uid, field) from the CKEditor source element's DOM.
     *
     * TYPO3 FormEngine names textareas as: data[table][uid][field]
     * e.g. data[tt_content][123][bodytext]
     *
     * @returns {{table: string, uid: number, field: string}|null}
     * @private
     */
    _getRecordContext() {
        const pattern = /^data\[(\w+)]\[(\d+)]\[(\w+)]$/;
        const urlPattern = /^edit\[(\w+)]\[(\d+)]$/;

        // Strategy 1: CKEditor 5 sourceElement (textarea itself or a wrapper containing one)
        const el = this.editor.sourceElement;
        if (el) {
            const textarea = el.tagName === 'TEXTAREA' ? el : el.querySelector('textarea[name^="data["]');
            if (textarea?.name) {
                const match = textarea.name.match(pattern);
                if (match) return { table: match[1], uid: parseInt(match[2], 10), field: match[3] };
            }
        }

        // Strategy 2: Walk up from CKEditor's editable DOM root to find FormEngine textarea
        // In TYPO3 v13/v14, the textarea may be a sibling/ancestor of the editable area,
        // not a child of sourceElement (especially with web component wrappers)
        const editableRoot = this.editor.editing?.view?.getDomRoot();
        if (editableRoot) {
            let parent = editableRoot.parentElement;
            while (parent && parent !== document.body) {
                const ta = parent.querySelector('textarea[name^="data["]');
                if (ta?.name) {
                    const match = ta.name.match(pattern);
                    if (match) return { table: match[1], uid: parseInt(match[2], 10), field: match[3] };
                }
                parent = parent.parentElement;
            }
        }

        // Strategy 3: Search all textareas in the document (fallback for unusual DOM layouts)
        for (const ta of document.querySelectorAll('textarea[name^="data["]')) {
            const match = ta.name.match(pattern);
            if (match) return { table: match[1], uid: parseInt(match[2], 10), field: match[3] };
        }

        // Strategy 4: Parse TYPO3 edit URL (e.g. ?edit[tt_content][123]=edit)
        try {
            const searchStr = window.location.search
                || window.parent?.location?.search || '';
            const params = new URLSearchParams(searchStr);
            for (const [key] of params) {
                const urlMatch = key.match(urlPattern);
                if (urlMatch) {
                    return {
                        table: urlMatch[1],
                        uid: parseInt(urlMatch[2], 10),
                        field: 'bodytext',
                    };
                }
            }
        } catch {
            // URL parsing or cross-origin access failed
        }

        return null;
    }

    async init() {
        // Initialize the AIService (now uses TYPO3 AJAX backend)
        this._service = new AIService();

        const editor = this.editor,
            model = editor.model;

        // Button to add text at current text cursor position:
        editor.ui.componentFactory.add(Cowriter.pluginName, () => {
            const button = new ButtonView();

            button.set({
                label: 'Cowriter - AI text completion',
                tooltip: true,
                icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="512" height="512" aria-hidden="true"><path d="M19,10H7V6h2c1.654,0,3-1.346,3-3s-1.346-3-3-3H3C1.346,0,0,1.346,0,3s1.346,3,3,3h2v4c-2.757,0-5,2.243-5,5v3H24v-3c0-2.757-2.243-5-5-5ZM6,15c-.552,0-1-.448-1-1s.448-1,1-1,1,.448,1,1-.448,1-1,1Zm4,0c-.552,0-1-.448-1-1s.448-1,1-1,1,.448,1,1-.448,1-1,1Zm4,0c-.552,0-1-.448-1-1s.448-1,1-1,1,.448,1,1-.448,1-1,1Zm4,0c-.552,0-1-.448-1-1s.448-1,1-1,1,.448,1,1-.448,1-1,1ZM.101,20H23.899c-.465,2.279-2.485,4-4.899,4H5c-2.414,0-4.435-1.721-4.899-4Z"/></svg>'
            });

            button.on('execute', async () => {
                if (this._isProcessing) return;
                this._isProcessing = true;

                try {
                    const selection = editor.model.document.selection;
                    const selectedRange = selection.getFirstRange();

                    // Get the selected content as HTML via CKEditor's data pipeline
                    let selectedText = '';
                    if (selectedRange && !selectedRange.isCollapsed) {
                        const content = model.getSelectedContent(selection);
                        selectedText = editor.data.stringify(content);
                    }

                    const fullContent = editor.getData();
                    const caps = this._getEditorCapabilities();
                    const recordContext = this._getRecordContext();

                    // Show the task dialog
                    const dialog = new CowriterDialog(this._service);
                    const result = await dialog.show(selectedText || '', fullContent, caps, recordContext);

                    if (result?.content) {
                        // Use CKEditor's HTML processing pipeline (handles schema/sanitization)
                        const viewFragment = editor.data.processor.toView(result.content);
                        const modelFragment = editor.data.toModel(viewFragment);

                        model.change((writer) => {
                            if (selectedRange && !selectedRange.isCollapsed) {
                                // Replace the selected range with the LLM output
                                writer.setSelection(selectedRange);
                            }
                            editor.model.insertContent(modelFragment);
                        });
                    }
                } catch (error) {
                    // User cancelled the dialog — no action needed.
                    // Log unexpected errors for debugging (cancellations have message 'User cancelled').
                    if (error?.message && error.message !== 'User cancelled') {
                        console.error('[Cowriter]', error);
                    }
                } finally {
                    this._isProcessing = false;
                }
            });

            return button;
        });

        // Vision button — analyze image and generate alt text
        editor.ui.componentFactory.add('cowriterVision', () => {
            const button = new ButtonView();

            button.set({
                label: 'Cowriter - Generate alt text',
                tooltip: true,
                icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zm-5-7l-3 3.72L9 13l-3 4h12l-4-5z"/></svg>',
            });

            button.on('execute', async () => {
                if (this._isProcessing) return;
                this._isProcessing = true;

                try {
                    // Detect selected image in the editor
                    const selectedElement = editor.model.document.selection.getSelectedElement();
                    let imageUrl = '';

                    if (selectedElement && (selectedElement.is('element', 'imageBlock') || selectedElement.is('element', 'imageInline'))) {
                        imageUrl = selectedElement.getAttribute('src') || '';
                    }

                    if (!imageUrl) {
                        // Fallback: check editing view for selected image
                        const viewSelection = editor.editing.view.document.selection;
                        const viewElement = viewSelection.getSelectedElement();
                        if (viewElement) {
                            const img = viewElement.is('element', 'img')
                                ? viewElement
                                : viewElement.getChild?.(0)?.is?.('element', 'img') ? viewElement.getChild(0) : null;
                            if (img) {
                                imageUrl = img.getAttribute('src') || '';
                            }
                        }
                    }

                    if (!imageUrl) {
                        Notification.warning('No image selected', 'Click on an image in the editor first.', 5);
                        return;
                    }

                    Notification.info('Analyzing image...', 'Generating alt text', 15);

                    const result = await this._service.analyzeImage(imageUrl);
                    if (result?.success && result.altText) {
                        editor.model.change(writer => {
                            // If the selected element is an image, set its alt attribute
                            if (selectedElement && (selectedElement.is('element', 'imageBlock') || selectedElement.is('element', 'imageInline'))) {
                                writer.setAttribute('alt', result.altText, selectedElement);
                            } else {
                                writer.insertText(result.altText, editor.model.document.selection.getFirstPosition());
                            }
                        });
                        Notification.success('Alt text generated', result.altText.substring(0, 80), 3);
                    } else {
                        Notification.error('Alt text generation failed', result?.error || 'Unknown error', 5);
                    }
                } catch (error) {
                    Notification.error('Alt text generation failed', error?.message || 'Unknown error', 5);
                    console.error('[Cowriter Vision]', error);
                } finally {
                    this._isProcessing = false;
                }
            });

            return button;
        });

        // Translation dropdown — translate selected text
        editor.ui.componentFactory.add('cowriterTranslate', (locale) => {
            const dropdown = createDropdown(locale);
            const items = new Collection();

            for (const lang of TRANSLATION_LANGUAGES) {
                const itemModel = new ViewModel({
                    label: lang.label,
                    languageCode: lang.code,
                    withText: true,
                });
                items.add({ type: 'button', model: itemModel });
            }

            addListToDropdown(dropdown, items);

            dropdown.buttonView.set({
                label: 'Cowriter - Translate',
                tooltip: true,
                withText: false,
                icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M12.87 15.07l-2.54-2.51.03-.03A17.52 17.52 0 0014.07 6H17V4h-7V2H8v2H1v2h11.17C11.5 7.92 10.44 9.75 9 11.35 8.07 10.32 7.3 9.19 6.69 8h-2c.73 1.63 1.73 3.17 2.98 4.56l-5.09 5.02L4 19l5-5 3.11 3.11.76-2.04zM18.5 10h-2L12 22h2l1.12-3h4.75L21 22h2l-4.5-12zm-2.62 7l1.62-4.33L19.12 17h-3.24z"/></svg>',
            });

            dropdown.on('execute', async (event) => {
                if (this._isProcessing) return;
                this._isProcessing = true;

                try {
                    const langCode = event.source.languageCode;
                    const langLabel = event.source.label || langCode;
                    const selection = editor.model.document.selection;
                    const selectedRange = selection.getFirstRange();

                    let selectedText = '';
                    if (selectedRange && !selectedRange.isCollapsed) {
                        const content = model.getSelectedContent(selection);
                        selectedText = editor.data.stringify(content);
                    }

                    if (!selectedText) {
                        Notification.warning('No text selected', 'Please select text in the editor before translating.', 5);
                        return;
                    }

                    Notification.info('Translating...', `Translating to ${langLabel}`, 15);

                    const result = await this._service.translate(selectedText, langCode);
                    if (result?.success && result.translation) {
                        const viewFragment = editor.data.processor.toView(result.translation);
                        const modelFragment = editor.data.toModel(viewFragment);

                        model.change((writer) => {
                            writer.setSelection(selectedRange);
                            editor.model.insertContent(modelFragment);
                        });

                        Notification.success('Translation complete', `Translated to ${langLabel}`, 3);
                    } else {
                        Notification.error('Translation failed', result?.error || 'Unknown error', 5);
                    }
                } catch (error) {
                    Notification.error('Translation failed', error?.message || 'Unknown error', 5);
                    console.error('[Cowriter Translate]', error);
                } finally {
                    this._isProcessing = false;
                }
            });

            return dropdown;
        });

        // Tasks dropdown — open dialog with a pre-selected task
        editor.ui.componentFactory.add('cowriterTemplates', (locale) => {
            const dropdown = createDropdown(locale);
            let tasksLoaded = false;
            let tasksLoading = false;

            dropdown.buttonView.set({
                label: 'Cowriter - Tasks',
                tooltip: true,
                withText: false,
                icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zM6 20V4h5v5h5v11H6z"/></svg>',
            });

            // Load tasks when dropdown opens (once per session, guarded against concurrent fetches)
            dropdown.on('change:isOpen', async () => {
                if (!dropdown.isOpen || tasksLoaded || tasksLoading) return;
                tasksLoading = true;

                try {
                    const result = await this._service.getTasks();
                    if (!result?.success || !result.tasks?.length) {
                        Notification.info('No tasks configured', 'Create tasks in the LLM module first.', 5);
                        return;
                    }

                    const items = new Collection();
                    for (const task of result.tasks) {
                        const itemModel = new ViewModel({
                            label: task.name,
                            taskUid: task.uid,
                            withText: true,
                        });
                        items.add({ type: 'button', model: itemModel });
                    }

                    addListToDropdown(dropdown, items);
                    tasksLoaded = true;
                } catch (error) {
                    Notification.error('Failed to load tasks', error?.message || 'Unknown error', 5);
                    console.error('[Cowriter Tasks]', error);
                } finally {
                    tasksLoading = false;
                }
            });

            dropdown.on('execute', async (event) => {
                if (this._isProcessing) return;
                this._isProcessing = true;

                try {
                    const taskUid = event.source.taskUid;
                    const selection = editor.model.document.selection;
                    const selectedRange = selection.getFirstRange();

                    let selectedText = '';
                    if (selectedRange && !selectedRange.isCollapsed) {
                        const content = model.getSelectedContent(selection);
                        selectedText = editor.data.stringify(content);
                    }

                    const fullContent = editor.getData();
                    const caps = this._getEditorCapabilities();
                    const recordContext = this._getRecordContext();

                    const dialog = new CowriterDialog(this._service);
                    const dialogResult = await dialog.show(
                        selectedText || '', fullContent, caps, recordContext, taskUid,
                    );

                    if (dialogResult?.content) {
                        const viewFragment = editor.data.processor.toView(dialogResult.content);
                        const modelFragment = editor.data.toModel(viewFragment);
                        model.change((writer) => {
                            if (selectedRange && !selectedRange.isCollapsed) {
                                writer.setSelection(selectedRange);
                            }
                            editor.model.insertContent(modelFragment);
                        });
                    }
                } catch (error) {
                    if (error?.message && error.message !== 'User cancelled') {
                        Notification.error('Task failed', error?.message || 'Unknown error', 5);
                        console.error('[Cowriter Tasks]', error);
                    }
                } finally {
                    this._isProcessing = false;
                }
            });

            return dropdown;
        });
    }
}
