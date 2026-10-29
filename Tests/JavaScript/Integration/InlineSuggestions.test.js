/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * Inline suggestions in a real CKEditor 5: the build TYPO3 ships in
 * EXT:rte_ckeditor, read from the Composer vendor directory (vitest project
 * "ckeditor" in vitest.config.js). Nothing of CKEditor is mocked; the route
 * is a fetch stub and the clock is vitest's.
 *
 * The grey text must be in the editing view and never in getData(), which is
 * what TYPO3 writes into the form field and saves.
 */
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { existsSync } from 'fs';
import { resolve } from 'path';

const vendor = resolve(__dirname, '../../../.Build/vendor');
const ckeditorBuild = `${vendor}/typo3/cms-rte-ckeditor/Resources/Public/Contrib/@ckeditor`;
const available = existsSync(`${ckeditorBuild}/ckeditor5-editor-classic.js`);

/**
 * @param {string} name
 */
function ckeditorModule(name) {
    return import(/* @vite-ignore */ `${ckeditorBuild}/ckeditor5-${name}.js`);
}

const TEXT = 'The lighthouse keeper climbed the stairs every evening';
const SUGGESTION = ' to light the lamp before dusk.';
const DELAY = 1000;
// InlineSuggestions.js TAB_GUARD: a Tab sooner than this leaves the field.
const TAB_GUARD = 800;

