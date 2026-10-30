/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * Tests for the "Generate image" dialog. The TYPO3 modal is mocked via
 * vitest.config.js alias.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ImageDialog, MAX_PROMPT_LENGTH, ANNOUNCE_DELAY_MS } from '../../Resources/Public/JavaScript/Ckeditor/ImageDialog.js';

const generated = {
    success: true,
    image: { fileUid: 42, fileTable: 'sys_file', url: '/fileadmin/a.png', width: 1024, height: 1024, mimeType: 'image/png', file: '1:/a.png' },
    alt: 'A lighthouse',
};

function modal() {
    return document.querySelector('.modal');
}

function button(name) {
    return modal().querySelector(`button[name="${name}"]`);
}

function textarea() {
    return modal().querySelector('[data-role="image-prompt"]');
}

function status() {
    return modal().querySelector('[data-role="image-status"]');
}

/** Let pending promise callbacks run. */
function flush() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

describe('ImageDialog', () => {
    let service;

    beforeEach(() => {
        service = { generateImage: vi.fn().mockResolvedValue(generated) };
    });

    afterEach(() => {
        document.body.replaceChildren();
    });

    it('asks for a description of at most the length the server accepts', () => {
        void new ImageDialog(service).show().catch(() => {});

        expect(textarea().maxLength).toBe(MAX_PROMPT_LENGTH);
        expect(MAX_PROMPT_LENGTH).toBe(1000);
        expect(modal().querySelector('label').htmlFor).toBe(textarea().id);
        expect(textarea().getAttribute('aria-describedby')).toBe('cowriter-image-prompt-help');
        expect(status().hidden).toBe(true);
    });

    it('marks the description for the modal\'s initial focus', () => {
        void new ImageDialog(service).show().catch(() => {});

        // Both core modals focus [autofocus] when shown; without it TYPO3 14.3
        // focuses the close button.
        expect(textarea().autofocus).toBe(true);
    });

    it('says where the image goes in the texts its caller passes', async () => {
        let finish;
        service.generateImage.mockReturnValue(new Promise((resolve) => { finish = resolve; }));
        const result = new ImageDialog(service, {
            promptHelp: 'Added to this field.',
            generating: 'Generating for this field.',
        }).show();

        expect(modal().querySelector('#cowriter-image-prompt-help').textContent).toBe('Added to this field.');

        textarea().value = 'A lighthouse';
        button('generate').click();
        await flush();

        expect(status().textContent).toBe('Generating for this field.');

        finish(generated);
        await expect(result).resolves.toEqual(generated);
    });

    it('speaks of the rich text editor without such texts', () => {
        void new ImageDialog(service).show().catch(() => {});

        expect(modal().querySelector('#cowriter-image-prompt-help').textContent).toContain('inserted at the cursor');
    });

    it('does not call the server for an empty description', async () => {
        void new ImageDialog(service).show().catch(() => {});
        textarea().value = '   ';
        button('generate').click();
        await flush();

        expect(service.generateImage).not.toHaveBeenCalled();
        expect(status().textContent).toBe('Describe the image first.');
        expect(status().hidden).toBe(false);
    });

    it('resolves with the stored image and closes', async () => {
        const result = new ImageDialog(service).show();
        textarea().value = '  A lighthouse  ';
        button('generate').click();

        await expect(result).resolves.toEqual(generated);
        expect(service.generateImage).toHaveBeenCalledWith('A lighthouse');
        expect(modal()).toBeNull();
    });

    it('keeps the editor out while the image is generated', async () => {
        let finish;
        service.generateImage.mockReturnValue(new Promise((resolve) => { finish = resolve; }));
        const result = new ImageDialog(service).show();
        textarea().value = 'A lighthouse';
        button('generate').click();
        await flush();

        expect(button('generate').disabled).toBe(true);
        expect(textarea().disabled).toBe(true);
        expect(status().textContent).toContain('Generating the image');
        button('generate').click();
        expect(service.generateImage).toHaveBeenCalledTimes(1);

        finish(generated);
        await expect(result).resolves.toEqual(generated);
    });

    it('shows a server error as text and lets the editor try again', async () => {
        const payload = '<img src=x onerror="window.pwned=1">';
        service.generateImage.mockRejectedValueOnce(new Error(payload));
        void new ImageDialog(service).show().catch(() => {});
        textarea().value = 'A lighthouse';
        button('generate').click();
        await flush();

        expect(status().textContent).toBe(payload);
        expect(status().querySelector('img')).toBeNull();
        expect(status().className).toContain('alert-danger');
        expect(button('generate').disabled).toBe(false);
        expect(textarea().disabled).toBe(false);
    });

    it('rejects when the editor cancels before generating', async () => {
        const returnFocus = vi.fn();
        const result = new ImageDialog(service, { returnFocus }).show();
        button('cancel').click();

        await expect(result).rejects.toThrow('User cancelled');
        expect(service.generateImage).not.toHaveBeenCalled();
        expect(returnFocus).toHaveBeenCalled();
    });

    it('keeps waiting for a paid image when the editor closes the dialog during generation', async () => {
        let finish;
        service.generateImage.mockReturnValue(new Promise((resolve) => { finish = resolve; }));
        let outcome = 'pending';
        const result = new ImageDialog(service).show();
        result.then(() => { outcome = 'resolved'; }, () => { outcome = 'rejected'; });
        textarea().value = 'A lighthouse';
        button('generate').click();
        await flush();
        expect(status().textContent).toContain('as long as this record stays open; otherwise it is in your upload folder.');

        button('cancel').click();
        await flush();
        expect(modal()).toBeNull();
        expect(outcome).toBe('pending');

        finish(generated);
        await expect(result).resolves.toEqual(generated);
    });

    it('is titled as a Cowriter dialog', () => {
        void new ImageDialog(service).show().catch(() => {});

        expect(modal().dataset.modalTitle).toBe('Cowriter - Generate image');
    });

    describe('context of the image', () => {
        const TARGET = { table: 'tt_content', uid: 34, field: 'image', pid: 12 };
        const ABOUT = {
            origin: 'Cowriter, image generation',
            feature: 'image generation',
            configuration: '',
            model: 'gpt-image-2',
            note: '',
            sources: ['The description you enter'],
            settings: [{ label: 'Feature', value: 'image generation is switched on' }],
            links: [],
        };
        const CONTEXT = {
            purpose: 'Image of the content element "Our team"',
            field: 'Images',
            proposal: 'Our team at the office in Leipzig',
            about: ABOUT,
        };
        let finish;

        function purpose() {
            return modal().querySelector('[data-role="image-purpose"]');
        }

        function hint() {
            return modal().querySelector('[data-role="image-proposal"]');
        }

        beforeEach(() => {
            service.getImageContext = vi.fn(() => new Promise((resolve) => { finish = resolve; }));
        });

        it('asks for the context of its target and fills the empty description with the proposal', async () => {
            void new ImageDialog(service, { target: TARGET }).show().catch(() => {});
            expect(service.getImageContext).toHaveBeenCalledWith(TARGET);
            expect(hint().hidden).toBe(true);

            finish(CONTEXT);
            await flush();

            expect(textarea().value).toBe('Our team at the office in Leipzig');
            expect(hint().hidden).toBe(false);
            expect(hint().textContent).toContain('not by the model');
            expect(textarea().getAttribute('aria-describedby')).toBe(hint().id + ' cowriter-image-prompt-help');
            expect(hint().id).toBe('cowriter-image-proposal');
        });

        it('announces the proposal in the status live region, shown before its text arrives', async () => {
            void new ImageDialog(service, { target: TARGET }).show().catch(() => {});
            expect(status().getAttribute('role')).toBe('status');

            finish(CONTEXT);
            await flush();

            // The region is in the accessibility tree first, still empty ...
            expect(status().hidden).toBe(false);
            expect(status().className).toBe('visually-hidden');
            expect(status().textContent).toBe('');

            await new Promise((resolve) => setTimeout(resolve, ANNOUNCE_DELAY_MS + 20));

            // ... then the hint and the proposal arrive as a change.
            expect(status().textContent).toBe(hint().textContent + ' Our team at the office in Leipzig');
        });

        it('does not announce a proposal over the status of a generation that started meanwhile', async () => {
            service.generateImage.mockReturnValue(new Promise(() => {}));
            void new ImageDialog(service, { target: TARGET }).show().catch(() => {});

            finish(CONTEXT);
            await flush();
            button('generate').click();
            await new Promise((resolve) => setTimeout(resolve, ANNOUNCE_DELAY_MS + 20));

            expect(status().textContent).not.toContain('Our team at the office in Leipzig');
            expect(status().className).toBe('mt-3');
        });

        it('announces nothing when the editor typed a description first', async () => {
            void new ImageDialog(service, { target: TARGET }).show().catch(() => {});
            textarea().value = 'My own description';

            finish(CONTEXT);
            await new Promise((resolve) => setTimeout(resolve, ANNOUNCE_DELAY_MS + 20));

            expect(status().hidden).toBe(true);
            expect(status().textContent).toBe('');
        });

        it('asks with a null target when it has none', () => {
            void new ImageDialog(service).show().catch(() => {});

            expect(service.getImageContext).toHaveBeenCalledWith(null);
        });

        it('shortens a proposal to the length the server accepts', async () => {
            void new ImageDialog(service, { target: TARGET }).show().catch(() => {});

            finish({ ...CONTEXT, proposal: 'x'.repeat(MAX_PROMPT_LENGTH + 50) });
            await flush();

            expect(textarea().value).toHaveLength(MAX_PROMPT_LENGTH);
        });

        it('does not overwrite what the editor typed before the context arrived', async () => {
            void new ImageDialog(service, { target: TARGET }).show().catch(() => {});
            textarea().value = 'My own description';

            finish(CONTEXT);
            await flush();

            expect(textarea().value).toBe('My own description');
            expect(hint().hidden).toBe(true);
            expect(textarea().getAttribute('aria-describedby')).toBe('cowriter-image-prompt-help');
        });

        it('does not fill the description while the image is already being generated', async () => {
            let generatedImage;
            service.generateImage.mockReturnValue(new Promise((resolve) => { generatedImage = resolve; }));
            const result = new ImageDialog(service, { target: TARGET }).show();
            textarea().value = 'A lighthouse';
            button('generate').click();
            await flush();
            expect(textarea().disabled).toBe(true);
            textarea().value = '';

            finish(CONTEXT);
            await flush();

            expect(textarea().value).toBe('');
            expect(hint().hidden).toBe(true);
            generatedImage(generated);
            await result;
        });

        it('shows the purpose without the field name for the rich text editor', async () => {
            void new ImageDialog(service, { target: TARGET }).show().catch(() => {});

            finish(CONTEXT);
            await flush();

            expect(purpose().hidden).toBe(false);
            expect(purpose().querySelector('strong').textContent).toBe('Purpose: ');
            expect(purpose().textContent).toBe('Purpose: Image of the content element "Our team"');
        });

        it('adds the field name to the purpose for a file field', async () => {
            void new ImageDialog(service, { target: TARGET, showField: true }).show().catch(() => {});

            finish(CONTEXT);
            await flush();

            expect(purpose().textContent).toBe('Purpose: Image of the content element "Our team", Field "Images"');
        });

        it('shows the field name alone when the server knows no purpose', async () => {
            void new ImageDialog(service, { target: TARGET, showField: true }).show().catch(() => {});

            finish({ ...CONTEXT, purpose: '' });
            await flush();

            expect(purpose().textContent).toBe('Purpose: Field "Images"');
        });

        it('keeps the purpose line hidden when neither a purpose nor a field is known', async () => {
            void new ImageDialog(service, { target: TARGET, showField: true }).show().catch(() => {});

            finish({ ...CONTEXT, purpose: '', field: '' });
            await flush();

            expect(purpose().hidden).toBe(true);
            expect(purpose().textContent).toBe('');
        });

        it('puts a purpose with markup in as text', async () => {
            const payload = '<img src=x onerror="window.pwned=1">';
            void new ImageDialog(service, { target: TARGET, showField: true }).show().catch(() => {});

            finish({ ...CONTEXT, purpose: payload, field: payload });
            await flush();

            expect(purpose().querySelector('img')).toBeNull();
            expect(purpose().textContent).toBe(`Purpose: ${payload}, Field "${payload}"`);
        });

        it('renders "About this suggestion" into its slot, after the status line', async () => {
            void new ImageDialog(service, { target: TARGET }).show().catch(() => {});

            finish(CONTEXT);
            await flush();

            const slot = modal().querySelector('[data-role="image-about"]');
            const section = slot.querySelector('.cowriter-about');
            expect(section).not.toBeNull();
            expect(modal().querySelectorAll('.cowriter-about')).toHaveLength(1);
            expect(status().compareDocumentPosition(slot) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
            expect(section.querySelector('dd').textContent).toBe('Cowriter, image generation');
        });

        it('changes nothing when the server has no context', async () => {
            void new ImageDialog(service, { target: TARGET, showField: true }).show().catch(() => {});

            finish(null);
            await flush();

            expect(textarea().value).toBe('');
            expect(purpose().hidden).toBe(true);
            expect(hint().hidden).toBe(true);
            expect(textarea().getAttribute('aria-describedby')).toBe('cowriter-image-prompt-help');
            expect(modal().querySelector('.cowriter-about')).toBeNull();
        });

        it('works with a service that cannot fetch a context', async () => {
            delete service.getImageContext;
            const prefill = vi.spyOn(ImageDialog.prototype, '_prefill');
            const result = new ImageDialog(service, { target: TARGET }).show();
            await flush();

            // _prefill runs unawaited: it must settle without an error of its own.
            await expect(prefill.mock.results[0].value).resolves.toBeUndefined();
            prefill.mockRestore();
            expect(purpose().hidden).toBe(true);
            textarea().value = 'A lighthouse';
            button('generate').click();
            await expect(result).resolves.toEqual(generated);
        });
    });

    it('hands over a failure that arrives after the dialog was closed', async () => {
        let fail;
        service.generateImage.mockReturnValue(new Promise((_resolve, reject) => { fail = reject; }));
        const result = new ImageDialog(service).show();
        textarea().value = 'A lighthouse';
        button('generate').click();
        await flush();
        button('cancel').click();

        fail(new Error('The image request timed out'));
        await expect(result).rejects.toThrow('The image request timed out');
    });
});
