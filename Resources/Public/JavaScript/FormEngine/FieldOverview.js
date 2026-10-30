/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * "Suggest values for all fields" in the document header of the record form.
 *
 * The button is rendered hidden by AddFieldOverviewButtonListener; attach()
 * reveals it once the form holds at least one "Suggest" field control of the
 * edited table, so it only appears where an editable suggestion field exists.
 *
 * One request asks for all those fields at once. The answer is shown in a
 * modal table: per field the current value, an editable suggestion and the
 * reason, with a checkbox that is checked where a change is suggested.
 * "Apply selected" writes the checked values into the form fields (value,
 * change event, validation, "changed" marker); nothing is saved until the
 * editor saves the record.
 *
 * Keyboard and screen readers: the button reacts to Enter and Space, the
 * table has a caption, row headers and a labelled checkbox and input per row,
 * progress and errors are announced through a live region, focus moves to the
 * first checkbox when the list is loaded and back to the button when the
 * dialog closes.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import { applyValue, findField } from '@netresearch/t3_cowriter/FieldValue';

export const BUTTON_SELECTOR = '.t3js-cowriter-suggest-all';

/**
 * Fields allowed more characters than this are edited in a text area.
 */
const LONG_TEXT_LENGTH = 120;

const CONTROL_SELECTOR = '[id^="t3js-cowriter-suggestions-"][data-field][data-item-name]';

const DEFAULT_LABELS = {
    title: 'AI suggestions for all fields',
    loading: 'Reviewing all fields…',
    loaded: '%d of %d fields have a suggestion. Check the values you want to apply.',
    error: 'The suggestions could not be loaded.',
    empty: 'No suggestions were returned.',
    caption: 'Suggested values for the fields of this record',
    columnApply: 'Apply',
    columnField: 'Field',
    columnCurrent: 'Current value',
    columnSuggestion: 'Suggestion',
    columnReason: 'Reason',
    keep: 'No change suggested',
    emptyValue: '(empty)',
    apply: 'Apply selected',
    cancel: 'Cancel',
    applied: '%d values applied. Please review them and save the record.',
    appliedSingular: '1 value applied. Please review it and save the record.',
    appliedTitle: 'Suggestions applied',
    nothingSelected: 'No value is selected.',
    applyRow: 'Apply the suggestion for "%s"',
    suggestionFor: 'Suggestion for "%s"',
};

export class FieldOverview {
    /**
     * Reveal and wire the document-header button of $doc, once.
     *
     * @param {Document} doc
     * @returns {FieldOverview|null}
     */
    static attach(doc = document) {
        const button = doc.querySelector(BUTTON_SELECTOR);
        if (!button || button.dataset.cowriterAttached === '1') {
            return null;
        }
        const overview = new FieldOverview(button);
        if (overview.controls().length === 0) {
            return null;
        }
        button.dataset.cowriterAttached = '1';
        overview.initialize();
        return overview;
    }

    /**
     * @param {HTMLElement} button
     */
    constructor(button) {
        this.button = button;
        this.labels = readLabels(button.dataset);
        this.modal = null;
        this.request = null;
        this.status = null;
        this.rows = [];
    }

    initialize() {
        this.button.classList.remove('d-none');
        this.button.setAttribute('role', 'button');
        this.button.setAttribute('aria-haspopup', 'dialog');
        this.button.addEventListener('click', (event) => {
            event.preventDefault();
            this.report(this.open());
        });
        this.button.addEventListener('keydown', (event) => {
            // The button is an <a role="button">: Space must act like Enter.
            if (event.key === ' ') {
                event.preventDefault();
                this.report(this.open());
            }
        });
    }

    /**
     * The "Suggest" field controls of the edited record, one per field.
     *
     * @returns {HTMLElement[]}
     */
    controls() {
        const table = this.button.dataset.table;
        const all = Array.from(this.button.ownerDocument.querySelectorAll(CONTROL_SELECTOR))
            .filter((control) => control.dataset.table === table);
        if (all.length === 0) {
            return [];
        }
        // Inline child records of the same table would have other uids.
        const uid = all[0].dataset.uid;
        const seen = new Set();
        return all.filter((control) => {
            if (control.dataset.uid !== uid || seen.has(control.dataset.field)) {
                return false;
            }
            seen.add(control.dataset.field);
            return true;
        });
    }

