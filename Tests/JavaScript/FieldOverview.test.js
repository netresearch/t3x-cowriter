/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * Tests for the "Suggest values for all fields" overview.
 *
 * The fixture holds the document-header button AddFieldOverviewButtonListener
 * renders (hidden) and two FormEngine fields with the "Suggest" field control,
 * in the markup InputTextElement renders around a field control.
 */
import { describe, it, expect, beforeEach } from 'vitest';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import FormEngine from '@typo3/backend/form-engine.js';
import FormEngineValidation from '@typo3/backend/form-engine-validation.js';
import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import FieldOverview from '../../Resources/Public/JavaScript/FormEngine/FieldOverview.js';
import FieldSuggestions from '../../Resources/Public/JavaScript/FormEngine/FieldSuggestions.js';

const BUTTON = `<a href="#" role="button" title="Suggest values for all fields with AI"
    class="btn btn-default btn-sm t3js-cowriter-suggest-all d-none"
    data-url="/typo3/ajax/cowriter/suggestions/all?token=dummy" data-table="pages"
    data-label-title="AI suggestions for all fields" data-label-applied="%d values applied.">All fields</a>`;

function field(name, value, uid = '12', table = 'pages') {
    const itemName = `data[${table}][${uid}][${name}]`;
    return `
<div class="formengine-field-item t3js-formengine-field-item">
  <div class="form-wizards-wrap">
    <div class="form-wizards-item-element">
      <input type="text" value="${value}" id="input-${table}-${uid}-${name}" data-formengine-input-name="${itemName}" class="form-control" />
      <input type="hidden" name="${itemName}" value="${value}" />
    </div>
    <div class="form-wizards-item-aside"><div class="btn-group">
      <a id="t3js-cowriter-suggestions-${table}${uid}${name}" role="button" data-item-name="${itemName}"
        data-table="${table}" data-field="${name}" data-uid="${uid}" data-pid="3" data-count="3" href="#"></a>
    </div></div>
  </div>
</div>`;
}

function render(html) {
    document.body.replaceChildren(document.createRange().createContextualFragment(html));
}

function jsonResponse(body) {
    return { resolve: async () => body };
}

const ANSWER = {
    success: true,
    fields: [
        {
            field: 'seo_title', label: 'Title for search engines', kind: 'seoTitle', maxLength: 60,
            current: 'Old title', action: 'change', value: 'Airfryer test 2026', reason: 'Names the main keyword first.',
        },
        {
            field: 'description', label: 'Description', kind: 'metaDescription', maxLength: 160,
            current: 'A good description.', action: 'keep', value: '', reason: 'Already a complete sentence under 160 characters.',
        },
    ],
};

async function openOverview(answer = ANSWER) {
    AjaxRequest.nextPost = () => Promise.resolve(jsonResponse(answer));
    const overview = FieldOverview.attach(document);
    await overview.open();
    return overview;
}

function modal() {
    return document.querySelector('.modal');
}

function footerButton(name) {
    return modal().querySelector(`.modal-footer button[name="${name}"]`);
}

