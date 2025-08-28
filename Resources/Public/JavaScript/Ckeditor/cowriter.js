// vim: ts=4 sw=4 expandtab colorcolumn=120
// @ts-check

import { Core, UI } from "@typo3/ckeditor5-bundle.js";
import { AIService, AIServiceOptions } from "./AIService.js";

export class Cowriter extends Core.Plugin {
    static pluginName = 'cowriter';

    /**
     * @type {AIService}
     * @private
     */
    _service;

    /**
     * @type {string}
     * @private
     */
    _preferredModel;

    /**
     * @type {string[]}
     * @private
     */
    _availableModels = [];

    async init() {
        try {
            const config = globalThis._cowriterConfig || {};
            this._preferredModel = config.preferredModel || 'gpt-3.5-turbo';
            
            if (!config.apiUrl || !config.apiToken) {
                console.warn('[Cowriter] Missing API configuration. Please configure the extension in the Extension Manager.');
                return;
            }

            this._service = new AIService(
                new AIServiceOptions(
                    config.apiType || 'openai',
                    config.apiUrl,
                    config.apiToken,
                )
            );
            
            this._availableModels = await this._service.fetchModels().catch(() => {
                console.warn('[Cowriter] Failed to fetch available models');
                return [];
            });
        } catch (error) {
            console.error('[Cowriter] Failed to initialize:', error);
            return;
        }

        const editor = this.editor,
            model = editor.model,
            view = editor.view;

        // Button to add text at current text cursor position:
        editor.ui.componentFactory.add(Cowriter.pluginName, () => {
            const button = new UI.ButtonView();

            button.set({
                label: 'Cowriter',
                icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="512" height="512"><path d="M19,10H7V6h2c1.654,0,3-1.346,3-3s-1.346-3-3-3H3C1.346,0,0,1.346,0,3s1.346,3,3,3h2v4c-2.757,0-5,2.243-5,5v3H24v-3c0-2.757-2.243-5-5-5ZM6,15c-.552,0-1-.448-1-1s.448-1,1-1,1,.448,1,1-.448,1-1,1Zm4,0c-.552,0-1-.448-1-1s.448-1,1-1,1,.448,1,1-.448,1-1,1Zm4,0c-.552,0-1-.448-1-1s.448-1,1-1,1,.448,1,1-.448,1-1,1Zm4,0c-.552,0-1-.448-1-1s.448-1,1-1,1,.448,1,1-.448,1-1,1ZM.101,20H23.899c-.465,2.279-2.485,4-4.899,4H5c-2.414,0-4.435-1.721-4.899-4Z"/></svg>'
            });

            button.on('execute', async () => {
                if (!this._service) {
                    alert('[Cowriter] Not configured. Please check the extension settings.');
                    return;
                }

                const selection = editor.model.document.selection;
                const ranges = Array.from(selection.getRanges());
                
                if (!ranges.length) {
                    alert('[Cowriter] Please select some text or type a prompt.');
                    return;
                }

                let promptRange = null;
                let prompt = '';
                
                // Get selected text
                for (const range of ranges) {
                    for (const item of range.getItems()) {
                        if (item.data) {
                            prompt = item.data;
                            promptRange = range;
                            break;
                        }
                    }
                    if (prompt) break;
                }

                if (!prompt || !promptRange) {
                    alert('[Cowriter] Please select some text or type a prompt.');
                    return;
                }

                // Parse model preference from prompt
                let preferredModel = null;
                if (prompt.startsWith('#cw:')) {
                    const split = prompt.split(/ +/g);
                    preferredModel = split[0].slice(4);
                    prompt = split.slice(1).join(' ');
                }

                // Set button to loading state
                button.set('isEnabled', false);
                button.set('label', 'Generating...');

                try {
                    let aiModel = null;
                    let warning = "";
                    let content = "";
                    
                    // Determine which model to use
                    if (preferredModel !== null) {
                        if (this._availableModels.includes(preferredModel)) {
                            aiModel = preferredModel;
                        } else {
                            warning = `AI model "${preferredModel}" not available. Using ${this._availableModels[0] || 'default model'}. `;
                            aiModel = this._availableModels[0] || this._preferredModel;
                        }
                    } else if (this._availableModels.length >= 1) {
                        if (this._availableModels.includes(this._preferredModel)) {
                            aiModel = this._preferredModel;
                        } else {
                            aiModel = this._availableModels[0];
                        }
                    } else {
                        aiModel = this._preferredModel;
                    }

                    if (aiModel) {
                        const response = await this._service.complete(prompt, { model: aiModel });
                        content = response[0]?.content || 'No response generated.';
                    } else {
                        warning = "No AI model available. ";
                    }

                    // Replace the prompt with the generated content
                    model.change((writer) => {
                        writer.remove(promptRange);
                        const insertPosition = selection.getFirstPosition();
                        if (warning || content) {
                            writer.insertText(warning + content, insertPosition);
                        }
                        writer.setSelection(null);
                    });
                } catch (error) {
                    console.error('[Cowriter] Error generating content:', error);
                    alert('[Cowriter] Error generating content. Please check console for details.');
                } finally {
                    // Reset button state
                    button.set('isEnabled', true);
                    button.set('label', 'Cowriter');
                }
            });

            return button;
        });
    }
}
