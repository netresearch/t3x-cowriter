/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * "Present as tabs or accordion" field control on the rich text of a text
 * element.
 *
 * Step 1 asks the backend to divide the saved text into sections and shows
 * the proposal below the field: a choice of layout, one editable title per
 * section with the beginning of its text, and a note that the element itself
 * stays unchanged. Step 2, after the editor pressed "Create element", asks the
 * backend to create the new element. Nothing is written before that.
 *
 * Section texts and titles are shown with textContent and value only, never
 * parsed as markup. The request for step 2 carries no markup either: titles
 * and the start blocks of the proposal.
 *
 * Keyboard: the control opens and closes the panel (Enter or Space), Escape in
 * the panel closes it and returns focus to the control. Loading, results and
 * errors are announced through a polite live region.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import DocumentService from '@typo3/core/document-service.js';

const DEFAULT_LABELS = {
    heading: 'Present as tabs or accordion',
    loading: 'Dividing the saved text into sections…',
    proposedHeadings: 'The saved text was divided at its headings. Check the sections, then create the new element.',
    proposedModel: 'The language model proposed these sections for the saved text. Check the titles, then create the new element.',
    layout: 'Present as',
    layoutTab: 'Tabs',
    layoutAccordion: 'Accordion',
    sectionTitle: 'Title of section %d',
    noText: '(no text, only the heading)',
    original: 'A new element is created directly after this one. This element stays unchanged: delete or hide it yourself once the new element is right.',
    create: 'Create element',
    creating: 'Creating the element…',
    created: 'The new element was created directly after this one. This element is unchanged: delete or hide it yourself.',
    open: 'Open the new element',
    cancel: 'Cancel',
    error: 'The sections could not be proposed.',
    titleRequired: 'Every section needs a title.',
};

const LAYOUT_LABELS = { tab: 'layoutTab', accordion: 'layoutAccordion' };