describe('FieldOverview', () => {
    beforeEach(() => {
        AjaxRequest.instances.length = 0;
        AjaxRequest.mockClear();
        FormEngine.markFieldAsChanged.mockClear();
        FormEngineValidation.validateField.mockClear();
        Notification.success.mockClear();
        Notification.error.mockClear();
        render(BUTTON + field('seo_title', 'Old title') + field('description', 'A good description.'));
    });

    it('reveals the hidden button when the form holds a suggestion control of the table', () => {
        const overview = FieldOverview.attach(document);

        const button = document.querySelector('.t3js-cowriter-suggest-all');
        expect(overview).not.toBeNull();
        expect(button.classList.contains('d-none')).toBe(false);
        expect(button.getAttribute('aria-haspopup')).toBe('dialog');
    });

    it('a field control that initialises reveals the button', async () => {
        const control = new FieldSuggestions('t3js-cowriter-suggestions-pages12seo_title');
        await control.ready;

        expect(document.querySelector('.t3js-cowriter-suggest-all').classList.contains('d-none')).toBe(false);
    });

    it('keeps the button hidden without a control of the edited table', () => {
        render(BUTTON + field('header', 'Hello', '40', 'tt_content'));

        expect(FieldOverview.attach(document)).toBeNull();
        expect(document.querySelector('.t3js-cowriter-suggest-all').classList.contains('d-none')).toBe(true);
    });

    it('is attached only once', () => {
        expect(FieldOverview.attach(document)).not.toBeNull();
        expect(FieldOverview.attach(document)).toBeNull();
    });

    it('sends one request with every field and its current form value', async () => {
        document.getElementById('input-pages-12-seo_title').value = 'Typed, not saved';

        await openOverview();

        expect(AjaxRequest.instances).toHaveLength(1);
        const request = AjaxRequest.instances[0];
        expect(request.url).toBe('/typo3/ajax/cowriter/suggestions/all?token=dummy');
        expect(request.post).toHaveBeenCalledWith(
            { table: 'pages', uid: '12', pid: '3', fields: { seo_title: 'Typed, not saved', description: 'A good description.' } },
            { headers: { 'Content-Type': 'application/json' } },
        );
    });

    it('ignores controls of other records in the same form', async () => {
        render(BUTTON + field('seo_title', 'Old title') + field('description', 'Other record', '99'));

        await openOverview();

        expect(AjaxRequest.instances[0].post.mock.calls[0][0].fields).toEqual({ seo_title: 'Old title' });
    });

    it('opens on Enter (click) and on Space', async () => {
        AjaxRequest.nextPost = () => new Promise(() => {});
        FieldOverview.attach(document);
        const button = document.querySelector('.t3js-cowriter-suggest-all');

        button.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true }));

        expect(modal()).not.toBeNull();
        expect(modal().querySelector('[role="status"]').textContent).toBe('Reviewing all fields…');
    });

    it('renders an accessible table with the suggestion checked and the keep row without a checkbox', async () => {
        await openOverview();

        const table = modal().querySelector('table');
        expect(table.querySelector('caption').textContent).toBe('Suggested values for the fields of this record');
        expect(Array.from(table.querySelectorAll('thead th')).map((th) => th.scope)).toEqual(['col', 'col', 'col', 'col', 'col']);

        const [changeRow, keepRow] = table.querySelectorAll('tbody tr');
        expect(changeRow.querySelector('th[scope="row"]').textContent).toBe('Title for search engines');
        const checkbox = changeRow.querySelector('input[type="checkbox"]');
        expect(checkbox.checked).toBe(true);
        // TYPO3's backend styles give a .form-check-input outside .form-check no size.
        expect(checkbox.parentElement.classList.contains('form-check')).toBe(true);
        expect(checkbox.getAttribute('aria-label')).toBe('Apply the suggestion for "Title for search engines"');
        const input = changeRow.querySelector('.cowriter-field-overview-value');
        expect(input.value).toBe('Airfryer test 2026');
        expect(input.maxLength).toBe(60);
        expect(input.getAttribute('aria-label')).toBe('Suggestion for "Title for search engines"');
        expect(document.getElementById(input.getAttribute('aria-describedby')).textContent).toBe('Names the main keyword first.');
        expect(changeRow.cells[2].textContent).toBe('Old title');

        expect(keepRow.querySelector('input')).toBeNull();
        expect(keepRow.cells[3].textContent).toBe('No change suggested');
        expect(keepRow.cells[4].textContent).toBe('Already a complete sentence under 160 characters.');

        expect(modal().querySelector('[role="status"]').textContent).toBe(
            '1 of 2 fields have a suggestion. Check the values you want to apply.',
        );
        expect(document.activeElement).toBe(checkbox);
    });

    it('renders model text as text, never as markup', async () => {
        const answer = structuredClone(ANSWER);
        answer.fields[0].reason = '<img src=x onerror="alert(1)">';
        answer.fields[0].label = '<b>Title</b>';

        await openOverview(answer);

        expect(modal().querySelector('img')).toBeNull();
        expect(modal().querySelector('tbody b')).toBeNull();
        expect(modal().querySelector('tbody tr').cells[4].textContent).toBe('<img src=x onerror="alert(1)">');
    });

    it('drops entries for fields that were not asked for or are malformed', async () => {
        const answer = structuredClone(ANSWER);
        answer.fields.push(
            { field: 'author', action: 'change', value: 'Mallory', reason: 'Not asked for.' },
            { field: 'seo_title', action: 'rewrite', value: 'x', reason: 'Unknown action.' },
            { field: 'description', action: 'change', value: 'x' },
        );

        await openOverview(answer);

        expect(Array.from(modal().querySelectorAll('tbody tr')).map((row) => row.dataset.field)).toEqual(['seo_title', 'description']);
    });

    it('applies the checked, possibly edited values to the form fields and marks them changed', async () => {
        await openOverview();
        const input = modal().querySelector('tbody .cowriter-field-overview-value');
        input.value = 'Airfryer test 2026: the best models';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        const changes = [];
        const target = document.getElementById('input-pages-12-seo_title');
        target.addEventListener('change', () => changes.push(target.value));

        footerButton('apply').click();

        expect(target.value).toBe('Airfryer test 2026: the best models');
        expect(changes).toEqual(['Airfryer test 2026: the best models']);
        expect(FormEngineValidation.validateField).toHaveBeenCalledWith(target);
        expect(FormEngine.markFieldAsChanged).toHaveBeenCalledWith(target);
        expect(document.getElementById('input-pages-12-description').value).toBe('A good description.');
        expect(modal()).toBeNull();
        expect(Notification.success).toHaveBeenCalledWith(
            'Suggestions applied',
            '1 value applied. Please review it and save the record.',
        );
        expect(document.activeElement).toBe(document.querySelector('.t3js-cowriter-suggest-all'));
    });

    it('writes nothing for an unchecked row and says that nothing is selected', async () => {
        await openOverview();
        modal().querySelector('tbody input[type="checkbox"]').checked = false;

        footerButton('apply').click();

        expect(document.getElementById('input-pages-12-seo_title').value).toBe('Old title');
        expect(FormEngine.markFieldAsChanged).not.toHaveBeenCalled();
        expect(modal()).not.toBeNull();
        expect(modal().querySelector('[role="status"]').textContent).toBe('No value is selected.');
    });

    it('editing an unchecked suggestion checks it again', async () => {
        await openOverview();
        const checkbox = modal().querySelector('tbody input[type="checkbox"]');
        checkbox.checked = false;

        const input = modal().querySelector('tbody .cowriter-field-overview-value');
        input.value = 'Edited';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        expect(checkbox.checked).toBe(true);
    });

    it('applies several values with the plural summary', async () => {
        const answer = structuredClone(ANSWER);
        answer.fields[1] = { ...answer.fields[1], action: 'change', value: 'A better description.', reason: 'Adds the keyword.' };

        await openOverview(answer);
        footerButton('apply').click();

        expect(document.getElementById('input-pages-12-description').value).toBe('A better description.');
        expect(Notification.success).toHaveBeenCalledWith('Suggestions applied', '2 values applied.');
    });

    it('cancel changes nothing', async () => {
        await openOverview();

        footerButton('cancel').click();

        expect(modal()).toBeNull();
        expect(document.getElementById('input-pages-12-seo_title').value).toBe('Old title');
        expect(FormEngine.markFieldAsChanged).not.toHaveBeenCalled();
        expect(Notification.success).not.toHaveBeenCalled();
    });

    it('shows the server error in the live region', async () => {
        AjaxRequest.nextPost = () => Promise.reject(jsonResponse({ success: false, error: 'You are not allowed to edit this field.' }));
        const overview = FieldOverview.attach(document);

        await overview.open();

        const status = modal().querySelector('[role="status"]');
        expect(status.textContent).toBe('You are not allowed to edit this field.');
        expect(status.classList.contains('text-danger')).toBe(true);
        expect(modal().querySelector('table')).toBeNull();
    });

    it('a long text is edited in a text area with the field limit', async () => {
        const answer = structuredClone(ANSWER);
        answer.fields[1] = { ...answer.fields[1], action: 'change', value: 'A better description.', reason: 'Adds the keyword.' };

        await openOverview(answer);

        const [title, description] = modal().querySelectorAll('tbody .cowriter-field-overview-value');
        expect(title.tagName).toBe('INPUT');
        expect(description.tagName).toBe('TEXTAREA');
        expect(description.maxLength).toBe(160);
        expect(description.value).toBe('A better description.');
    });

    it('shows a failure to open the dialog as an error notification', async () => {
        const advanced = Modal.advanced;
        Modal.advanced = () => {
            throw new Error('The dialog could not be opened.');
        };
        try {
            FieldOverview.attach(document);
            document.querySelector('.t3js-cowriter-suggest-all').click();
            await new Promise((resolve) => setTimeout(resolve, 0));

            expect(Notification.error).toHaveBeenCalledWith('AI suggestions for all fields', 'The dialog could not be opened.');
        } finally {
            Modal.advanced = advanced;
        }
    });

    it('opening with Space reports a failure the same way', async () => {
        const advanced = Modal.advanced;
        Modal.advanced = () => {
            throw new Error('');
        };
        try {
            FieldOverview.attach(document);
            document.querySelector('.t3js-cowriter-suggest-all')
                .dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true }));
            await new Promise((resolve) => setTimeout(resolve, 0));

            expect(Notification.error).toHaveBeenCalledWith('AI suggestions for all fields', 'The suggestions could not be loaded.');
        } finally {
            Modal.advanced = advanced;
        }
    });

    it('a server answer without success flag shows the generic error', async () => {
        AjaxRequest.nextPost = () => Promise.resolve(jsonResponse(null));

        await FieldOverview.attach(document).open();

        expect(modal().querySelector('[role="status"]').textContent).toBe('The suggestions could not be loaded.');
    });

    it('keeps "Apply selected" disabled until the table is rendered', async () => {
        let finish;
        AjaxRequest.nextPost = () => new Promise((resolve) => { finish = resolve; });
        const overview = FieldOverview.attach(document);

        const opening = overview.open();

        expect(footerButton('apply').disabled).toBe(true);
        finish(jsonResponse(ANSWER));
        await opening;
        expect(footerButton('apply').disabled).toBe(false);
    });

    it('keeps "Apply selected" disabled when the request fails', async () => {
        AjaxRequest.nextPost = () => Promise.reject(jsonResponse({ success: false, error: 'Nope.' }));

        await FieldOverview.attach(document).open();

        expect(footerButton('apply').disabled).toBe(true);
    });

    it('disables "Apply selected" also when the footer renders after the call', async () => {
        Modal.renderAsync = true;
        try {
            AjaxRequest.nextPost = () => new Promise(() => {});
            FieldOverview.attach(document).open();
            await modal().updateComplete;
            await Promise.resolve();

            expect(footerButton('apply').disabled).toBe(true);
        } finally {
            Modal.renderAsync = false;
        }
    });

    it('focuses the caption when every field is fine as it is', async () => {
        const answer = structuredClone(ANSWER);
        answer.fields[0] = { ...answer.fields[0], action: 'keep', value: '' };

        await openOverview(answer);

        expect(modal().querySelector('input[type="checkbox"]')).toBeNull();
        expect(document.activeElement).toBe(modal().querySelector('caption'));
        expect(footerButton('apply').disabled).toBe(true);
    });

    it('a slug suggestion gets no length limit in the input, since it is the full path', async () => {
        render(BUTTON + field('slug', '/old'));
        await openOverview({
            success: true,
            fields: [{ field: 'slug', label: 'URL Segment', kind: 'slug', maxLength: 60, current: '/old', action: 'change', value: '/products/airfryer-test-2026-with-a-long-path-segment', reason: 'Names the topic.' }],
        });

        const input = modal().querySelector('tbody .cowriter-field-overview-value');
        expect(input.value).toBe('/products/airfryer-test-2026-with-a-long-path-segment');
        expect(input.maxLength).toBe(-1);
    });
});