    async open() {
        const controls = this.controls();
        if (controls.length === 0 || this.modal) {
            return;
        }

        const container = document.createElement('div');
        container.className = 'cowriter-field-overview';
        this.status = document.createElement('p');
        this.status.className = 'cowriter-field-overview-status';
        this.status.setAttribute('role', 'status');
        this.status.setAttribute('aria-live', 'polite');
        this.results = document.createElement('div');
        container.append(this.status, this.results);

        this.modal = Modal.advanced({
            title: this.labels.title,
            content: container,
            size: Modal.sizes.large,
            buttons: [
                { text: this.labels.cancel, btnClass: 'btn-default', name: 'cancel', trigger: () => this.close() },
                { text: this.labels.apply, btnClass: 'btn-primary', name: 'apply', trigger: () => this.apply() },
            ],
        });
        this.modal?.addEventListener?.('typo3-modal-hidden', () => {
            this.request?.abort();
            this.request = null;
            this.modal = null;
            this.rows = [];
            this.button.focus();
        });

        // Nothing to apply until the table is there. The core modal renders
        // its footer only after Modal.advanced() has returned.
        this.setApplyEnabled(false);
        this.modal?.updateComplete?.then?.(() => this.setApplyEnabled(this.rows.length > 0));
        this.setStatus(this.labels.loading, false);
        container.setAttribute('aria-busy', 'true');
        const modal = this.modal;
        try {
            const fields = await this.fetchFields(controls);
            if (modal !== this.modal) {
                return;
            }
            if (fields.length === 0) {
                this.setStatus(this.labels.empty, true);
                return;
            }
            this.renderTable(fields, controls);
            const changes = fields.filter((field) => field.action === 'change').length;
            this.setStatus(format(this.labels.loaded, changes, fields.length), false);
            this.setApplyEnabled(this.rows.length > 0);
            // The first suggestion; when every field is fine as it is, the
            // table caption, so the reader lands on the result either way.
            (this.results.querySelector('input[type="checkbox"]') ?? this.results.querySelector('caption'))?.focus();
        } catch (error) {
            if (modal === this.modal) {
                this.setStatus(error instanceof Error && error.message !== '' ? error.message : this.labels.error, true);
            }
        } finally {
            container.removeAttribute('aria-busy');
        }
    }

    close() {
        this.modal?.hideModal();
    }

    /**
     * open() handles the failures of the request itself; anything it did not
     * foresee (the dialog could not be opened, for example) is shown as an
     * error notification instead of getting lost.
     *
     * @param {Promise<void>} action
     */
    report(action) {
        action.catch((error) => {
            Notification.error(
                this.labels.title,
                error instanceof Error && error.message !== '' ? error.message : this.labels.error,
            );
        });
    }

