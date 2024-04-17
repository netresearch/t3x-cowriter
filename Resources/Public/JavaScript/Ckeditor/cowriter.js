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

    async init() {
        this._service = new AIService(
            new AIServiceOptions(
                // TODO: Get API URL from configuration
                _cowriterConfig.apiUrl,
                // TODO: Get API token from configuration
                _cowriterConfig.apiToken,
            )
        );
        const availableModels = await this._service.fetchModels();

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
                // TODO: Open dialog
                const selection =editor.model.document.selection;

                const ranges = (Array.from(selection.getRanges()) ?? []);
                let promptRange, prompt;
                for (const range of ranges) {
                    for (const item of range.getItems()) {
                        prompt = item.data;
                        promptRange = range;

                        break;
                    }
                }

                if (prompt === undefined || promptRange === undefined) return;

                // TODO: Properly parse arguments or put these into a dropdown
                let preferredModel = null;
                if (prompt.startsWith('#cw:')) {
                    const split = prompt.split(/ +/g);
                    preferredModel = split[0].slice(4);
                }

                // TODO: Set loading state

                let aiModel = null;
                let warning = "";
                let content = "";
                // FIXME: Clean this up
                if (preferredModel !== null) {
                    if (availableModels.includes(preferredModel)) {
                        aiModel = preferredModel;
                    } else {
                        warning = `AI model "${preferredModel}" not available. `;
                        aiModel = availableModels[0] ?? null;
                    }
                } else if (availableModels.length >= 1) {
                    aiModel = availableModels[0];
                } else {
                    warning = "No AI model available.";
                }

                if (aiModel !== null) content = (await this._service.complete(prompt, { model: aiModel }))[0].content;

                model.change((writer) => {
                    writer.remove(promptRange);
                    const insertPosition = selection.getFirstPosition();
                    writer.insert(warning + content, insertPosition);

                    writer.setSelection(null);
                });
            });

            return button;
        });
    }
}
