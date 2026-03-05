/**
 * Mock for @ckeditor/ckeditor5-ui
 *
 * Provides minimal stubs for testing CKEditor 5 plugins.
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

    fire(event, ...args) {
        if (this._handlers[event]) {
            return this._handlers[event](...args);
        }
    }
}

export class Model {
    constructor(attributes = {}) {
        Object.assign(this, attributes);
    }
}

export class Collection {
    constructor() {
        this._items = [];
    }

    add(item) {
        this._items.push(item);
    }

    [Symbol.iterator]() {
        return this._items[Symbol.iterator]();
    }
}

/**
 * Creates a mock dropdown view.
 */
export function createDropdown() {
    const dropdown = {
        _handlers: {},
        isOpen: false,
        buttonView: new ButtonView(),
        on(event, handler) {
            if (!this._handlers[event]) {
                this._handlers[event] = [];
            }
            this._handlers[event].push(handler);
        },
        fire(event, ...args) {
            if (this._handlers[event]) {
                const results = this._handlers[event].map(handler => handler(...args));
                return Promise.all(results);
            }
            return Promise.resolve();
        },
    };
    return dropdown;
}

/**
 * Adds list items to a dropdown.
 */
export function addListToDropdown(dropdown, items) {
    dropdown._items = items;
}