describe.skipIf(!available)('inline suggestions in TYPO3\'s CKEditor', () => {
    let modules;
    /** @type {Array<{destroy: function(): Promise<void>}>} */
    const editors = [];
    /** @type {Array<{body: any, signal: AbortSignal, resolve: function(any): void}>} */
    let requests;

    beforeAll(async () => {
        class NoResizeObserver {
            observe() {}
            unobserve() {}
            disconnect() {}
        }
        window.ResizeObserver = NoResizeObserver;
        globalThis.ResizeObserver = NoResizeObserver;

        modules = {
            ...(await ckeditorModule('editor-classic')),
            ...(await ckeditorModule('essentials')),
            ...(await ckeditorModule('paragraph')),
            ...(await ckeditorModule('basic-styles')),
            ...(await ckeditorModule('list')),
            ...(await ckeditorModule('table')),
            ...(await ckeditorModule('heading')),
            ...(await ckeditorModule('code-block')),
            InlineSuggestions: (await import('../../../Resources/Public/JavaScript/Ckeditor/InlineSuggestions.js'))
                .InlineSuggestions,
            Cowriter: (await import('../../../Resources/Public/JavaScript/Ckeditor/cowriter.js')).Cowriter,
        };
    });

    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] });
        globalThis.localStorage.clear();
        globalThis.TYPO3 = { settings: { ajaxUrls: { tx_cowriter_inline_suggestion: '/typo3/ajax/inline' } } };
        requests = [];
        // A request stays open until the test answers it.
        globalThis.fetch = vi.fn((url, init) => new Promise((resolvePromise, reject) => {
            const request = {
                body: JSON.parse(init.body),
                signal: init.signal,
                resolve: (suggestion) => resolvePromise({
                    ok: true,
                    status: 200,
                    json: async () => ({ success: true, suggestion }),
                }),
            };
            init.signal?.addEventListener('abort', () => {
                const error = new Error('aborted');
                error.name = 'AbortError';
                reject(error);
            });
            requests.push(request);
        }));
    });

    afterEach(async () => {
        while (editors.length > 0) {
            await editors.pop().destroy();
        }
        vi.useRealTimers();
        delete globalThis.TYPO3;
    });

    async function createEditor({ enabled = true, data = `<p>${TEXT}</p>`, userId = 7, extraPlugins = [] } = {}) {
        const element = document.createElement('div');
        document.body.appendChild(element);
        const editor = await modules.ClassicEditor.create(element, {
            licenseKey: 'GPL',
            plugins: [modules.Essentials, modules.Paragraph, modules.Bold, modules.List, modules.Table, ...extraPlugins, modules.Cowriter],
            toolbar: ['cowriterInlineSuggestions'],
            cowriter: enabled ? { inlineSuggestions: { enabled: true, delay: DELAY, minChars: 20, userId } } : {},
        });
        editors.push(editor);
        editor.setData(data);
        editor.editing.view.document.isFocused = true;
        const root = editor.model.document.getRoot();
        editor.model.change((writer) => writer.setSelection(root.getChild(0), 'end'));
        return editor;
    }

    /**
     * Type like a user does: through the typing command, which marks its
     * batch as typing.
     */
    function type(editor, text) {
        editor.execute('insertText', { text });
    }

    function ghost(editor) {
        return editor.editing.view.getDomRoot().querySelector('[data-cowriter-inline-suggestion]');
    }

    function key(editor, keyName, keyCode) {
        const event = new KeyboardEvent('keydown', { key: keyName, keyCode, bubbles: true, cancelable: true });
        editor.editing.view.getDomRoot().dispatchEvent(event);
        return event;
    }

    async function showSuggestion(editor) {
        type(editor, '.');
        await vi.advanceTimersByTimeAsync(DELAY);
        expect(requests).toHaveLength(1);
        requests[0].resolve(SUGGESTION);
        await vi.advanceTimersByTimeAsync(0);
        expect(ghost(editor)).not.toBeNull();
    }

    it('shows the suggestion in the editing view only, never in the data', async () => {
        const editor = await createEditor();
        let dataChanges = 0;
        editor.model.document.on('change:data', () => dataChanges++);

        await showSuggestion(editor);

        expect(requests[0].body).toEqual({ text: `${TEXT}.` });
        expect(ghost(editor).textContent).toBe(SUGGESTION);
        expect(ghost(editor).getAttribute('aria-hidden')).toBe('true');
        expect(editor.editing.view.getDomRoot().textContent).toContain(SUGGESTION.trim());
        expect(editor.getData()).toBe(`<p>${TEXT}.</p>`);
        expect(editor.getData()).not.toContain('light the lamp');
        // Only the typed full stop changed the data; showing the suggestion did not.
        expect(dataChanges).toBe(1);
    });

    it('keeps the caret in front of the grey text', async () => {
        const editor = await createEditor();
        await showSuggestion(editor);

        // Where the browser puts the caret: the view selection mapped to the DOM.
        const view = editor.editing.view;
        const caret = view.domConverter.viewPositionToDom(view.document.selection.getFirstPosition());
        const range = document.createRange();
        range.setStart(caret.parent, caret.offset);

        expect(range.comparePoint(ghost(editor), 0)).toBe(1);
        expect(caret.parent.textContent).toBe(`${TEXT}.`);
    });

    it('announces the suggestion in a polite live region', async () => {
        const editor = await createEditor();
        await showSuggestion(editor);

        const region = editor.ui.view.element.querySelector('[aria-live="polite"]');
        expect(region.getAttribute('role')).toBe('status');
        expect(region.textContent).toBe(`Suggestion available, press Tab to accept: ${SUGGESTION.trim()}`);
    });

    it('inserts the suggestion as plain text on Tab, as one undo step', async () => {
        const editor = await createEditor();
        await showSuggestion(editor);
        let dataChanges = 0;
        editor.model.document.on('change:data', () => dataChanges++);
        await vi.advanceTimersByTimeAsync(TAB_GUARD);

        const event = key(editor, 'Tab', 9);

        expect(event.defaultPrevented).toBe(true);
        expect(ghost(editor)).toBeNull();
        expect(editor.getData()).toBe(`<p>${TEXT}.${SUGGESTION}</p>`);
        expect(dataChanges).toBe(1);

        editor.execute('undo');
        expect(editor.getData()).toBe(`<p>${TEXT}.</p>`);
    });

    it('inserts markup in the suggestion as text, not as markup', async () => {
        const editor = await createEditor();
        type(editor, '.');
        await vi.advanceTimersByTimeAsync(DELAY);
        requests[0].resolve(' <b>bold</b> & <img src=x onerror=alert(1)>');
        await vi.advanceTimersByTimeAsync(TAB_GUARD);

        key(editor, 'Tab', 9);

        expect(editor.getData()).toBe(`<p>${TEXT}. &lt;b&gt;bold&lt;/b&gt; &amp; &lt;img src=x onerror=alert(1)&gt;</p>`);
    });

    it('lets a Tab right after the suggestion appeared leave the field and discards the suggestion', async () => {
        const editor = await createEditor();
        await showSuggestion(editor);
        await vi.advanceTimersByTimeAsync(TAB_GUARD - 1);

        const event = key(editor, 'Tab', 9);

        expect(event.defaultPrevented).toBe(false);
        expect(ghost(editor)).toBeNull();
        expect(editor.getData()).toBe(`<p>${TEXT}.</p>`);
    });

    it('accepts with Tab once the guard time has passed', async () => {
        const editor = await createEditor();
        await showSuggestion(editor);
        await vi.advanceTimersByTimeAsync(TAB_GUARD);

        const event = key(editor, 'Tab', 9);

        expect(event.defaultPrevented).toBe(true);
        expect(editor.getData()).toBe(`<p>${TEXT}.${SUGGESTION}</p>`);
    });

    it('leaves Shift+Tab alone and discards the suggestion', async () => {
        const editor = await createEditor();
        await showSuggestion(editor);
        await vi.advanceTimersByTimeAsync(TAB_GUARD);

        const event = new KeyboardEvent('keydown', { key: 'Tab', keyCode: 9, shiftKey: true, bubbles: true, cancelable: true });
        editor.editing.view.getDomRoot().dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(ghost(editor)).toBeNull();
        expect(editor.getData()).toBe(`<p>${TEXT}.</p>`);
    });

    it('does not ask in a list item or a table cell, where Tab indents or moves to the next cell', async () => {
        for (const data of [`<ul><li>${TEXT}</li></ul>`, `<figure class="table"><table><tbody><tr><td>${TEXT}</td><td>x</td></tr></tbody></table></figure>`]) {
            const editor = await createEditor({ data });
            const root = editor.model.document.getRoot();
            const block = editor.model.createRangeIn(root).getItems();
            let last = null;
            for (const item of block) {
                if (item.is('element') && editor.model.schema.checkChild(item, '$text') && item.childCount > 0) {
                    last = last ?? item;
                }
            }
            editor.model.change((writer) => writer.setSelection(last, 'end'));

            type(editor, '.');
            await vi.advanceTimersByTimeAsync(DELAY * 10);
        }

        expect(requests).toHaveLength(0);
    });

    it('asks at the end of a heading', async () => {
        await createEditor({ data: `<h2>${TEXT}</h2>`, extraPlugins: [modules.Heading] });

        type(editors[0], '.');
        await vi.advanceTimersByTimeAsync(DELAY);

        expect(requests).toHaveLength(1);
    });

    it('does not ask in a code block, where Tab indents', async () => {
        const editor = await createEditor({
            data: `<pre><code class="language-plaintext">${TEXT}</code></pre>`,
            extraPlugins: [modules.CodeBlock],
        });
        expect(editor.model.document.getRoot().getChild(0).name).toBe('codeBlock');

        type(editor, '.');
        await vi.advanceTimersByTimeAsync(DELAY * 10);

        expect(requests).toHaveLength(0);
    });

    it('leaves Tab alone when no suggestion is shown', async () => {
        const editor = await createEditor();

        const event = key(editor, 'Tab', 9);

        expect(event.defaultPrevented).toBe(false);
        expect(editor.getData()).toBe(`<p>${TEXT}</p>`);
    });

    it('discards the suggestion on Escape without inserting anything', async () => {
        const editor = await createEditor();
        await showSuggestion(editor);
        let dataChanges = 0;
        editor.model.document.on('change:data', () => dataChanges++);

        const event = key(editor, 'Escape', 27);

        expect(event.defaultPrevented).toBe(true);
        expect(ghost(editor)).toBeNull();
        expect(editor.getData()).toBe(`<p>${TEXT}.</p>`);
        expect(dataChanges).toBe(0);
        expect(editor.ui.view.element.querySelector('[aria-live="polite"]').textContent).toBe('');
    });

    it('discards the suggestion on any other key, but not on a modifier alone', async () => {
        const editor = await createEditor();
        await showSuggestion(editor);

        key(editor, 'Shift', 16);
        expect(ghost(editor)).not.toBeNull();

        const event = key(editor, 'a', 65);
        expect(ghost(editor)).toBeNull();
        expect(event.defaultPrevented).toBe(false);
    });

    it('discards the suggestion when the cursor moves away from it', async () => {
        const editor = await createEditor();
        await showSuggestion(editor);

        editor.model.change((writer) => writer.setSelection(editor.model.document.getRoot().getChild(0), 3));

        expect(ghost(editor)).toBeNull();
        expect(editor.plugins.get('cowriterInlineSuggestions').suggestion).toBeNull();
    });

    it('sends the paragraphs before the current one, at most the last 1000 characters', async () => {
        const editor = await createEditor({ data: `<p>First paragraph.</p><p>${TEXT}</p>` });
        const root = editor.model.document.getRoot();
        editor.model.change((writer) => writer.setSelection(root.getChild(1), 'end'));

        type(editor, '.');
        await vi.advanceTimersByTimeAsync(DELAY);
        expect(requests[0].body.text).toBe(`First paragraph.\n\n${TEXT}.`);

        const long = 'x'.repeat(1500);
        editor.setData(`<p>${long}</p>`);
        editor.model.change((writer) => writer.setSelection(root.getChild(0), 'end'));
        type(editor, 'y');
        await vi.advanceTimersByTimeAsync(5000);
        expect(requests).toHaveLength(2);
        expect(requests[1].body.text).toBe(`${'x'.repeat(999)}y`);
    });

    it('never splits a character that takes two code units when it cuts the text', async () => {
        const emoji = '\u{1F600}';
        // 1001 code points, 1002 code units: cutting code units would keep
        // only the second half of the emoji.
        const editor = await createEditor({ data: `<p>a${emoji}${'x'.repeat(998)}</p>` });

        type(editor, 'y');
        await vi.advanceTimersByTimeAsync(DELAY);

        expect(requests[0].body.text).toBe(`${emoji}${'x'.repeat(998)}y`);
        expect(Array.from(requests[0].body.text)).toHaveLength(1000);
    });

    it('discards the suggestion when the editor leaves the editor', async () => {
        const editor = await createEditor();
        await showSuggestion(editor);

        editor.editing.view.document.isFocused = false;

        expect(ghost(editor)).toBeNull();
    });

    it('asks after the pause only, with one request for a burst of keys', async () => {
        const editor = await createEditor();

        type(editor, ' a');
        await vi.advanceTimersByTimeAsync(DELAY - 1);
        type(editor, 'n');
        await vi.advanceTimersByTimeAsync(DELAY - 1);
        expect(requests).toHaveLength(0);

        await vi.advanceTimersByTimeAsync(1);
        expect(requests).toHaveLength(1);
        expect(requests[0].body.text).toBe(`${TEXT} an`);
    });

    it('aborts the running request and drops its answer when the editor types on', async () => {
        const editor = await createEditor();
        type(editor, '.');
        await vi.advanceTimersByTimeAsync(DELAY);
        const first = requests[0];

        type(editor, ' A');

        expect(first.signal.aborted).toBe(true);
        first.resolve(SUGGESTION);
        await vi.advanceTimersByTimeAsync(0);
        expect(ghost(editor)).toBeNull();
    });

    it('drops an answer when the caret moved meanwhile', async () => {
        const editor = await createEditor({ data: `<p>${TEXT}</p><p>Second paragraph with enough text in it</p>` });
        type(editor, '.');
        await vi.advanceTimersByTimeAsync(DELAY);

        editor.model.change((writer) => writer.setSelection(editor.model.document.getRoot().getChild(1), 'end'));
        requests[0].resolve(SUGGESTION);
        await vi.advanceTimersByTimeAsync(0);

        expect(ghost(editor)).toBeNull();
    });

    it('does not ask with the cursor inside a paragraph, with a selection or during a composition', async () => {
        const editor = await createEditor({ data: `<p>${TEXT}</p><p>Second paragraph</p>` });
        const root = editor.model.document.getRoot();

        editor.model.change((writer) => writer.setSelection(root.getChild(0), 5));
        type(editor, 'x');
        await vi.advanceTimersByTimeAsync(DELAY * 10);

        // A selection that starts at the end of the paragraph.
        editor.model.change((writer) => writer.setSelection(root.getChild(0), 'end'));
        type(editor, 'y');
        editor.model.change((writer) => writer.setSelection(writer.createRange(
            writer.createPositionAt(root.getChild(0), 'end'),
            writer.createPositionAt(root.getChild(1), 3),
        )));
        await vi.advanceTimersByTimeAsync(DELAY * 10);

        editor.model.change((writer) => writer.setSelection(root.getChild(0), 'end'));
        type(editor, 'z');
        editor.editing.view.document.isComposing = true;
        await vi.advanceTimersByTimeAsync(DELAY * 10);

        expect(requests).toHaveLength(0);
    });

    it('does not ask for a short paragraph', async () => {
        const editor = await createEditor({ data: '<p>Too short</p>' });

        type(editor, '.');
        await vi.advanceTimersByTimeAsync(DELAY * 10);

        expect(requests).toHaveLength(0);
    });

    it('does not ask after a change that is not typing', async () => {
        const editor = await createEditor();

        editor.model.change((writer) => writer.insertText(' pasted', editor.model.document.selection.getFirstPosition()));
        await vi.advanceTimersByTimeAsync(DELAY * 10);

        expect(requests).toHaveLength(0);
    });

    it('wires nothing while the administrator has not switched it on', async () => {
        const editor = await createEditor({ enabled: false });
        const toggle = editor.ui.view.toolbar.items.find((item) => item.label === 'Cowriter - Inline suggestions');

        type(editor, '.');
        await vi.advanceTimersByTimeAsync(DELAY * 10);

        expect(requests).toHaveLength(0);
        expect(toggle.isEnabled).toBe(false);
        expect(toggle.isOn).toBe(false);
    });

    it('stops asking when the editor switches it off, and remembers the choice', async () => {
        const editor = await createEditor();
        const toggle = editor.ui.view.toolbar.items.find((item) => item.label === 'Cowriter - Inline suggestions');
        await showSuggestion(editor);

        toggle.fire('execute');

        expect(toggle.isOn).toBe(false);
        expect(toggle.element.getAttribute('aria-pressed')).toBe('false');
        expect(ghost(editor)).toBeNull();
        expect(globalThis.localStorage.getItem('t3_cowriter.inlineSuggestions.7')).toBe('off');

        type(editor, ' More');
        await vi.advanceTimersByTimeAsync(DELAY * 10);
        expect(requests).toHaveLength(1);

        const reopened = await createEditor();
        expect(reopened.plugins.get('cowriterInlineSuggestions').isOn).toBe(false);
    });

    it('switches off every rich text field of the user on the page, not only the clicked one', async () => {
        const first = await createEditor();
        const second = await createEditor();
        const otherUser = await createEditor({ userId: 8 });
        const toggleOf = (editor) => editor.ui.view.toolbar.items.find((item) => item.label === 'Cowriter - Inline suggestions');

        toggleOf(first).fire('execute');

        expect(second.plugins.get('cowriterInlineSuggestions').isOn).toBe(false);
        expect(toggleOf(second).element.getAttribute('aria-pressed')).toBe('false');
        expect(otherUser.plugins.get('cowriterInlineSuggestions').isOn).toBe(true);

        second.editing.view.document.isFocused = true;
        second.model.change((writer) => writer.setSelection(second.model.document.getRoot().getChild(0), 'end'));
        type(second, '.');
        await vi.advanceTimersByTimeAsync(DELAY * 10);
        expect(requests).toHaveLength(0);

        toggleOf(second).fire('execute');
        expect(first.plugins.get('cowriterInlineSuggestions').isOn).toBe(true);
        expect(second.plugins.get('cowriterInlineSuggestions').isOn).toBe(true);
    });

    it('follows the choice made in another tab through the storage event', async () => {
        const editor = await createEditor();
        const plugin = editor.plugins.get('cowriterInlineSuggestions');
        await showSuggestion(editor);

        window.dispatchEvent(new StorageEvent('storage', { key: 't3_cowriter.inlineSuggestions.7', newValue: 'off' }));
        expect(plugin.isOn).toBe(false);
        expect(ghost(editor)).toBeNull();

        window.dispatchEvent(new StorageEvent('storage', { key: 't3_cowriter.inlineSuggestions.8', newValue: 'on' }));
        expect(plugin.isOn).toBe(false);

        window.dispatchEvent(new StorageEvent('storage', { key: 't3_cowriter.inlineSuggestions.7', newValue: 'on' }));
        expect(plugin.isOn).toBe(true);
    });

    it('forgets a destroyed editor', async () => {
        const first = await createEditor();
        const second = await createEditor();
        const plugin = second.plugins.get('cowriterInlineSuggestions');
        editors.splice(editors.indexOf(second), 1);
        await second.destroy();

        first.plugins.get('cowriterInlineSuggestions').setUserChoice(false);
        window.dispatchEvent(new StorageEvent('storage', { key: 't3_cowriter.inlineSuggestions.7', newValue: 'off' }));

        expect(plugin.isOn).toBe(true);
    });
});
