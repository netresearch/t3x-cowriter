// vim: ts=4 sw=4 expandtab colorcolumn=120
// @ts-check

import { Core, UI } from "@typo3/ckeditor5-bundle.js";
import { AIService } from "./AIService.js";

/**
 * Cowriter CKEditor plugin.
 *
 * Provides AI-powered text completion within the TYPO3 backend RTE.
 * Uses the nr-llm extension for LLM provider abstraction.
 */
export class Cowriter extends Core.Plugin {
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
     * Sanitize AI-generated content by removing HTML/XML tags
     * @param {string} content - The content to sanitize
     * @returns {string} - The sanitized content
     * @private
     */
    _sanitizeContent(content) {
        // Handle null, undefined, or non-string inputs
        if (!content || typeof content !== 'string') {
            return '';
        }

        // Remove HTML/XML tags from the content
        // Matches opening tags, closing tags, and self-closing tags
        // Note: This sanitizes AI-generated output (not user input) to remove
        // accidental HTML/XML tags. The content will also be processed by CKEditor's
        // own sanitization before being inserted into the DOM.
        let sanitized = content;
        let previousLength;
        let iterations = 0;
        const maxIterations = 100; // Defensive limit to prevent infinite loops

        // Run the replacement multiple times to handle any nested or overlapping tags
        do {
            previousLength = sanitized.length;
            sanitized = sanitized.replace(/<\/?[a-zA-Z][^>]*>/g, '');
            iterations++;
        } while (sanitized.length !== previousLength && iterations < maxIterations);

        return sanitized;
    }

    async init() {
        // Initialize the AIService (now uses TYPO3 AJAX backend)
        this._service = new AIService();

        const editor = this.editor,
            model = editor.model;

        // Button to add text at current text cursor position:
        editor.ui.componentFactory.add(Cowriter.pluginName, () => {
            const button = new UI.ButtonView();

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

                    const ranges = Array.from(selection.getRanges());
                    let promptRange, prompt;
                    for (const range of ranges) {
                        for (const item of range.getItems()) {
                            if (item.data !== undefined && item.data !== '') {
                                prompt = item.data;
                                promptRange = range;
                                break;
                            }
                        }
                        if (prompt !== undefined) break;
                    }

                    if (prompt === undefined || promptRange === undefined) return;

                    let content = "";
                    let errorMessage = "";

                    try {
                        // Use the complete endpoint via TYPO3 AJAX
                        const result = await this._service.complete(prompt, {});
                        const rawContent = result.content || '';
                        content = this._sanitizeContent(rawContent);
                    } catch (error) {
                        console.error('Cowriter error:', error);
                        // Error messages are inserted as text nodes by writer.insert(),
                        // so no HTML escaping is needed (CKEditor treats them as plain text)
                        errorMessage = `[Cowriter Error: ${error.message || 'Unknown error'}] `;
                    }

                    model.change((writer) => {
                        writer.remove(promptRange);
                        const insertPosition = selection.getFirstPosition();
                        if (!insertPosition) return;
                        writer.insert(errorMessage + content, insertPosition);
                    });
                } finally {
                    this._isProcessing = false;
                }
            });

            return button;
        });
    }
}
