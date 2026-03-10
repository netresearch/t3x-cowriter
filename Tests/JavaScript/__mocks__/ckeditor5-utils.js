/**
 * Mock for @ckeditor/ckeditor5-utils
 *
 * Provides minimal stubs for testing CKEditor 5 plugins.
 */
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
