/**
 * Mock for @ckeditor/ckeditor5-core
 *
 * Provides a minimal Plugin stub for testing CKEditor 5 plugins.
 */
export class Plugin {
    static pluginName = '';
    editor = null;

    init() {
        return Promise.resolve();
    }
}
