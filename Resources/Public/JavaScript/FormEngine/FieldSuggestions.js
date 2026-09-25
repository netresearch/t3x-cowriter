/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * "Suggest" field control for FormEngine text fields.
 *
 * Asks the backend for N suggestions and lists them below the field. Picking
 * one writes it into the form field the same way core field controls do
 * (value + change event + validation + "changed" marker); nothing is saved
 * until the editor saves the record.
 *
 * Keyboard: the control opens and closes the list (Enter or Space), the
 * arrow keys, Home and End move between suggestions, Escape closes the list
 * and returns focus to the control, picking a suggestion moves focus to the
 * filled field. Loading, results and errors are
 * announced through a polite live region.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import DocumentService from '@typo3/core/document-service.js';
import FormEngine from '@typo3/backend/form-engine.js';
import FormEngineValidation from '@typo3/backend/form-engine-validation.js';

const DEFAULT_LABELS = {
    heading: 'AI suggestions',
    loading: 'Generating suggestions…',
    loaded: '%d suggestions available. Choose one to insert it into the field.',
    empty: 'No suggestions were returned.',
    error: 'The suggestions could not be loaded.',
    inserted: 'Suggestion inserted. Save the record to keep it.',
    close: 'Close suggestions',
};

export class FieldSuggestions {
    /**
     * @param {string} controlId id of the field control anchor rendered by FieldSuggestionsControl
     */
    constructor(controlId) {
        this.control = null;
        this.panel = null;
        this.list = null;
        this.status = null;
        this.request = null;
        // Increases with every open and close: a request whose token is no
        // longer current was superseded, and its result or error is ignored.
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
            } else if (event.key === 'Escape' && this.isOpen()) {
                event.preventDefault();
                this.close();
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
        this.panel.className = 'cowriter-suggestions mt-2';
        this.panel.hidden = true;
        this.panel.setAttribute('role', 'group');
        this.panel.setAttribute('aria-labelledby', id + '-heading');

        const header = document.createElement('div');
        header.className = 'd-flex align-items-center justify-content-between mb-1';
        const heading = document.createElement('strong');
        heading.id = id + '-heading';
        heading.textContent = this.labels.heading;
        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'btn btn-sm btn-default cowriter-suggestions-close';
        closeButton.setAttribute('aria-label', this.labels.close);
        closeButton.textContent = '×';
        closeButton.addEventListener('click', () => this.close());
        header.append(heading, closeButton);

        this.list = document.createElement('div');
        this.list.className = 'list-group cowriter-suggestions-list';

        this.panel.append(header, this.list);
        this.panel.addEventListener('keydown', (event) => this.onPanelKeydown(event));

        // The live region stays outside the panel so the confirmation after
        // inserting a value is still announced once the panel is closed.
        this.status = document.createElement('p');
        this.status.id = id + '-status';
        this.status.className = 'form-text cowriter-suggestions-status mb-0';
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
        this.list.replaceChildren();
        this.setStatus(this.labels.loading, false);
        this.control.setAttribute('aria-busy', 'true');
        const token = ++this.requestToken;

        try {
            const suggestions = await this.fetchSuggestions();
            if (token !== this.requestToken) {
                return;
            }
            if (suggestions.length === 0) {
                this.setStatus(this.labels.empty, true);
                return;
            }
            this.renderSuggestions(suggestions);
            this.setStatus(this.labels.loaded.replace('%d', String(suggestions.length)), false);
            this.list.querySelector('button')?.focus();
        } catch (error) {
            if (token === this.requestToken) {
                this.setStatus(error instanceof Error && error.message !== '' ? error.message : this.labels.error, true);
            }
        } finally {
            if (token === this.requestToken) {
                this.control.removeAttribute('aria-busy');
            }
        }
    }

    /**
     * @param {HTMLElement|null} focusTarget where focus goes; the control by default
     */
    close(focusTarget = null) {
        this.requestToken++;
        this.request?.abort();
        this.request = null;
        this.panel.hidden = true;
        this.control.setAttribute('aria-expanded', 'false');
        this.control.removeAttribute('aria-busy');
        (focusTarget || this.control).focus();
    }

    /**
     * @returns {Promise<string[]>}
     */
    async fetchSuggestions() {
        const data = this.control.dataset;
        const url = data.url
            || (typeof TYPO3 !== 'undefined' ? TYPO3.settings?.ajaxUrls?.tx_cowriter_suggestions : undefined);
        if (!url) {
            throw new Error(this.labels.error);
        }

        const field = this.findField();
        this.request = new AjaxRequest(url);
        let response;
        try {
            response = await this.request.post(
                {
                    table: data.table,
                    field: data.field,
                    uid: data.uid,
                    pid: data.pid,
                    count: Number(data.count) || 3,
                    currentValue: field ? field.value : '',
                },
                { headers: { 'Content-Type': 'application/json' } },
            );
        } catch (failure) {
            throw new Error(await this.errorMessage(failure), { cause: failure });
        }

        const result = await response.resolve();
        if (!result || result.success !== true || !Array.isArray(result.suggestions)) {
            throw new Error(result && typeof result.error === 'string' ? result.error : this.labels.error);
        }
        return result.suggestions.filter((value) => typeof value === 'string' && value !== '');
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

    /**
     * @param {string[]} suggestions
     */
    renderSuggestions(suggestions) {
        const buttons = suggestions.map((suggestion) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action cowriter-suggestion';
            // textContent: the model's answer is never parsed as markup.
            button.textContent = suggestion;
            button.addEventListener('click', () => this.pick(suggestion));
            return button;
        });
        this.list.replaceChildren(...buttons);
    }

    onPanelKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.close();
            return;
        }

        const buttons = Array.from(this.list.querySelectorAll('button'));
        const index = buttons.indexOf(document.activeElement);
        if (buttons.length === 0 || index === -1) {
            return;
        }

        const targets = {
            ArrowDown: (index + 1) % buttons.length,
            ArrowUp: (index - 1 + buttons.length) % buttons.length,
            Home: 0,
            End: buttons.length - 1,
        };
        if (event.key in targets) {
            event.preventDefault();
            buttons[targets[event.key]].focus();
        }
    }

    /**
     * @param {string} value
     */
    pick(value) {
        const field = this.findField();
        if (field) {
            this.applyValue(field, value);
        }
        // Focus goes to the filled field, where the editor continues.
        this.close(field);
        this.setStatus(this.labels.inserted, false);
    }

    findField() {
        const itemName = this.control.dataset.itemName;
        if (!itemName) {
            return null;
        }
        return document.querySelector('[data-formengine-input-name="' + CSS.escape(itemName) + '"]');
    }

    /**
     * @param {HTMLInputElement|HTMLTextAreaElement} field
     * @param {string} value
     */
    applyValue(field, value) {
        if (field.classList.contains('t3js-form-field-slug-input')) {
            // TYPO3's slug element: show its editable input, then let its own
            // "input" handler sanitise the value, check uniqueness and update
            // the hidden field that is submitted.
            const wrap = field.closest('.form-control-wrap');
            wrap?.querySelector('.t3js-form-field-slug-readonly')?.classList.add('hidden');
            field.classList.remove('hidden');
            field.value = value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
            return;
        }

        // Same sequence as core's password generator field control.
        field.value = value;
        field.dispatchEvent(new Event('change', { bubbles: true }));
        FormEngineValidation.validateField(field);
        FormEngine.markFieldAsChanged(field);
    }

    setStatus(message, isError) {
        this.status.textContent = message;
        this.status.classList.toggle('text-danger', isError);
    }
}

export default FieldSuggestions;
