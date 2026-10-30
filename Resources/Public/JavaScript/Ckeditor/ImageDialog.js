/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

// vim: ts=4 sw=4 expandtab colorcolumn=120
// @ts-check

import Modal from '@typo3/backend/modal.js';
import { t } from '@netresearch/t3_cowriter/Labels';
import { showAbout } from '@netresearch/t3_cowriter/AboutSection';

/**
 * Longest description the server accepts (ImageGenerationRequest::MAX_PROMPT_LENGTH).
 */
export const MAX_PROMPT_LENGTH = 1000;

/**
 * @typedef {Object} GeneratedImage
 * @property {{fileUid: number, fileTable: string, url: string, width: number, height: number, mimeType: string, file: string}} image
 * @property {string} alt
 */

/**
 * Dialog for "image by prompt": the editor describes the image, the server
 * generates and stores it, and the dialog resolves with the stored file.
 *
 * When the dialog knows the record the image is for, it asks the server
 * (tx_cowriter_image_context, no model call) what the image is for, which it
 * shows as its own line, and for a proposal of the description built from
 * the record and its page, which it puts into the empty description field,
 * marked as a proposal. Below, "About this suggestion" says that the
 * Cowriter answers and that only the description is sent.
 *
 * There is no preview step: every generated image is a stored and paid file,
 * so the one the editor asked for is the one that gets inserted. Every text
 * goes into the DOM as text, never as markup.
 */
export class ImageDialog {
    /**
     * @param {import('./AIService.js').AIService} service
     * @param {{returnFocus?: function(): void, promptHelp?: string, generating?: string,
     *     target?: {table?: string, uid?: number|string, field?: string, pid?: number|string}|null,
     *     showField?: boolean}} [options]
     *     promptHelp and generating replace the texts that say where the image
     *     goes; without them the dialog speaks of the rich text editor. target
     *     names the record and field the image is for; showField adds the
     *     field's name to the purpose (file fields).
     */
    constructor(service, options = {}) {
        this._service = service;
        this._returnFocus = options.returnFocus ?? null;
        this._promptHelp = options.promptHelp ?? null;
        this._generating = options.generating ?? null;
        this._target = options.target ?? null;
        this._showField = options.showField === true;
    }

    /**
     * @returns {Promise<GeneratedImage>} rejects with "User cancelled" when the
     *     dialog is closed before an image was requested. Closed while the image
     *     is generated, the dialog keeps waiting, because the image is paid for
     *     and stored anyway: the promise then resolves with the image, or
     *     rejects with the error, once the server answered.
     */
    show() {
        const container = this._buildContent();
        const textarea = /** @type {HTMLTextAreaElement} */ (container.querySelector('[data-role="image-prompt"]'));
        const status = /** @type {HTMLElement} */ (container.querySelector('[data-role="image-status"]'));

        return new Promise((resolve, reject) => {
            /** @type {any} */
            let modal;
            let busy = false;
            let closed = false;

            const setBusy = (value) => {
                busy = value;
                textarea.disabled = value;
                const generate = modal?.querySelector?.('button[name="generate"]');
                if (generate) {
                    generate.disabled = value;
                }
            };

            const showStatus = (text, kind) => {
                status.replaceChildren();
                if (kind === 'busy') {
                    const spinner = document.createElement('span');
                    spinner.className = 'spinner-border spinner-border-sm me-2';
                    spinner.setAttribute('aria-hidden', 'true');
                    status.appendChild(spinner);
                }
                status.appendChild(document.createTextNode(text));
                status.className = kind === 'error' ? 'alert alert-danger mt-3 mb-0' : 'mt-3';
                status.hidden = text === '';
            };

            const generate = async () => {
                if (busy) return;
                const prompt = textarea.value.trim();
                if (prompt === '') {
                    showStatus(t('ckeditor.image.dialog.noPrompt', 'Describe the image first.'), 'error');
                    textarea.focus();
                    return;
                }

                setBusy(true);
                showStatus(
                    this._generating ?? t(
                        'ckeditor.image.dialog.generating',
                        'Generating the image. This can take one or two minutes. '
                        + 'If you close this dialog, the image is still inserted when it is ready, '
                        + 'as long as this record stays open; otherwise it is in your upload folder.',
                    ),
                    'busy',
                );
                try {
                    const result = await this._service.generateImage(prompt);
                    if (result?.success && result.image?.url) {
                        if (!closed) {
                            closed = true;
                            modal.hideModal();
                        }
                        resolve(result);
                        return;
                    }
                    throw new Error(result?.error || t('ckeditor.unknownError', 'Unknown error'));
                } catch (error) {
                    if (closed) {
                        reject(error);
                        return;
                    }
                    showStatus(error?.message || t('ckeditor.unknownError', 'Unknown error'), 'error');
                    setBusy(false);
                }
            };

            modal = Modal.advanced({
                title: t('ckeditor.image.dialog.title', 'Cowriter - Generate image'),
                content: container,
                size: Modal.sizes.medium,
                buttons: [
                    {
                        text: t('ckeditor.dialog.button.cancel', 'Cancel'),
                        btnClass: 'btn-default',
                        name: 'cancel',
                        icon: 'actions-close',
                        trigger: () => modal.hideModal(),
                    },
                    {
                        text: t('ckeditor.image.dialog.generate', 'Generate'),
                        btnClass: 'btn-primary',
                        name: 'generate',
                        icon: 'actions-play',
                        trigger: () => { void generate(); },
                    },
                ],
            });

            modal?.addEventListener?.('typo3-modal-hidden', () => {
                const wasClosed = closed;
                closed = true;
                // Nothing requested yet: nothing to wait for. While an image is
                // generated, the request goes on and settles the promise.
                if (!wasClosed && !busy) {
                    reject(new Error('User cancelled'));
                }
                try {
                    this._returnFocus?.();
                } catch {
                    // The editor may be gone; nothing to focus then.
                }
            });

            modal?.updateComplete?.then?.(() => textarea.focus());
            void this._prefill(container, textarea);
        });
    }

