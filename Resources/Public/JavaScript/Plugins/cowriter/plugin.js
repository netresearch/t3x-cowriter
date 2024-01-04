import {Core, UI} from "@typo3/ckeditor5-bundle.js";

export default class Cowriter extends Core.Plugin {
    static pluginName = 'cowriter';

    init() {
        const editor = this.editor;
        const model = editor.model;
        const view = editor.view;

        // Register <wbr>:
        model.schema.register('wbrTag', {
            allowWhere: '$text',
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
        editor.ui.componentFactory.add('Cowriter', () => {
            const button = new UI.ButtonView();

            button.set({
                label: 'Cowriter',
                icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" xml:space="preserve"><path d="M6,4 L6,5 L4,5 L4,6 L6,6 L6,7 L4,7 L4,8 L6,8 L6,9 L3,9 L3,4 L6,4 Z M7,7 L7,8 L6,8 L6,7 L7,7 Z M7,5 L7,6 L6,6 L6,5 L7,5 Z M11,4 L11,5 L9,5 L9,6 L11,6 L11,7 L12,7 L12,9 L11,9 L11,8 L10,8 L10,7 L9,7 L9,9 L8,9 L8,4 L11,4 Z M12,5 L12,6 L11,6 L11,5 L12,5 Z M14,8 L16,8 L16,9 L13,9 L13,4 L16,4 L16,5 L14,5 L14,6 L15,6 L15,7 L14,7 L14,8 Z M6,11 L6,16 L5,16 L5,14 L4,14 L4,16 L3,16 L3,11 L6,11 Z M5,13 L5,12 L4,12 L4,13 L5,13 Z M8,11 L8,13 L9,13 L9,14 L8,14 L8,16 L7,16 L7,11 L8,11 Z M11,15 L11,16 L10,16 L10,15 L11,15 Z M14,11 L14,13 L16,13 L16,11 L17,11 L17,14 L14,14 L14,16 L13,16 L13,15 L12,15 L12,14 L11,14 L11,13 L12,13 L12,12 L13,12 L13,11 L14,11 Z M10,14 L10,15 L9,15 L9,14 L10,14 Z M10,12 L10,13 L9,13 L9,12 L10,12 Z M11,11 L11,12 L10,12 L10,11 L11,11 Z"/></svg>'
            });

            button.on('execute', () => {
                model.change(writer => {
                    const insertPosition = editor.model.document.selection.getFirstPosition();
                    const wbrTag = writer.createElement('wbrTag');
                    writer.insert(wbrTag, insertPosition);
                });
            });

            return button;
        });
    }
}
