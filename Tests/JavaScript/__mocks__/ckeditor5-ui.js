/**
 * Mock for @ckeditor/ckeditor5-ui
 *
 * Provides a minimal ButtonView stub for testing CKEditor 5 plugins.
 */
export class ButtonView {
    constructor() {
        this._handlers = {};
        this.label = '';
        this.tooltip = false;
        this.icon = '';
    }

    set(options) {
        Object.assign(this, options);
    }

    on(event, handler) {
        this._handlers[event] = handler;
    }

    fire(event) {
        if (this._handlers[event]) {
            return this._handlers[event]();
        }
    }
}