    /**
     * Fill in what the server knows about the image's purpose. The proposal
     * only goes into a description the editor has not started.
     *
     * @param {HTMLElement} container
     * @param {HTMLTextAreaElement} textarea
     * @returns {Promise<void>}
     * @private
     */
    async _prefill(container, textarea) {
        const context = typeof this._service.getImageContext === 'function'
            ? await this._service.getImageContext(this._target)
            : null;
        if (!context) {
            return;
        }

        const purpose = /** @type {HTMLElement} */ (container.querySelector('[data-role="image-purpose"]'));
        const parts = [context.purpose];
        if (this._showField && context.field !== '') {
            parts.push(t('ckeditor.image.dialog.field', 'Field "%s"', context.field));
        }
        const purposeText = parts.filter((part) => part !== '').join(', ');
        if (purposeText !== '') {
            const label = document.createElement('strong');
            label.textContent = t('ckeditor.image.dialog.purpose', 'Purpose') + ': ';
            purpose.replaceChildren(label, document.createTextNode(purposeText));
            purpose.hidden = false;
        }

        if (context.proposal !== '' && textarea.value.trim() === '' && !textarea.disabled) {
            textarea.value = context.proposal.slice(0, MAX_PROMPT_LENGTH);
            const hint = /** @type {HTMLElement} */ (container.querySelector('[data-role="image-proposal"]'));
            hint.hidden = false;
            textarea.setAttribute('aria-describedby', hint.id + ' cowriter-image-prompt-help');
        }

        showAbout(/** @type {HTMLElement} */ (container.querySelector('[data-role="image-about"]')), context.about);
    }

    /**
     * @returns {HTMLElement}
     * @private
     */
    _buildContent() {
        const container = document.createElement('div');
        container.className = 'cowriter-image-dialog';

        // What the image is for; filled in by _prefill(). Not part of the
        // description, which becomes the image's alternative text.
        const purpose = document.createElement('p');
        purpose.className = 'cowriter-image-purpose';
        purpose.dataset.role = 'image-purpose';
        purpose.hidden = true;
        container.appendChild(purpose);

        const label = document.createElement('label');
        label.className = 'form-label';
        label.htmlFor = 'cowriter-image-prompt';
        label.textContent = t('ckeditor.image.dialog.prompt', 'Describe the image');
        container.appendChild(label);

        const textarea = document.createElement('textarea');
        textarea.id = 'cowriter-image-prompt';
        textarea.className = 'form-control';
        textarea.rows = 4;
        textarea.maxLength = MAX_PROMPT_LENGTH;
        textarea.dataset.role = 'image-prompt';
        textarea.setAttribute('aria-describedby', 'cowriter-image-prompt-help');
        // The 13.4 modal focuses [autofocus] once it is shown, and so does the
        // native dialog of 14.3, which otherwise focuses its close button
        // after the focus() below.
        textarea.autofocus = true;
        container.appendChild(textarea);

        const proposal = document.createElement('p');
        proposal.id = 'cowriter-image-proposal';
        proposal.className = 'form-text fst-italic';
        proposal.dataset.role = 'image-proposal';
        proposal.textContent = t(
            'ckeditor.image.dialog.proposal',
            'Proposal built from the page data, not by the model: check and change it before you generate the image.',
        );
        proposal.hidden = true;
        container.appendChild(proposal);

        const help = document.createElement('p');
        help.id = 'cowriter-image-prompt-help';
        help.className = 'form-text';
        help.textContent = this._promptHelp ?? t(
            'ckeditor.image.dialog.promptHelp',
            'The image is stored in your upload folder and inserted at the cursor. '
            + 'Your description becomes its alternative text: check it after inserting.',
        );
        container.appendChild(help);

        const status = document.createElement('div');
        status.dataset.role = 'image-status';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.hidden = true;
        container.appendChild(status);

        const about = document.createElement('div');
        about.dataset.role = 'image-about';
        container.appendChild(about);

        return container;
    }
}
