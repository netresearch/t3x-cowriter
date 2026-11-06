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
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import { showAbout } from '@netresearch/t3_cowriter/AboutSection';
import { sanitizeHtml } from '@netresearch/t3_cowriter/HtmlSanitizer';
import DocumentService from '@typo3/core/document-service.js';
import Viewport from '@typo3/backend/viewport.js';

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

export class AudienceVariant {
    /**
     * @param {string} controlId id of the field control anchor rendered by VariantControl
     */
    constructor(controlId) {
        this.control = null;
        this.panel = null;
        this.status = null;
        this.request = null;
        this.choice = null;
        this.proposal = null;
        this.created = null;
        this.contextChoice = '0';
        this.shown = '';
        // A write in flight: the server may write even if the browser stops
        // waiting, so the panel stays open until it answers.
        this.busy = false;
        // Increases with every open and close: an answer to a superseded
        // request is ignored.
        this.requestToken = 0;
        this.ready = DocumentService.ready().then(() => this.initialize(document.getElementById(controlId)));
    }

    /**
     * @param {HTMLElement|null} control
     */
    initialize(control) {
        if (!control) {
            return;
        }
        this.control = control;
        this.labels = this.readLabels(control.dataset);
        this.options = this.readOptions(control.dataset.options);
        this.buildPanel();

        control.addEventListener('click', (event) => {
            event.preventDefault();
            this.toggle();
        });
        control.addEventListener('keydown', (event) => {
            // The control is an <a role="button">: Space must act like Enter.
            if (event.key === ' ') {
                event.preventDefault();
                this.toggle();
            }
        });
    }

    readLabels(dataset) {
        const labels = { ...DEFAULT_LABELS };
        for (const key of Object.keys(DEFAULT_LABELS)) {
            const value = dataset['label' + key.charAt(0).toUpperCase() + key.slice(1)];
            if (typeof value === 'string' && value !== '') {
                labels[key] = value;
            }
        }
        return labels;
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

    buildPanel() {
        const id = this.control.id;

        this.panel = document.createElement('div');
        this.panel.id = id + '-panel';
        this.panel.className = 'cowriter-variant card card-body mt-2';
        this.panel.hidden = true;
        this.panel.setAttribute('role', 'group');
        this.panel.setAttribute('aria-labelledby', id + '-heading');
        this.panel.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                this.close();
            }
        });

        // The live region stays outside the panel, so the result is still
        // announced once the panel is closed.
        this.status = document.createElement('p');
        this.status.id = id + '-status';
        this.status.className = 'form-text cowriter-variant-status mb-0';
        this.status.setAttribute('role', 'status');
        this.status.setAttribute('aria-live', 'polite');

        const anchor = this.control.closest('.form-wizards-wrap') || this.control.parentElement;
        anchor.after(this.panel, this.status);
    }

    isOpen() {
        return this.panel !== null && !this.panel.hidden;
    }

    toggle() {
        if (this.isOpen()) {
            this.close();
        } else {
            this.open();
        }
    }

    open() {
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

    close() {
        if (this.busy) {
            return;
        }
        this.requestToken++;
        this.request?.abort();
        this.request = null;
        this.panel.hidden = true;
        this.control.setAttribute('aria-expanded', 'false');
        this.control.removeAttribute('aria-busy');
        this.control.focus();
    }

    heading() {
        const heading = document.createElement('strong');
        heading.id = this.control.id + '-heading';
        heading.className = 'd-block mb-2';
        heading.textContent = this.labels.heading;
        return heading;
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
        body.replaceChildren(...Array.from(sanitizeHtml(proposal.bodytext).childNodes));
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
                bodytext: sanitizeHtml(this.proposal.bodytext).innerHTML,
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

    button(label, className, onClick) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.textContent = label;
        button.addEventListener('click', () => onClick(button));
        return button;
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

    /**
     * Loads $url in the backend's content frame. The content container asks
     * FormEngine first, which shows its "unsaved changes" dialog when this
     * form has changes.
     *
     * @param {string} url
     */
    navigate(url) {
        Viewport.ContentContainer.setUrl(url).catch(() => {
            // Rejected when the editor stays: FormEngine's "unsaved changes"
            // dialog was cancelled. Nothing to report, the form is unchanged.
        });
    }

    /**
     * The actions handle their own failures; anything they did not foresee
     * is shown in the status line instead of getting lost.
     *
     * @param {Promise<void>} action
     */
    report(action) {
        action.catch((error) => this.setStatus(this.messageOf(error), true));
    }

    /**
     * POST JSON and return the answer of a successful request; throw with the
     * server's own message otherwise.
     */
    async post(url, body) {
        if (!url) {
            throw new Error(this.labels.error);
        }

        this.request = new AjaxRequest(url);
        let response;
        try {
            response = await this.request.post(body, { headers: { 'Content-Type': 'application/json' } });
        } catch (failure) {
            throw new Error(await this.errorMessage(failure), { cause: failure });
        }

        const result = await response.resolve();
        if (!result || result.success !== true) {
            throw new Error(result && typeof result.error === 'string' ? result.error : this.labels.error);
        }
        return result;
    }

    /**
     * The server's own message for a failed request, if it sent one.
     */
    async errorMessage(failure) {
        try {
            const body = await failure?.resolve?.();
            if (body && typeof body.error === 'string' && body.error !== '') {
                return body.error;
            }
        } catch {
            // Not a JSON answer: fall back to the generic message.
        }
        return this.labels.error;
    }

    messageOf(error) {
        return error instanceof Error && error.message !== '' ? error.message : this.labels.error;
    }

    setStatus(message, isError) {
        this.status.textContent = message;
        this.status.classList.toggle('text-danger', isError);
    }
}

export default AudienceVariant;
