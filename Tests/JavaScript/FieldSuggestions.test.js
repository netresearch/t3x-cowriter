/**
 * Tests for the FormEngine "Suggest" field control.
 *
 * The fixtures copy the markup TYPO3's InputTextElement and InputSlugElement
 * render around a field control (form-wizards-wrap, the visible input with
 * data-formengine-input-name, the hidden input that is submitted), plus the
 * anchor FieldSuggestionsControl renders into the btn-group.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import FormEngine from '@typo3/backend/form-engine.js';
import FormEngineValidation from '@typo3/backend/form-engine-validation.js';
import FieldSuggestions from '../../Resources/Public/JavaScript/FormEngine/FieldSuggestions.js';

const CONTROL_ID = 't3js-cowriter-suggestions-1';

function controlAnchor(itemName, field) {
    return `<a id="${CONTROL_ID}" role="button" aria-expanded="false" aria-controls="${CONTROL_ID}-panel"
        data-url="/typo3/ajax/cowriter/suggestions?token=dummy" data-item-name="${itemName}"
        data-table="pages" data-field="${field}" data-uid="12" data-pid="12" data-count="3"
        data-label-heading="AI suggestions" data-label-loading="Generating suggestions…"
        data-label-loaded="%d suggestions available." data-label-empty="No suggestions were returned."
        data-label-error="The suggestions could not be loaded." data-label-inserted="Suggestion inserted."
        data-label-close="Close suggestions" class="btn btn-default" href="#"><span class="icon"></span></a>`;
}

const INPUT_FIXTURE = `
<div class="formengine-field-item t3js-formengine-field-item">
  <div class="form-control-wrap" style="max-width: 400px">
    <div class="form-wizards-wrap">
      <div class="form-wizards-item-element">
        <input type="text" value="" id="formengine-input-1" data-formengine-validation-rules="[]"
          data-formengine-input-params='{"field":"data[pages][12][seo_title]","evalList":"trim"}'
          data-formengine-input-name="data[pages][12][seo_title]" maxlength="255"
          class="form-control form-control-clearable t3js-clearable" />
        <input type="hidden" name="data[pages][12][seo_title]" value="Old title" />
      </div>
      <div class="form-wizards-item-aside form-wizards-item-aside--field-control">
        <div class="btn-group">${controlAnchor('data[pages][12][seo_title]', 'seo_title')}</div>
      </div>
    </div>
  </div>
</div>`;

const SLUG_FIXTURE = `
<div class="formengine-field-item t3js-formengine-field-item">
  <div class="form-control-wrap" style="max-width: 400px" id="t3js-form-field-slug-id1">
    <div class="form-wizards-wrap">
      <div class="form-wizards-item-element">
        <div class="input-group">
          <input class="form-control t3js-form-field-slug-readonly" title="/company/old" value="/company/old" readonly />
          <input type="text" id="formengine-input-2" class="form-control t3js-form-field-slug-input hidden"
            placeholder="/company/old" data-formengine-input-name="data[pages][12][slug]" />
          <input type="hidden" class="t3js-form-field-slug-hidden" name="data[pages][12][slug]" value="/company/old" />
        </div>
      </div>
      <div class="form-wizards-item-aside form-wizards-item-aside--field-control">
        <div class="btn-group">${controlAnchor('data[pages][12][slug]', 'slug')}</div>
      </div>
    </div>
  </div>
</div>`;

/**
 * Replace the document body with a static test fixture.
 */
function render(html) {
    document.body.replaceChildren(document.createRange().createContextualFragment(html));
}

function jsonResponse(body) {
    return { resolve: async () => body };
}

async function setup(fixture = INPUT_FIXTURE) {
    render(fixture);
    const subject = new FieldSuggestions(CONTROL_ID);
    await subject.ready;
    return { subject, control: document.getElementById(CONTROL_ID) };
}

async function openWith(subject, body) {
    AjaxRequest.nextPost = () => Promise.resolve(jsonResponse(body));
    await subject.open();
}

function suggestionButtons() {
    return Array.from(document.querySelectorAll('.cowriter-suggestion'));
}