export class AlternativePresentation {
    /**
     * @param {string} controlId id of the field control anchor rendered by PresentationControl
     */
    constructor(controlId) {
        this.control = null;
        this.panel = null;
        this.status = null;
        this.request = null;
        this.proposal = null;
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

    buildPanel() {
        const id = this.control.id;

        this.panel = document.createElement('div');
        this.panel.id = id + '-panel';
        this.panel.className = 'cowriter-presentation card card-body mt-2';
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
        this.status.className = 'form-text cowriter-presentation-status mb-0';
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

    async open() {
        this.panel.hidden = false;
        this.control.setAttribute('aria-expanded', 'true');
        this.panel.replaceChildren(this.heading());
        this.setStatus(this.labels.loading, false);
        this.control.setAttribute('aria-busy', 'true');
        const token = ++this.requestToken;

        try {
            const proposal = await this.post(this.control.dataset.proposeUrl, { uid: Number(this.control.dataset.uid) });
            if (token !== this.requestToken) {
                return;
            }
            if (!Array.isArray(proposal.sections) || proposal.sections.length === 0) {
                throw new Error(this.labels.error);
            }
            this.proposal = proposal;
            this.renderProposal(proposal);
            this.setStatus(proposal.source === 'model' ? this.labels.proposedModel : this.labels.proposedHeadings, false);
            this.panel.querySelector('input')?.focus();
        } catch (error) {
            if (token === this.requestToken) {
                this.setStatus(this.messageOf(error), true);
            }
        } finally {
            if (token === this.requestToken) {
                this.control.removeAttribute('aria-busy');
            }
        }
    }

    close() {
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
     * The layouts both the control and the answer offer, in the answer's order.
     *
     * @param {string[]} offered
     * @returns {string[]}
     */
    layoutsOf(offered) {
        const allowed = (this.control.dataset.layouts || '').split(',').filter((value) => value !== '');
        return (Array.isArray(offered) ? offered : [])
            .filter((layout) => allowed.includes(layout) && layout in LAYOUT_LABELS);
    }

    renderProposal(proposal) {
        const id = this.control.id;
        const form = document.createElement('form');
        form.className = 'cowriter-presentation-form';
        form.noValidate = true;
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            this.create(form);
        });

        const fieldset = document.createElement('fieldset');
        fieldset.className = 'mb-3';
        const legend = document.createElement('legend');
        legend.className = 'form-label fs-6';
        legend.textContent = this.labels.layout;
        fieldset.append(legend);
        this.layoutsOf(proposal.layouts).forEach((layout, index) => {
            const wrap = document.createElement('div');
            wrap.className = 'form-check form-check-inline';
            const input = document.createElement('input');
            input.type = 'radio';
            input.className = 'form-check-input';
            input.name = id + '-layout';
            input.id = id + '-layout-' + layout;
            input.value = layout;
            input.checked = index === 0;
            const label = document.createElement('label');
            label.className = 'form-check-label';
            label.htmlFor = input.id;
            label.textContent = this.labels[LAYOUT_LABELS[layout]];
            wrap.append(input, label);
            fieldset.append(wrap);
        });

        const list = document.createElement('ol');
        list.className = 'list-unstyled cowriter-presentation-sections';
        proposal.sections.forEach((section, index) => {
            const item = document.createElement('li');
            item.className = 'mb-3';
            const label = document.createElement('label');
            label.className = 'form-label';
            label.htmlFor = id + '-title-' + index;
            label.textContent = this.labels.sectionTitle.replace('%d', String(index + 1));
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control';
            input.id = id + '-title-' + index;
            input.maxLength = 100;
            input.required = true;
            input.value = section.title;
            input.dataset.index = String(index);
            const preview = document.createElement('p');
            preview.className = 'form-text mb-0';
            preview.id = id + '-preview-' + index;
            preview.textContent = section.preview !== '' ? section.preview : this.labels.noText;
            input.setAttribute('aria-describedby', preview.id);
            item.append(label, input, preview);
            list.append(item);
        });

        const note = document.createElement('p');
        note.className = 'alert alert-info';
        note.textContent = this.labels.original;

        const buttons = document.createElement('div');
        buttons.className = 'd-flex gap-2';
        const createButton = document.createElement('button');
        createButton.type = 'submit';
        createButton.className = 'btn btn-primary cowriter-presentation-create';
        createButton.textContent = this.labels.create;
        const cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.className = 'btn btn-default';
        cancelButton.textContent = this.labels.cancel;
        cancelButton.addEventListener('click', () => this.close());
        buttons.append(createButton, cancelButton);

        form.append(fieldset, list, note, buttons);
        this.panel.replaceChildren(this.heading(), form);
    }

    /**
     * @param {HTMLFormElement} form
     */
    async create(form) {
        const inputs = Array.from(form.querySelectorAll('input[type="text"]'));
        const empty = inputs.find((input) => input.value.trim() === '');
        inputs.forEach((input) => {
            // An empty aria-invalid counts as "false": the value must be "true".
            if (input.value.trim() === '') {
                input.setAttribute('aria-invalid', 'true');
            } else {
                input.removeAttribute('aria-invalid');
            }
        });
        if (empty) {
            this.setStatus(this.labels.titleRequired, true);
            empty.focus();
            return;
        }

        const layout = form.querySelector('input[type="radio"]:checked')?.value;
        const sections = this.proposal.sections.map((section, index) => ({
            title: inputs[index].value.trim(),
            start: section.start,
            consumesHeading: section.consumesHeading === true,
        }));

        const submit = form.querySelector('button[type="submit"]');
        submit.disabled = true;
        this.setStatus(this.labels.creating, false);
        const token = ++this.requestToken;

        try {
            const result = await this.post(this.control.dataset.createUrl, {
                uid: Number(this.control.dataset.uid),
                layout,
                fingerprint: this.proposal.fingerprint,
                sections,
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
        }
    }

    renderCreated(result) {
        const message = document.createElement('p');
        message.className = 'mb-2';
        message.textContent = this.labels.created;
        const children = [this.heading(), message];
        if (typeof result.editUrl === 'string' && result.editUrl !== '') {
            const link = document.createElement('a');
            link.className = 'btn btn-default';
            link.href = result.editUrl;
            link.textContent = this.labels.open;
            children.push(link);
        }
        this.panel.replaceChildren(...children);
        this.setStatus(this.labels.created, false);
        this.panel.querySelector('a')?.focus();
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

export default AlternativePresentation;
