/**
 * Mock for @typo3/ckeditor5-bundle.js
 *
 * Provides minimal CKEditor 5 stubs for testing TYPO3 CKEditor plugins.
 */
export const Core = {
    Plugin: class Plugin {
        static pluginName = '';
        editor = null;

        init() {
            return Promise.resolve();
        }
    },
};

export const UI = {
    ButtonView: class ButtonView {
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
    },
};
