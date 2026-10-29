/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * Tests for the "Generate image" dialog. The TYPO3 modal is mocked via
 * vitest.config.js alias.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ImageDialog, MAX_PROMPT_LENGTH } from '../../Resources/Public/JavaScript/Ckeditor/ImageDialog.js';

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