function status() {
    return document.getElementById(CONTROL_ID + '-status');
}

function panel() {
    return document.getElementById(CONTROL_ID + '-panel');
}

describe('FieldSuggestions', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        AjaxRequest.instances.length = 0;
    });

    it('does nothing when the control element is missing', async () => {
        render('');
        const subject = new FieldSuggestions('missing');
        await expect(subject.ready).resolves.toBeUndefined();
        expect(subject.control).toBeNull();
    });

    it('builds a hidden panel and a polite live region next to the field', async () => {
        const { control } = await setup();

        expect(panel()).not.toBeNull();
        expect(panel().hidden).toBe(true);
        expect(panel().getAttribute('aria-labelledby')).toBe(CONTROL_ID + '-heading');
        expect(status().getAttribute('role')).toBe('status');
        expect(status().getAttribute('aria-live')).toBe('polite');
        expect(control.closest('.form-wizards-wrap').nextElementSibling).toBe(panel());
    });

    it('posts the field identity and the live field value, then lists the suggestions', async () => {
        const { subject, control } = await setup();
        document.querySelector('[data-formengine-input-name]').value = 'Typed but not saved';

        await openWith(subject, { success: true, suggestions: ['First', 'Second', 'Third'] });

        const request = AjaxRequest.instances[0];
        expect(request.url).toBe('/typo3/ajax/cowriter/suggestions?token=dummy');
        expect(request.post).toHaveBeenCalledWith(
            { table: 'pages', field: 'seo_title', uid: '12', pid: '12', count: 3, currentValue: 'Typed but not saved' },
            { headers: { 'Content-Type': 'application/json' } },
        );
        expect(suggestionButtons().map((button) => button.textContent)).toEqual(['First', 'Second', 'Third']);
        expect(suggestionButtons().every((button) => button.type === 'button')).toBe(true);
        expect(panel().hidden).toBe(false);
        expect(control.getAttribute('aria-expanded')).toBe('true');
        expect(control.hasAttribute('aria-busy')).toBe(false);
        expect(status().textContent).toBe('3 suggestions available.');
        expect(document.activeElement).toBe(suggestionButtons()[0]);
    });

    it('announces loading while the request is pending', async () => {
        const { subject, control } = await setup();
        let finish;
        AjaxRequest.nextPost = () => new Promise((resolve) => { finish = resolve; });

        const pending = subject.open();
        expect(status().textContent).toBe('Generating suggestions…');
        expect(control.getAttribute('aria-busy')).toBe('true');

        finish(jsonResponse({ success: true, suggestions: ['One'] }));
        await pending;
        expect(control.hasAttribute('aria-busy')).toBe(false);
    });

    it('renders model output as text, never as markup', async () => {
        const { subject } = await setup();

        await openWith(subject, { success: true, suggestions: ['<img src=x onerror=alert(1)>'] });

        expect(suggestionButtons()[0].textContent).toBe('<img src=x onerror=alert(1)>');
        expect(document.querySelector('.cowriter-suggestions-list img')).toBeNull();
    });

    it('skips entries that are not non-empty strings', async () => {
        const { subject } = await setup();

        await openWith(subject, { success: true, suggestions: ['Kept', '', 42, null] });

        expect(suggestionButtons().map((button) => button.textContent)).toEqual(['Kept']);
    });

    it('fills the field and fires the events FormEngine needs when a suggestion is picked', async () => {
        const { subject, control } = await setup();
        const field = document.querySelector('[data-formengine-input-name]');
        const changeEvents = [];
        const listener = (event) => changeEvents.push(event);
        document.addEventListener('change', listener);
        await openWith(subject, { success: true, suggestions: ['Picked title', 'Other'] });

        suggestionButtons()[0].click();
        document.removeEventListener('change', listener);

        expect(field.value).toBe('Picked title');
        expect(changeEvents).toHaveLength(1);
        expect(changeEvents[0].target).toBe(field);
        expect(changeEvents[0].bubbles).toBe(true);
        expect(FormEngineValidation.validateField).toHaveBeenCalledWith(field);
        expect(FormEngine.markFieldAsChanged).toHaveBeenCalledWith(field);
        expect(panel().hidden).toBe(true);
        expect(control.getAttribute('aria-expanded')).toBe('false');
        // The editor continues in the filled field.
        expect(document.activeElement).toBe(field);
        expect(status().textContent).toBe('Suggestion inserted.');
    });

    it('hands a picked slug to the TYPO3 slug element through its input event', async () => {
        const { subject } = await setup(SLUG_FIXTURE);
        const slugInput = document.querySelector('.t3js-form-field-slug-input');
        const readonly = document.querySelector('.t3js-form-field-slug-readonly');
        const inputEvents = [];
        slugInput.addEventListener('input', (event) => inputEvents.push(event));
        await openWith(subject, { success: true, suggestions: ['/company/new-segment'] });

        suggestionButtons()[0].click();

        expect(slugInput.value).toBe('/company/new-segment');
        expect(slugInput.classList.contains('hidden')).toBe(false);
        expect(readonly.classList.contains('hidden')).toBe(true);
        expect(inputEvents).toHaveLength(1);
        expect(document.activeElement).toBe(slugInput);
        // The slug element validates and marks the field itself.
        expect(FormEngineValidation.validateField).not.toHaveBeenCalled();
        expect(FormEngine.markFieldAsChanged).not.toHaveBeenCalled();
    });

    it('closes on Escape inside the list and returns focus to the control', async () => {
        const { subject, control } = await setup();
        await openWith(subject, { success: true, suggestions: ['A', 'B'] });

        suggestionButtons()[1].dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(panel().hidden).toBe(true);
        expect(control.getAttribute('aria-expanded')).toBe('false');
        expect(document.activeElement).toBe(control);
    });

    it('closes on Escape on the control itself', async () => {
        const { subject, control } = await setup();
        await openWith(subject, { success: true, suggestions: ['A'] });
        control.focus();

        control.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(panel().hidden).toBe(true);
        expect(document.activeElement).toBe(control);
    });

    it('moves focus between suggestions with the arrow keys, Home and End', async () => {
        const { subject } = await setup();
        await openWith(subject, { success: true, suggestions: ['A', 'B', 'C'] });
        const buttons = suggestionButtons();
        const press = (key) => document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));

        press('ArrowDown');
        expect(document.activeElement).toBe(buttons[1]);
        press('End');
        expect(document.activeElement).toBe(buttons[2]);
        press('ArrowDown');
        expect(document.activeElement).toBe(buttons[0]);
        press('ArrowUp');
        expect(document.activeElement).toBe(buttons[2]);
        press('Home');
        expect(document.activeElement).toBe(buttons[0]);
    });

    it('opens with Space and with a click, and a second click closes', async () => {
        const { control } = await setup();
        AjaxRequest.nextPost = () => Promise.resolve(jsonResponse({ success: true, suggestions: ['A'] }));

        const space = new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true });
        control.dispatchEvent(space);
        expect(space.defaultPrevented).toBe(true);
        expect(panel().hidden).toBe(false);

        const click = new MouseEvent('click', { bubbles: true, cancelable: true });
        control.dispatchEvent(click);
        expect(click.defaultPrevented).toBe(true);
        expect(panel().hidden).toBe(true);

        control.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        expect(panel().hidden).toBe(false);
    });

    it('shows the server message of a refused request as an error', async () => {
        const { subject } = await setup();
        AjaxRequest.nextPost = () => Promise.reject(jsonResponse({ success: false, error: 'You are not allowed to edit this field.' }));

        await subject.open();

        expect(status().textContent).toBe('You are not allowed to edit this field.');
        expect(status().classList.contains('text-danger')).toBe(true);
        expect(suggestionButtons()).toHaveLength(0);
        expect(panel().hidden).toBe(false);
    });

    it('falls back to the generic error when the failure carries no message', async () => {
        const { subject } = await setup();
        AjaxRequest.nextPost = () => Promise.reject({ resolve: async () => { throw new SyntaxError('not JSON'); } });

        await subject.open();

        expect(status().textContent).toBe('The suggestions could not be loaded.');
        expect(status().classList.contains('text-danger')).toBe(true);
    });

    it('shows the error of an unsuccessful answer', async () => {
        const { subject } = await setup();

        await openWith(subject, { success: false, error: 'The AI returned no usable suggestions. Please try again.' });

        expect(status().textContent).toBe('The AI returned no usable suggestions. Please try again.');
        expect(status().classList.contains('text-danger')).toBe(true);
    });

    it('reports an empty result', async () => {
        const { subject } = await setup();

        await openWith(subject, { success: true, suggestions: [] });

        expect(status().textContent).toBe('No suggestions were returned.');
    });

    it('clears a previous error once suggestions arrive', async () => {
        const { subject } = await setup();
        AjaxRequest.nextPost = () => Promise.reject(jsonResponse({ error: 'Temporary failure.' }));
        await subject.open();
        subject.close();

        await openWith(subject, { success: true, suggestions: ['A'] });

        expect(status().classList.contains('text-danger')).toBe(false);
        expect(status().textContent).toBe('1 suggestions available.');
    });

    it('ignores the outcome of a request that a reopen superseded', async () => {
        const { subject, control } = await setup();
        const pending = [];
        AjaxRequest.nextPost = () => new Promise((resolve, reject) => pending.push({ resolve, reject }));

        const first = subject.open();
        subject.close();
        const second = subject.open();
        expect(pending).toHaveLength(2);

        // The aborted first request fails while the second one is loading.
        pending[0].reject(jsonResponse({ error: 'The operation was aborted.' }));
        await first;
        expect(status().textContent).toBe('Generating suggestions…');
        expect(status().classList.contains('text-danger')).toBe(false);
        expect(control.getAttribute('aria-busy')).toBe('true');

        pending[1].resolve(jsonResponse({ success: true, suggestions: ['Second'] }));
        await second;
        expect(suggestionButtons().map((button) => button.textContent)).toEqual(['Second']);
        expect(control.hasAttribute('aria-busy')).toBe(false);
    });

    it('ignores a late answer of a superseded request', async () => {
        const { subject } = await setup();
        const pending = [];
        AjaxRequest.nextPost = () => new Promise((resolve, reject) => pending.push({ resolve, reject }));

        const first = subject.open();
        subject.close();
        const second = subject.open();

        pending[0].resolve(jsonResponse({ success: true, suggestions: ['Stale'] }));
        await first;
        expect(suggestionButtons()).toHaveLength(0);

        pending[1].resolve(jsonResponse({ success: true, suggestions: ['Fresh'] }));
        await second;
        expect(suggestionButtons().map((button) => button.textContent)).toEqual(['Fresh']);
    });

    it('aborts a pending request when the list is closed', async () => {
        const { subject } = await setup();
        AjaxRequest.nextPost = () => new Promise(() => {});

        subject.open();
        subject.close();

        expect(AjaxRequest.instances[0].abort).toHaveBeenCalled();
        expect(panel().hidden).toBe(true);
    });

    it('uses the registered AJAX URL when the control carries none', async () => {
        const { subject, control } = await setup();
        delete control.dataset.url;
        globalThis.TYPO3 = { settings: { ajaxUrls: { tx_cowriter_suggestions: '/typo3/ajax/fallback' } } };

        await openWith(subject, { success: true, suggestions: ['A'] });

        expect(AjaxRequest.instances[0].url).toBe('/typo3/ajax/fallback');
        delete globalThis.TYPO3;
    });

    it('falls back to English labels when the control carries none', async () => {
        render(INPUT_FIXTURE);
        const control = document.getElementById(CONTROL_ID);
        Object.keys(control.dataset)
            .filter((key) => key.startsWith('label'))
            .forEach((key) => delete control.dataset[key]);
        const subject = new FieldSuggestions(CONTROL_ID);
        await subject.ready;

        expect(document.getElementById(CONTROL_ID + '-heading').textContent).toBe('AI suggestions');
        expect(document.querySelector('.cowriter-suggestions-close').getAttribute('aria-label')).toBe('Close suggestions');
    });
});