    /**
     * @param {HTMLElement[]} controls
     * @returns {Promise<object[]>}
     */
    async fetchFields(controls) {
        const url = this.button.dataset.url;
        if (!url) {
            throw new Error(this.labels.error);
        }

        const fields = {};
        for (const control of controls) {
            const field = findField(control.dataset.itemName, control.ownerDocument);
            fields[control.dataset.field] = field ? field.value : '';
        }

        this.request = new AjaxRequest(url);
        let response;
        try {
            response = await this.request.post(
                {
                    table: this.button.dataset.table,
                    uid: controls[0].dataset.uid,
                    pid: controls[0].dataset.pid,
                    fields,
                },
                { headers: { 'Content-Type': 'application/json' } },
            );
        } catch (failure) {
            throw new Error(await this.errorMessage(failure), { cause: failure });
        }

        const result = await response.resolve();
        if (result?.success !== true || !Array.isArray(result.fields)) {
            throw new Error(typeof result?.error === 'string' ? result.error : this.labels.error);
        }
        const known = new Set(controls.map((control) => control.dataset.field));
        return result.fields.filter((entry) => entry
            && typeof entry.field === 'string' && known.has(entry.field)
            && (entry.action === 'change' || entry.action === 'keep')
            && typeof entry.reason === 'string');
    }

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
     * Everything the model wrote goes in as text (textContent, input value),
     * never as markup.
     *
     * @param {object[]} fields
     * @param {HTMLElement[]} controls
     */
    renderTable(fields, controls) {
        const doc = this.results.ownerDocument;
        const table = doc.createElement('table');
        table.className = 'table table-striped cowriter-field-overview-table';
        const caption = doc.createElement('caption');
        caption.textContent = this.labels.caption;
        caption.tabIndex = -1;
        table.append(caption);

        const head = doc.createElement('thead');
        const headRow = doc.createElement('tr');
        for (const key of ['columnApply', 'columnField', 'columnCurrent', 'columnSuggestion', 'columnReason']) {
            const th = doc.createElement('th');
            th.scope = 'col';
            th.textContent = this.labels[key];
            headRow.append(th);
        }
        head.append(headRow);
        table.append(head);

        const body = doc.createElement('tbody');
        const controlByField = new Map(controls.map((control) => [control.dataset.field, control]));
        this.rows = [];
        fields.forEach((entry, index) => {
            const label = typeof entry.label === 'string' && entry.label !== '' ? entry.label : entry.field;
            const row = doc.createElement('tr');
            row.dataset.field = entry.field;

            const applyCell = doc.createElement('td');
            const fieldCell = doc.createElement('th');
            fieldCell.scope = 'row';
            fieldCell.textContent = label;
            const currentCell = doc.createElement('td');
            const current = typeof entry.current === 'string' ? entry.current : '';
            currentCell.textContent = current !== '' ? current : this.labels.emptyValue;
            const suggestionCell = doc.createElement('td');
            const reasonCell = doc.createElement('td');
            reasonCell.textContent = entry.reason;

            if (entry.action === 'change' && typeof entry.value === 'string' && entry.value !== '') {
                const id = 'cowriter-field-overview-' + index;
                const checkbox = doc.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.className = 'form-check-input';
                checkbox.id = id + '-apply';
                checkbox.checked = true;
                checkbox.setAttribute('aria-label', format(this.labels.applyRow, label));

                // Long texts (descriptions, abstract) get a text area.
                const long = entry.kind !== 'slug' && Number(entry.maxLength) > LONG_TEXT_LENGTH;
                const input = doc.createElement(long ? 'textarea' : 'input');
                if (long) {
                    input.rows = 3;
                } else {
                    input.type = 'text';
                }
                input.className = 'form-control cowriter-field-overview-value';
                input.style.minWidth = '18rem';
                input.id = id + '-value';
                input.value = entry.value;
                input.setAttribute('aria-label', format(this.labels.suggestionFor, label));
                input.setAttribute('aria-describedby', id + '-reason');
                if (entry.kind !== 'slug' && Number(entry.maxLength) > 0) {
                    input.maxLength = Number(entry.maxLength);
                }
                // Editing a suggestion means the editor wants it.
                input.addEventListener('input', () => {
                    checkbox.checked = true;
                });
                reasonCell.id = id + '-reason';

                // TYPO3's backend styles size .form-check-input only inside .form-check.
                const check = doc.createElement('div');
                check.className = 'form-check';
                check.append(checkbox);
                applyCell.append(check);
                suggestionCell.append(input);
                this.rows.push({ checkbox, input, control: controlByField.get(entry.field) });
            } else {
                const keep = doc.createElement('em');
                keep.textContent = this.labels.keep;
                suggestionCell.append(keep);
            }

            row.append(applyCell, fieldCell, currentCell, suggestionCell, reasonCell);
            body.append(row);
        });
        table.append(body);

        const wrap = doc.createElement('div');
        wrap.className = 'table-fit';
        wrap.append(table);
        this.results.replaceChildren(wrap);
    }

    /**
     * Write the checked suggestions into the form and close the dialog.
     *
     * @returns {number} how many values were written
     */
    /**
     * @param {boolean} enabled
     */
    setApplyEnabled(enabled) {
        const button = this.modal?.querySelector?.('button[name="apply"]');
        if (button) {
            button.disabled = !enabled;
        }
    }

    apply() {
        let applied = 0;
        for (const row of this.rows) {
            const value = row.input.value.trim();
            if (!row.checkbox.checked || value === '' || !row.control) {
                continue;
            }
            const field = findField(row.control.dataset.itemName, row.control.ownerDocument);
            if (field) {
                applyValue(field, value);
                applied++;
            }
        }

        if (applied === 0) {
            this.setStatus(this.labels.nothingSelected, true);
            return 0;
        }

        this.close();
        Notification.success(
            this.labels.appliedTitle,
            applied === 1 ? this.labels.appliedSingular : format(this.labels.applied, applied),
        );
        return applied;
    }

    setStatus(message, isError) {
        this.status.textContent = message;
        this.status.classList.toggle('text-danger', isError);
    }
}

/**
 * Replace each %d or %s in order.
 *
 * @param {string} text
 * @param {...(string|number)} values
 * @returns {string}
 */
function format(text, ...values) {
    let index = 0;
    return text.replace(/%[ds]/g, (match) => (index < values.length ? String(values[index++]) : match));
}

function readLabels(dataset) {
    const labels = { ...DEFAULT_LABELS };
    for (const key of Object.keys(DEFAULT_LABELS)) {
        const value = dataset['label' + key.charAt(0).toUpperCase() + key.slice(1)];
        if (typeof value === 'string' && value !== '') {
            labels[key] = value;
        }
    }
    return labels;
}

export default FieldOverview;
