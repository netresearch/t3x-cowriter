/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * A generated image inserted by the Cowriter plugin into a real CKEditor 5:
 * the build TYPO3 ships in EXT:rte_ckeditor and the real image plugin of
 * netresearch/rte-ckeditor-image, both read from the Composer vendor
 * directory (vitest project "ckeditor" in vitest.config.js). Nothing of
 * CKEditor or the image plugin is mocked; the TYPO3 backend modal and
 * notification are.
 *
 * The data round trip getData() → setData() → getData() is what saving and
 * reopening a record does in the browser. Without `composer install` the
 * files are missing and the tests are skipped.
 */
import { describe, it, expect, beforeAll, afterEach } from 'vitest';
import { existsSync } from 'fs';
import { resolve } from 'path';

const vendor = resolve(__dirname, '../../../.Build/vendor');
const ckeditorBuild = `${vendor}/typo3/cms-rte-ckeditor/Resources/Public/Contrib/@ckeditor`;
const imagePlugin = `${vendor}/netresearch/rte-ckeditor-image/Resources/Public/JavaScript/Plugins/typo3image.js`;
const available = existsSync(`${ckeditorBuild}/ckeditor5-editor-classic.js`) && existsSync(imagePlugin);

/**
 * A module of TYPO3's CKEditor build, by file path: a bare specifier would be
 * resolved when the file is transformed, which fails without the vendor
 * directory even though the tests are skipped then.
 *
 * @param {string} name
 */
function ckeditorModule(name) {
    return import(/* @vite-ignore */ `${ckeditorBuild}/ckeditor5-${name}.js`);
}

const generated = {
    success: true,
    image: {
        fileUid: 42,
        fileTable: 'sys_file',
        url: '/fileadmin/user_upload/a-lighthouse-at-dusk-0a1b2c3d.png',
        width: 1024,
        height: 768,
        mimeType: 'image/png',
        file: '1:/user_upload/a-lighthouse-at-dusk-0a1b2c3d.png',
    },
    alt: 'A lighthouse "at" <dusk> & sea',
};

/**
 * The attributes of every <img> in an editor's data.
 *
 * @param {string} html
 * @returns {Array<Record<string, string>>}
 */
function images(html) {
    const body = new DOMParser().parseFromString(html, 'text/html').body;
    return Array.from(body.querySelectorAll('img')).map((img) => Object.fromEntries(
        Array.from(img.attributes).map((attribute) => [attribute.name, attribute.value]),
    ));
}

describe.skipIf(!available)('a generated image in TYPO3\'s CKEditor with rte-ckeditor-image', () => {
    let modules;
    /** @type {Array<{destroy: function(): Promise<void>}>} */
    const editors = [];

    beforeAll(async () => {
        // CKEditor's toolbar measures itself; jsdom has no ResizeObserver.
        class NoResizeObserver {
            observe() {}
            unobserve() {}
            disconnect() {}
        }
        window.ResizeObserver = NoResizeObserver;
        globalThis.ResizeObserver = NoResizeObserver;
        // The image plugin loads its labels from its backend route.
        globalThis.fetch = async () => ({ ok: true, json: async () => ({ lang: {} }) });

        modules = {
            ...(await ckeditorModule('editor-classic')),
            ...(await ckeditorModule('essentials')),
            ...(await ckeditorModule('paragraph')),
            ...(await ckeditorModule('html-support')),
            ...(await ckeditorModule('style')),
            ...(await ckeditorModule('widget')),
            Typo3Image: (await import(/* @vite-ignore */ imagePlugin)).default,
            Cowriter: (await import('../../../Resources/Public/JavaScript/Ckeditor/cowriter.js')).Cowriter,
        };
    });

    afterEach(async () => {
        while (editors.length > 0) {
            await editors.pop().destroy();
        }
    });

    async function createEditor({ withImagePlugin }) {
        const element = document.createElement('div');
        document.body.appendChild(element);
        const plugins = [
            modules.Essentials,
            modules.Paragraph,
            modules.GeneralHtmlSupport,
            modules.Style,
            modules.WidgetToolbarRepository,
            modules.Cowriter,
        ];
        if (withImagePlugin) {
            plugins.push(modules.Typo3Image);
        }
        const editor = await modules.ClassicEditor.create(element, {
            licenseKey: 'GPL',
            plugins,
            toolbar: ['cowriterImage'],
            typo3image: { routeUrl: '/typo3/rte/image?token=test' },
        });
        editors.push(editor);
        return editor;
    }

    it('keeps the file reference, source, alternative text and size through saving and reopening', async () => {
        const editor = await createEditor({ withImagePlugin: true });

        editor.plugins.get('cowriter')._insertGeneratedImage(generated);
        const saved = editor.getData();
        editor.setData(saved);
        const reopened = editor.getData();

        for (const html of [saved, reopened]) {
            const found = images(html);
            expect(found).toHaveLength(1);
            expect(found[0]).toMatchObject({
                'data-htmlarea-file-uid': '42',
                'data-htmlarea-file-table': 'sys_file',
                src: '/fileadmin/user_upload/a-lighthouse-at-dusk-0a1b2c3d.png',
                alt: 'A lighthouse "at" <dusk> & sea',
                width: '1024',
                height: '768',
            });
        }

        const root = editor.model.document.getRoot();
        const elements = Array.from(editor.model.createRangeIn(root).getItems())
            .filter((item) => item.is?.('element', 'typo3image'));
        expect(elements).toHaveLength(1);
        expect(elements[0].getAttribute('fileUid')).toBe('42');
    });

    it('inserts at the remembered cursor although the editor typed elsewhere meanwhile', async () => {
        const editor = await createEditor({ withImagePlugin: true });
        editor.setData('<p>First</p><p>Second</p>');
        const root = editor.model.document.getRoot();
        const cowriter = editor.plugins.get('cowriter');

        // The cursor at the end of "First" when the button is clicked …
        editor.model.change((writer) => writer.setSelection(root.getChild(0), 'end'));
        const insertionPoint = cowriter._rememberInsertionPoint();
        // … then the editor types in front of it and moves on to "Second".
        editor.model.change((writer) => {
            writer.insertText('Very ', root.getChild(0), 0);
            writer.setSelection(root.getChild(1), 'end');
        });
        cowriter._insertGeneratedImage(generated, insertionPoint);
        cowriter._forgetInsertionPoint(insertionPoint);

        const data = editor.getData();
        expect(data.indexOf('data-htmlarea-file-uid="42"')).toBeGreaterThan(data.indexOf('Very First'));
        expect(data.indexOf('data-htmlarea-file-uid="42"')).toBeLessThan(data.indexOf('Second'));
        expect(Array.from(editor.model.markers)).toHaveLength(0);
    });

    it('offers the button with the image plugin', async () => {
        const editor = await createEditor({ withImagePlugin: true });
        const button = editor.ui.view.toolbar.items.find((item) => item.label === 'Cowriter - Generate image');

        expect(button).toBeDefined();
        expect(button.isEnabled).toBe(true);
    });

    it('disables the button and says why without the image plugin', async () => {
        const editor = await createEditor({ withImagePlugin: false });
        const button = editor.ui.view.toolbar.items.find(
            (item) => item.label === 'Cowriter - Generate image: needs the image plugin of rte-ckeditor-image',
        );

        expect(editor.model.schema.isRegistered('typo3image')).toBe(false);
        expect(button).toBeDefined();
        expect(button.isEnabled).toBe(false);
    });
});
