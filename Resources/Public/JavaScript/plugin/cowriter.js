import {Core, UI} from "@typo3/ckeditor5-bundle.js";

export default class cowriter extends Core.Plugin {
    static pluginName = 'cowriter';

    init() {
        const editor = this.editor;
        const model = editor.model;
        const view = editor.view;

        // Register <wbr>:
        model.schema.register('wbrTag', {
            allowWhere: '$text',
            allowAttributesOf: '$text',
            isInline: true
        });

        editor.conversion.for('upcast')
            .elementToElement({
                model: 'wbrTag',
                view: 'wbr'
            });

        editor.conversion.for('dataDowncast')
            .elementToElement({
                model: 'wbrTag',
                view: (modelElement, {writer}) => writer.createEmptyElement('wbr')
            });

        // Wrap <wbr> with <span> element to highlight the element in the editing view:
        editor.conversion.for('editingDowncast')
            .elementToElement({
                model: 'wbrTag',
                view: (modelElement, {writer}) => writer.createContainerElement('span', {class: 'ck ck-wbr'}, writer.createEmptyElement('wbr'))
            });

        // Create button that allows to insert <wbr> at current text cursor position:
        editor.ui.componentFactory.add('cowriter', () => {
            const button = new UI.ButtonView();

            button.set({
                label: 'Cowriter',
                icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="512" height="512"><path d="M19,10H7V6h2c1.654,0,3-1.346,3-3s-1.346-3-3-3H3C1.346,0,0,1.346,0,3s1.346,3,3,3h2v4c-2.757,0-5,2.243-5,5v3H24v-3c0-2.757-2.243-5-5-5ZM6,15c-.552,0-1-.448-1-1s.448-1,1-1,1,.448,1,1-.448,1-1,1Zm4,0c-.552,0-1-.448-1-1s.448-1,1-1,1,.448,1,1-.448,1-1,1Zm4,0c-.552,0-1-.448-1-1s.448-1,1-1,1,.448,1,1-.448,1-1,1Zm4,0c-.552,0-1-.448-1-1s.448-1,1-1,1,.448,1,1-.448,1-1,1ZM.101,20H23.899c-.465,2.279-2.485,4-4.899,4H5c-2.414,0-4.435-1.721-4.899-4Z"/></svg>'
            });

            button.on('execute', () => {
                model.change(writer => {
                    const insertPosition = editor.model.document.selection.getFirstPosition();
                    writer.insert('Netresearch rockt', insertPosition);
                });
            });

            return button;
        });
    }
}
