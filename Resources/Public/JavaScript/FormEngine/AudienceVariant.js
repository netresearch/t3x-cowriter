/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * "Create an audience variant" field control on the rich text of a text
 * element.
 *
 * Step 1: the editor chooses an audience (and optionally a tone of voice) from
 * the operator's prompt snippets and asks for a proposal. Step 2 shows the
 * model's header in an editable field and its text as a sanitised preview,
 * with the contexts the editor may give the variant. Nothing is written
 * before the editor presses "Create variant". Step 3: the copy exists,
 * hidden; with a context, one button shows it (and optionally hides this
 * element in that context), two more open the page and the variant.
 *
 * The model's text is passed through sanitizeHtml() before it is shown and
 * before it is sent back; everything else is set with textContent or value.
 *
 * Keyboard: the control opens and closes the panel (Enter or Space), Escape in
 * the panel closes it and returns focus to the control. Loading, results and
 * errors are announced through a polite live region.
 */
import { showAbout } from '@netresearch/t3_cowriter/AboutSection';
import { FieldControlPanel } from '@netresearch/t3_cowriter/FieldControlPanel';
import { imageSourcesOf, sanitizeHtml } from '@netresearch/t3_cowriter/HtmlSanitizer';

const DEFAULT_LABELS = {
    heading: 'Create an audience variant',
    audience: 'Audience',
    tone: 'Tone of voice',
    toneNone: 'As in the text',
    propose: 'Propose a variant',
    loading: 'The language model rewrites the saved header and text for the audience…',
    proposed: 'The language model proposed this variant. Check the header and the text, then create the variant.',
    header: 'Header of the variant',
    bodytext: 'Text of the variant (preview)',
    context: 'Show the variant in context',
    contextNone: 'No context: keep the variant hidden',
    original: 'A copy of this element with the text above is created directly after it, hidden. This element stays unchanged.',
    create: 'Create variant',
    creating: 'Creating the variant…',
    created: 'The variant was created directly after this element, hidden, and is assigned to the context "%s". Check it, then show it.',
    open: 'Open the variant',
    openPage: 'Open the page in the Layout module',
    cancel: 'Cancel',
    error: 'The variant could not be proposed.',
    retry: 'Propose again',
    hideOriginal: 'Hide this element in that context',
    show: 'Show the variant',
    showing: 'Showing the variant…',
    shown: 'The variant is visible in its context now, and this element is hidden there.',
    shownOriginalVisible: 'The variant is visible in its context now. This element stays visible there too.',
};

export class AudienceVariant extends FieldControlPanel {
    /**
     * @param {string} controlId id of the field control anchor rendered by VariantControl
     */
    constructor(controlId) {
        super(controlId, DEFAULT_LABELS, 'cowriter-variant');
        this.options = { audiences: [], tones: [] };
        this.choice = null;
        this.proposal = null;
        this.contextChoice = '0';
        this.created = null;
        this.shown = '';
    }

    configure(dataset) {
        this.options = this.readOptions(dataset.options);
    }

    /**
     * @param {string|undefined} json
     * @returns {{audiences: Array<{uid: number, name: string}>, tones: Array<{uid: number, name: string}>}}
     */
    readOptions(json) {
        const valid = (list) => (Array.isArray(list) ? list : [])
            .filter((option) => option && Number.isInteger(option.uid) && option.uid > 0 && typeof option.name === 'string');
        try {
            const parsed = JSON.parse(json || '{}');
            return { audiences: valid(parsed.audiences), tones: valid(parsed.tones) };
        } catch {
            return { audiences: [], tones: [] };
        }
    }

    async open() {
        this.panel.hidden = false;
        this.control.setAttribute('aria-expanded', 'true');
        if (this.created) {
            // The variant exists: show the result again instead of a second proposal.
            this.renderCreated(this.created);
            return;
        }
        if (this.proposal) {
            this.renderProposal(this.proposal);
            return;
        }
        this.renderChoice();
    }

