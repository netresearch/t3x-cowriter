/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The panel below a rich text field that a Cowriter field control opens:
 * the base of "Present as tabs or accordion" (AlternativePresentation) and
 * "Create an audience variant" (AudienceVariant).
 *
 * It wires the control (click, and Space because the control is an
 * <a role="button">), builds the panel after the field and a polite live
 * region after the panel, closes on Escape and returns focus to the control,
 * and posts JSON to the routes the control names. A subclass implements
 * open() and sets this.busy while a write is in flight: the server may write
 * even if the browser stops waiting, so the panel stays open until it
 * answers.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import DocumentService from '@typo3/core/document-service.js';
import Viewport from '@typo3/backend/viewport.js';

export class FieldControlPanel {
    /**
     * @param {string} controlId id of the field control anchor
     * @param {Object<string, string>} defaultLabels labels used where the control carries none
     * @param {string} cssClass class of the panel; its status line gets "<class>-status"
     */
    constructor(controlId, defaultLabels, cssClass) {
        this.defaultLabels = defaultLabels;
        this.cssClass = cssClass;
        this.control = null;
        this.panel = null;
        this.status = null;
        this.request = null;
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
        this.configure(control.dataset);
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

    /**
     * Reads what else the control carries; called with the control's dataset
     * before the panel is built. Nothing by default.
     */
    configure() {
        // A subclass reads its own data attributes here.
    }

    readLabels(dataset) {
        const labels = { ...this.defaultLabels };
        for (const key of Object.keys(this.defaultLabels)) {
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
        this.panel.className = this.cssClass + ' card card-body mt-2';
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
        this.status.className = 'form-text ' + this.cssClass + '-status mb-0';
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
            this.report(this.open());
        }
    }

    /**
     * Shows the panel with its current step.
     *
     * @returns {Promise<void>}
     */
    async open() {
        this.panel.hidden = false;
        this.control.setAttribute('aria-expanded', 'true');
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

    button(label, className, onClick) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.textContent = label;
        button.addEventListener('click', () => onClick(button));
        return button;
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
        } catch (error_) {
            throw new Error(await this.errorMessage(error_), { cause: error_ });
        }

        const result = await response.resolve();
        if (result?.success !== true) {
            throw new Error(typeof result?.error === 'string' ? result.error : this.labels.error);
        }
        return result;
    }

    /**
     * The server's own message for a failed request, if it sent one.
     */
    async errorMessage(failure) {
        try {
            const body = await failure?.resolve?.();
            if (typeof body?.error === 'string' && body.error !== '') {
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

export default FieldControlPanel;
