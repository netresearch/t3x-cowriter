/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * A Cowriter result replaces the selection in a real CKEditor 5 (the build
 * TYPO3 ships in EXT:rte_ckeditor, vitest project "ckeditor"); the block the
 * selection lies in keeps its element and attributes.
 *
 * The case this exists for: a paragraph with the style "Lead"
 * (<p class="lead">) fully selected, a task run, the result inserted — the
 * paragraph came back as a bare <p>. The data pipeline wraps plain text, or a
 * bare <p>, in a new attribute-less paragraph, and insertContent() puts it in
 * place of the emptied block.
 *
 * Only the dialog is replaced (it would call the backend); the editor, the
 * Cowriter plugin and its buttons are real. Without `composer install` the
 * CKEditor files are missing and the tests are skipped.
 */
import { describe, it, expect, beforeAll, afterEach, vi } from 'vitest';
import { existsSync } from 'fs';
import { resolve } from 'path';

const ckeditorBuild = resolve(
    __dirname,
    '../../../.Build/vendor/typo3/cms-rte-ckeditor/Resources/Public/Contrib/@ckeditor',
);
const available = existsSync(`${ckeditorBuild}/ckeditor5-editor-classic.js`);

/** What the replaced dialog answers; set per test. */
let dialogResult = { content: '' };

vi.mock('@netresearch/t3_cowriter/CowriterDialog', () => ({
    CowriterDialog: class {
        show() {
            return Promise.resolve(dialogResult);
        }
    },
}));

/**
 * A module of TYPO3's CKEditor build, by file path (see GeneratedImageRoundTrip.test.js).
 *
 * @param {string} name
 */
function ckeditorModule(name) {
    return import(/* @vite-ignore */ `${ckeditorBuild}/ckeditor5-${name}.js`);
}

const LEAD = '<p class="lead" style="text-align:center;">Teh quick brown fox.</p><p>Next paragraph.</p>';