    /**
     * A labelled select with the given options.
     *
     * @param {string} role
     * @param {string} label
     * @param {Array<{value: string, text: string}>} options
     * @param {string} selected
     */
    select(role, label, options, selected = '') {
        const wrap = document.createElement('div');
        wrap.className = 'mb-3';
        const labelElement = document.createElement('label');
        labelElement.className = 'form-label';
        labelElement.htmlFor = this.control.id + '-' + role;
        labelElement.textContent = label;
        const select = document.createElement('select');
        select.className = 'form-select';
        select.id = this.control.id + '-' + role;
        select.dataset.role = role;
        for (const option of options) {
            const element = document.createElement('option');
            element.value = option.value;
            element.textContent = option.text;
            element.selected = option.value === selected;
            select.append(element);
        }
        wrap.append(labelElement, select);
        return wrap;
    }

    /**
     * Step 1: audience and tone.
     */
    renderChoice() {
        const form = document.createElement('form');
        form.className = 'cowriter-variant-choice';
        form.noValidate = true;
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            this.report(this.propose(form));
        });

        const audiences = this.options.audiences.map((option) => ({ value: String(option.uid), text: option.name }));
        form.append(this.select('audience', this.labels.audience, audiences, this.choice ? String(this.choice.audience) : ''));
        if (this.options.tones.length > 0) {
            const tones = [{ value: '0', text: this.labels.toneNone }, ...this.options.tones.map((option) => ({ value: String(option.uid), text: option.name }))];
            form.append(this.select('tone', this.labels.tone, tones, this.choice ? String(this.choice.tone) : '0'));
        }

        const buttons = document.createElement('div');
        buttons.className = 'd-flex gap-2';
        const proposeButton = document.createElement('button');
        proposeButton.type = 'submit';
        proposeButton.className = 'btn btn-primary cowriter-variant-propose';
        proposeButton.textContent = this.labels.propose;
        buttons.append(proposeButton, this.button(this.labels.cancel, 'btn btn-default cowriter-variant-cancel', () => this.close()));

        form.append(buttons);
        this.panel.replaceChildren(this.heading(), form);
        form.querySelector('select')?.focus();
    }

    /**
     * @param {HTMLFormElement} form
     */
    async propose(form) {
        const read = (role) => Number(form.querySelector('select[data-role="' + role + '"]')?.value || 0);
        this.choice = { audience: read('audience'), tone: read('tone') };
        const submit = form.querySelector('button[type="submit"]');
        submit.disabled = true;
        this.setStatus(this.labels.loading, false);
        this.control.setAttribute('aria-busy', 'true');
        const token = ++this.requestToken;

        try {
            const proposal = await this.post(this.control.dataset.proposeUrl, {
                uid: Number(this.control.dataset.uid),
                audience: this.choice.audience,
                tone: this.choice.tone,
            });
            if (token !== this.requestToken) {
                return;
            }
            if (typeof proposal.bodytext !== 'string' || proposal.bodytext === '') {
                throw new Error(this.labels.error);
            }
            this.proposal = proposal;
            this.contextChoice = '0';
            this.renderProposal(proposal);
            this.setStatus(this.labels.proposed, false);
            this.panel.querySelector('input[type="text"]')?.focus();
        } catch (error) {
            if (token === this.requestToken) {
                submit.disabled = false;
                this.setStatus(this.messageOf(error), true);
            }
        } finally {
            if (token === this.requestToken) {
                this.control.removeAttribute('aria-busy');
            }
        }
    }

    /**
     * Step 2: the proposal for review.
     */
    renderProposal(proposal) {
        const id = this.control.id;
        const form = document.createElement('form');
        form.className = 'cowriter-variant-form';
        form.noValidate = true;
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            this.report(this.create(form));
        });

        const headerWrap = document.createElement('div');
        headerWrap.className = 'mb-3';
        const headerLabel = document.createElement('label');
        headerLabel.className = 'form-label';
        headerLabel.htmlFor = id + '-header';
        headerLabel.textContent = this.labels.header;
        const header = document.createElement('input');
        header.type = 'text';
        header.className = 'form-control';
        header.id = id + '-header';
        header.maxLength = 255;
        header.value = typeof proposal.header === 'string' ? proposal.header : '';
        // Closing and reopening the panel keeps what the editor changed.
        header.addEventListener('input', () => { proposal.header = header.value; });
        // Enter in the header does not create the variant at once.
        header.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });
        headerWrap.append(headerLabel, header);

        const bodyWrap = document.createElement('div');
        bodyWrap.className = 'mb-3';
        const bodyLabel = document.createElement('p');
        bodyLabel.className = 'form-label mb-1';
        bodyLabel.id = id + '-bodytext-label';
        bodyLabel.textContent = this.labels.bodytext;
        const body = document.createElement('div');
        body.className = 'cowriter-variant-preview border rounded p-2';
        body.setAttribute('role', 'region');
        body.setAttribute('aria-labelledby', bodyLabel.id);
        body.replaceChildren(...Array.from(this.sanitizedBodytext(proposal).childNodes));
        bodyWrap.append(bodyLabel, body);

        const parts = [headerWrap, bodyWrap];
        const contexts = Array.isArray(proposal.contexts) ? proposal.contexts.filter((context) => context && Number.isInteger(context.uid) && context.uid > 0) : [];
        if (contexts.length > 0) {
            const options = [{ value: '0', text: this.labels.contextNone }, ...contexts.map((context) => ({ value: String(context.uid), text: String(context.title) }))];
            const contextSelect = this.select('context', this.labels.context, options, this.contextChoice || '0');
            contextSelect.querySelector('select').addEventListener('change', (event) => { this.contextChoice = event.target.value; });
            parts.push(contextSelect);
        }
        if (typeof proposal.contextNote === 'string' && proposal.contextNote !== '') {
            parts.push(this.notice('info', proposal.contextNote));
        }
        parts.push(this.notice('info', this.labels.original));

        const buttons = document.createElement('div');
        buttons.className = 'd-flex flex-wrap gap-2';
        const createButton = document.createElement('button');
        createButton.type = 'submit';
        createButton.className = 'btn btn-primary cowriter-variant-create';
        createButton.textContent = this.labels.create;
        buttons.append(
            createButton,
            this.button(this.labels.retry, 'btn btn-default cowriter-variant-retry', () => {
                this.proposal = null;
                this.renderChoice();
            }),
            this.button(this.labels.cancel, 'btn btn-default cowriter-variant-cancel', () => this.close()),
        );
        parts.push(buttons);

        form.append(...parts);
        this.panel.replaceChildren(this.heading(), form);
        // Where the proposal comes from; below the form, outside it.
        showAbout(this.panel, proposal.about);
    }

    /**
     * The proposed text, sanitised. An image of another origin stays only when
     * the saved text the model rewrote already held it, as in the translation.
     *
     * @param {{bodytext: string, originalBodytext?: string}} proposal
     * @returns {HTMLElement}
     */
    sanitizedBodytext(proposal) {
        return sanitizeHtml(proposal.bodytext, imageSourcesOf(proposal.originalBodytext));
    }

    notice(kind, text) {
        const notice = document.createElement('p');
        notice.className = 'alert alert-' + kind + ' cowriter-variant-notice';
        notice.textContent = text;
        return notice;
    }

    /**
     * @param {HTMLFormElement} form
     */
    async create(form) {
        const context = Number(form.querySelector('select[data-role="context"]')?.value || 0);
        const submit = form.querySelector('button[type="submit"]');
        const others = Array.from(form.querySelectorAll('button[type="button"]'));
        submit.disabled = true;
        others.forEach((button) => { button.disabled = true; });
        this.busy = true;
        this.setStatus(this.labels.creating, false);
        const token = ++this.requestToken;

        try {
            const result = await this.post(this.control.dataset.createUrl, {
                uid: Number(this.control.dataset.uid),
                fingerprint: this.proposal.fingerprint,
                header: form.querySelector('input[type="text"]').value.trim(),
                // What the editor saw in the preview, nothing more.
                bodytext: this.sanitizedBodytext(this.proposal).innerHTML,
                context,
            });
            if (token !== this.requestToken) {
                return;
            }
            this.renderCreated(result);
        } catch (error) {
            if (token === this.requestToken) {
                submit.disabled = false;
                this.setStatus(this.messageOf(error), true);
            }
        } finally {
            this.busy = false;
            others.forEach((button) => { button.disabled = false; });
        }
    }

    /**
     * Step 3: the variant exists, hidden. With a context it can be shown here.
     */
    renderCreated(result) {
        this.created = result;
        const id = this.control.id;
        const message = document.createElement('p');
        message.className = 'mb-2 cowriter-variant-result';
        if (this.shown !== '') {
            message.textContent = this.shown;
        } else if (typeof result.note === 'string' && result.note !== '') {
            message.textContent = result.note;
        } else {
            message.textContent = this.labels.created.replace('%s', String(result.contextTitle || ''));
        }

        const parts = [this.heading(), message];
        const buttons = document.createElement('div');
        buttons.className = 'd-flex flex-wrap gap-2';
        if (this.shown === '' && typeof result.showToken === 'string' && result.showToken !== '' && this.control.dataset.showUrl) {
            const wrap = document.createElement('div');
            wrap.className = 'form-check mb-2';
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'form-check-input';
            checkbox.id = id + '-hide-original';
            checkbox.checked = true;
            const label = document.createElement('label');
            label.className = 'form-check-label';
            label.htmlFor = checkbox.id;
            label.textContent = this.labels.hideOriginal;
            wrap.append(checkbox, label);
            parts.push(wrap);
            buttons.append(this.button(this.labels.show, 'btn btn-primary cowriter-variant-show', (button) => this.report(this.showVariant(button, checkbox))));
        }
        if (typeof result.pageUrl === 'string' && result.pageUrl !== '') {
            buttons.append(this.button(this.labels.openPage, 'btn btn-default cowriter-variant-page', () => this.navigate(result.pageUrl)));
        }
        if (typeof result.editUrl === 'string' && result.editUrl !== '') {
            buttons.append(this.button(this.labels.open, 'btn btn-default cowriter-variant-edit', () => this.navigate(result.editUrl)));
        }

        this.panel.replaceChildren(...parts, buttons);
        this.setStatus(message.textContent, false);
        this.panel.querySelector('button, input')?.focus();
    }

    /**
     * Shows the variant, for the variant the creation made only (its token),
     * and hides this element in the variant's context when the box is ticked.
     *
     * @param {HTMLButtonElement} button
     * @param {HTMLInputElement} checkbox
     */
    async showVariant(button, checkbox) {
        const hideOriginal = checkbox.checked;
        button.disabled = true;
        checkbox.disabled = true;
        this.busy = true;
        this.setStatus(this.labels.showing, false);
        try {
            await this.post(this.control.dataset.showUrl, {
                variantUid: this.created.uid,
                originalUid: this.created.originalUid,
                context: this.created.context,
                hideOriginal,
                token: this.created.showToken,
            });
        } catch (error) {
            button.disabled = false;
            checkbox.disabled = false;
            this.setStatus(this.messageOf(error), true);
            return;
        } finally {
            this.busy = false;
        }

        if (hideOriginal) {
            this.syncContextSetting(this.created.originalUid, this.created.context);
        }
        this.shown = hideOriginal ? this.labels.shown : this.labels.shownOriginalVisible;
        this.renderCreated(this.created);
    }

    /**
     * This form still holds the original's old context settings (the
     * "Contexts" tab of EXT:contexts). Without this, saving the form
     * afterwards would show the original in the variant's context again. The
     * stored value is put into the field; it is not marked as changed,
     * because it matches what is stored.
     *
     * @param {number} uid
     * @param {number} context
     */
    syncContextSetting(uid, context) {
        const name = 'data[tt_content][' + uid + '][tx_contexts_settings][' + context + '][tx_contexts]';
        const field = document.querySelector('select[name="' + CSS.escape(name) + '"]');
        if (field) {
            field.value = '0';
        }
    }
}

export default AudienceVariant;
