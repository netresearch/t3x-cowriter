// vim: ts=4 sw=4 expandtab colorcolumn=120
// @ts-check

import { Plugin } from "@ckeditor/ckeditor5-core";
import { ButtonView } from "@ckeditor/ckeditor5-ui";
import { AIService } from "@netresearch/t3_cowriter/AIService";
import { CowriterDialog } from "@netresearch/t3_cowriter/CowriterDialog";

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

                    // Extract selected text from editor ranges
                    const ranges = Array.from(selection.getRanges());
                    let promptRange, selectedText;
                    for (const range of ranges) {
                        for (const item of range.getItems()) {
                            if (item.data !== undefined && item.data !== '') {
                                selectedText = item.data;
                                promptRange = range;
                                break;
                            }
                        }
                        if (selectedText !== undefined) break;
                    }

                    const fullContent = editor.getData();
                    const caps = this._getEditorCapabilities();

                    // Show the task dialog
                    const dialog = new CowriterDialog(this._service);
                    const result = await dialog.show(selectedText || '', fullContent, caps);

                    if (result?.content) {
                        model.change((writer) => {
                            if (promptRange) {
                                writer.remove(promptRange);
                            }
                        });

                        // Use CKEditor's HTML processing pipeline (handles schema/sanitization)
                        const viewFragment = editor.data.processor.toView(result.content);
                        const modelFragment = editor.data.toModel(viewFragment);
                        editor.model.insertContent(modelFragment);
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
    }
}