describe.skipIf(!available)('a Cowriter result in place of a selection in TYPO3\'s CKEditor', () => {
    let modules;
    /** @type {Array<{destroy: function(): Promise<void>}>} */
    const editors = [];

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
            ...(await ckeditorModule('heading')),
            ...(await ckeditorModule('list')),
            ...(await ckeditorModule('alignment')),
            ...(await ckeditorModule('html-support')),
            ...(await ckeditorModule('style')),
            Cowriter: (await import('../../../Resources/Public/JavaScript/Ckeditor/cowriter.js')).Cowriter,
        };
    });

    afterEach(async () => {
        while (editors.length > 0) {
            await editors.pop().destroy();
        }
    });

    async function createEditor(data) {
        const element = document.createElement('div');
        document.body.appendChild(element);
        const editor = await modules.ClassicEditor.create(element, {
            licenseKey: 'GPL',
            plugins: [
                modules.Essentials,
                modules.Paragraph,
                modules.Heading,
                modules.List,
                modules.Alignment,
                modules.GeneralHtmlSupport,
                modules.Style,
                modules.Cowriter,
            ],
            // Lead is the definition bootstrap_package's preset ships.
            style: {
                definitions: [
                    { name: 'Lead', element: 'p', classes: ['lead'] },
                    { name: 'Intro', element: 'p', classes: ['intro'] },
                ],
            },
        });
        editors.push(editor);
        editor.setData(data);
        return editor;
    }

    /** Select the whole text of the n-th block of the root. */
    function selectTextOf(editor, index) {
        const model = editor.model;
        const block = model.document.getRoot().getChild(index);
        model.change((writer) => writer.setSelection(model.createRange(
            model.createPositionAt(block, 0),
            model.createPositionAt(block, 'end'),
        )));
    }

    /** Let the async execute handler of a button finish. */
    async function settle() {
        for (let i = 0; i < 5; i++) {
            await new Promise((done) => setTimeout(done, 0));
        }
    }

    describe('through the toolbar', () => {
        it('keeps the Lead class and the alignment when a task result replaces the whole paragraph', async () => {
            const editor = await createEditor(LEAD);
            selectTextOf(editor, 0);
            dialogResult = { content: '<p>The quick brown fox.</p>' };

            const dropdown = editor.ui.componentFactory.create('cowriterTemplates');
            dropdown.taskUid = 3;
            dropdown.fire('execute');
            await settle();

            expect(editor.getData()).toBe(
                '<p class="lead" style="text-align:center;">The quick brown fox.</p><p>Next paragraph.</p>',
            );
        });

        it('keeps them when the Cowriter dialog answers with plain text', async () => {
            const editor = await createEditor(LEAD);
            selectTextOf(editor, 0);
            dialogResult = { content: 'The quick brown fox.' };

            editor.ui.componentFactory.create('cowriter').fire('execute');
            await settle();

            expect(editor.getData()).toBe(
                '<p class="lead" style="text-align:center;">The quick brown fox.</p><p>Next paragraph.</p>',
            );
        });

        it('keeps them when a translation replaces the whole paragraph', async () => {
            const editor = await createEditor(LEAD);
            selectTextOf(editor, 0);
            const cowriter = editor.plugins.get('cowriter');
            cowriter._service.translate = async () => ({ success: true, translation: '<p>Der schnelle braune Fuchs.</p>' });

            const dropdown = editor.ui.componentFactory.create('cowriterTranslate');
            dropdown.languageCode = 'de';
            dropdown.label = 'German';
            dropdown.fire('execute');
            await settle();

            expect(editor.getData()).toBe(
                '<p class="lead" style="text-align:center;">Der schnelle braune Fuchs.</p><p>Next paragraph.</p>',
            );
        });
    });

    describe('_insertResult()', () => {
        it('keeps a heading a heading', async () => {
            const editor = await createEditor('<h2>Teh heading</h2><p>Body</p>');
            selectTextOf(editor, 0);

            editor.plugins.get('cowriter')._insertResult('The heading');

            expect(editor.getData()).toBe('<h2>The heading</h2><p>Body</p>');
        });

        it('keeps a list item a list item', async () => {
            const editor = await createEditor('<ul><li>Frist item</li><li>Second</li></ul>');
            // The list plugin writes its item ids into the data; they stay as they are.
            const before = editor.getData();
            selectTextOf(editor, 0);

            editor.plugins.get('cowriter')._insertResult('<p>First item</p>');

            expect(before).toMatch(/^<ul><li[^>]*>Frist item<\/li><li[^>]*>Second<\/li><\/ul>$/);
            expect(editor.getData()).toBe(before.replace('Frist item', 'First item'));
        });

        it('inserts a result block with attributes of its own as it is', async () => {
            const editor = await createEditor(LEAD);
            selectTextOf(editor, 0);

            editor.plugins.get('cowriter')._insertResult('<h3>A new heading</h3>');

            expect(editor.getData()).toBe('<h3>A new heading</h3><p>Next paragraph.</p>');
        });

        it('keeps the classes of a result paragraph that has its own', async () => {
            const editor = await createEditor(LEAD);
            selectTextOf(editor, 0);

            editor.plugins.get('cowriter')._insertResult('<p class="intro">The quick brown fox.</p>');

            expect(editor.getData()).toBe('<p class="intro">The quick brown fox.</p><p>Next paragraph.</p>');
        });

        it('keeps the attributes on both ends when a multi-paragraph result replaces part of a paragraph', async () => {
            const editor = await createEditor('<p class="lead">Start middle end</p>');
            const model = editor.model;
            const block = model.document.getRoot().getChild(0);
            model.change((writer) => writer.setSelection(model.createRange(
                model.createPositionAt(block, 6),
                model.createPositionAt(block, 12),
            )));

            editor.plugins.get('cowriter')._insertResult('<p>one</p><p>two</p>');

            expect(editor.getData()).toBe('<p class="lead">Start one</p><p class="lead">two end</p>');
        });
    });
});
