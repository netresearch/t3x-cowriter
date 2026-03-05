// vim: ts=4 sw=4 expandtab colorcolumn=120
// @ts-check

import { Plugin } from "@ckeditor/ckeditor5-core";
import { ButtonView, createDropdown, addListToDropdown, Model, Collection } from "@ckeditor/ckeditor5-ui";
import { AIService } from "@netresearch/t3_cowriter/AIService";
import { CowriterDialog } from "@netresearch/t3_cowriter/CowriterDialog";

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
        const el = this.editor.sourceElement;
        if (!el) return null;

        // The source element may be the textarea itself or a wrapper containing it
        const textarea = el.tagName === 'TEXTAREA' ? el : el.querySelector('textarea');
        if (!textarea?.name) return null;

        const match = textarea.name.match(/^data\[(\w+)]\[(\d+)]\[(\w+)]$/);
        if (!match) return null;

        return {
            table: match[1],
            uid: parseInt(match[2], 10),
            field: match[3],
        };
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
                    const imageUrl = prompt('Enter image URL for alt text generation:');
                    if (!imageUrl) return;

                    const result = await this._service.analyzeImage(imageUrl);
                    if (result?.success && result.altText) {
                        editor.model.change(writer => {
                            writer.insertText(result.altText, editor.model.document.selection.getFirstPosition());
                        });
                    }
                } catch (error) {
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
                const itemModel = new Model({
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
                    const selection = editor.model.document.selection;
                    const selectedRange = selection.getFirstRange();

                    let selectedText = '';
                    if (selectedRange && !selectedRange.isCollapsed) {
                        const content = model.getSelectedContent(selection);
                        selectedText = editor.data.stringify(content);
                    }

                    if (!selectedText) {
                        console.warn('[Cowriter Translate] No text selected for translation');
                        return;
                    }

                    const result = await this._service.translate(selectedText, langCode);
                    if (result?.success && result.translation) {
                        const viewFragment = editor.data.processor.toView(result.translation);
                        const modelFragment = editor.data.toModel(viewFragment);

                        model.change((writer) => {
                            writer.setSelection(selectedRange);
                            editor.model.insertContent(modelFragment);
                        });
                    }
                } catch (error) {
                    console.error('[Cowriter Translate]', error);
                } finally {
                    this._isProcessing = false;
                }
            });

            return dropdown;
        });

        // Templates dropdown — apply prompt template presets
        editor.ui.componentFactory.add('cowriterTemplates', (locale) => {
            const dropdown = createDropdown(locale);

            dropdown.buttonView.set({
                label: 'Cowriter - Templates',
                tooltip: true,
                withText: false,
                icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zM6 20V4h5v5h5v11H6z"/></svg>',
            });

            // Load templates when dropdown opens
            dropdown.on('change:isOpen', async () => {
                if (!dropdown.isOpen) return;

                try {
                    const result = await this._service.getTemplates();
                    if (!result?.success || !result.templates?.length) return;

                    const items = new Collection();
                    for (const tmpl of result.templates) {
                        const itemModel = new Model({
                            label: tmpl.name,
                            templateIdentifier: tmpl.identifier,
                            templateDescription: tmpl.description || '',
                            withText: true,
                        });
                        items.add({ type: 'button', model: itemModel });
                    }

                    addListToDropdown(dropdown, items);
                } catch (error) {
                    console.error('[Cowriter Templates]', error);
                }
            });

            dropdown.on('execute', async (event) => {
                if (this._isProcessing) return;
                this._isProcessing = true;

                try {
                    const selectedText = editor.getData();
                    const caps = this._getEditorCapabilities();
                    const recordContext = this._getRecordContext();

                    // Open the cowriter dialog with the template pre-selected
                    const dialog = new CowriterDialog(this._service);
                    const dialogResult = await dialog.show(
                        '', selectedText, caps, recordContext,
                        { templateIdentifier: event.source.templateIdentifier },
                    );

                    if (dialogResult?.content) {
                        const viewFragment = editor.data.processor.toView(dialogResult.content);
                        const modelFragment = editor.data.toModel(viewFragment);
                        model.change(() => {
                            editor.model.insertContent(modelFragment);
                        });
                    }
                } catch (error) {
                    if (error?.message && error.message !== 'User cancelled') {
                        console.error('[Cowriter Templates]', error);
                    }
                } finally {
                    this._isProcessing = false;
                }
            });

            return dropdown;
        });
    }
}
