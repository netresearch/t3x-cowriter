/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * Tests for the "Present as tabs or accordion" field control.
 *
 * The fixture copies the markup TYPO3's RichTextElement renders around a
 * field control (form-wizards-wrap with the aside holding the control), plus
 * the anchor PresentationControl renders.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Viewport from '@typo3/backend/viewport.js';
import AlternativePresentation from '../../Resources/Public/JavaScript/FormEngine/AlternativePresentation.js';

const CONTROL_ID = 't3js-cowriter-presentation-1';

const FIXTURE = `
<div class="formengine-field-item t3js-formengine-field-item">
  <div class="form-control-wrap">
    <div class="form-wizards-wrap">
      <div class="form-wizards-item-element"><textarea id="rte-1"></textarea></div>
      <div class="form-wizards-item-aside form-wizards-item-aside--field-control">
        <div class="btn-group">
          <a id="${CONTROL_ID}" role="button" aria-expanded="false" aria-controls="${CONTROL_ID}-panel"
            data-propose-url="/typo3/ajax/cowriter/presentation-propose?token=a"
            data-create-url="/typo3/ajax/cowriter/presentation-create?token=b"
            data-switch-url="/typo3/ajax/cowriter/presentation-switch?token=c"
            data-hidden-field="disabled"
            data-uid="10" data-layouts="tab,accordion"
            data-label-heading="Present as tabs or accordion" data-label-layout-tab="Tabs (label)"
            data-label-title-required="Every section needs a title."
            class="btn btn-default" href="#"><span class="icon"></span></a>
        </div>
      </div>
    </div>
  </div>
</div>
<div class="form-check form-switch">
  <input type="checkbox" class="form-check-input" checked
    data-formengine-input-name="data[tt_content][10][disabled]" id="hidden-toggle" />
  <input type="hidden" name="data[tt_content][10][disabled]" value="0" />
</div>`;

const CREATED = {
    success: true,
    uid: 17,
    originalUid: 10,
    layout: 'accordion',
    switchToken: 'a'.repeat(40),
    pageUrl: '/typo3/module/web/layout?id=2#element-tt_content-17',
    editUrl: '/typo3/record/edit?x=17',
};

const PROPOSAL = {
    success: true,
    source: 'headings',
    fingerprint: 'f'.repeat(64),
    layouts: ['tab', 'accordion'],
    sections: [
        { title: 'Introduction', start: 0, consumesHeading: false, preview: 'Our chairs fit every office.' },
        { title: 'Seat', start: 1, consumesHeading: true, preview: '' },
        { title: '<img src=x onerror=alert(1)>', start: 3, consumesHeading: true, preview: '<b>Five</b> years' },
    ],
};

function render(html) {
    document.body.replaceChildren(document.createRange().createContextualFragment(html));
}

function jsonResponse(body) {
    return { resolve: async () => body };
}

async function flush() {
    for (let i = 0; i < 5; i++) {
        await Promise.resolve();
    }
}

async function setup() {
    render(FIXTURE);
    const subject = new AlternativePresentation(CONTROL_ID);
    await subject.ready;
    return { subject, control: document.getElementById(CONTROL_ID) };
}

/**
 * Answers the propose request with $proposal and the create request with
 * $created; records the bodies.
 */
function answer(proposal, created, switched = { success: true }) {
    const posts = [];
    AjaxRequest.nextPost = (url, data) => {
        posts.push({ url, data });
        const result = url.includes('propose') ? proposal : (url.includes('switch') ? switched : created);
        return result instanceof Error ? Promise.reject(result) : Promise.resolve(jsonResponse(result));
    };
    return posts;
}

async function openPanel(control) {
    control.click();
    await flush();
}

describe('AlternativePresentation', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        AjaxRequest.instances.length = 0;
    });

    it('does nothing when the control element is missing', async () => {
        render('<div></div>');
        const subject = new AlternativePresentation('missing');
        await subject.ready;
        expect(subject.control).toBeNull();
    });

    it('builds a hidden panel and a live region after the field', async () => {
        const { control } = await setup();
        const panel = document.getElementById(CONTROL_ID + '-panel');
        expect(panel.hidden).toBe(true);
        expect(panel.getAttribute('aria-labelledby')).toBe(CONTROL_ID + '-heading');
        const status = document.getElementById(CONTROL_ID + '-status');
        expect(status.getAttribute('role')).toBe('status');
        expect(status.getAttribute('aria-live')).toBe('polite');
        expect(control.closest('.form-wizards-wrap').nextElementSibling).toBe(panel);
    });

    it('asks for a proposal with the uid only and shows it', async () => {
        const posts = answer(PROPOSAL, null);
        const { control } = await setup();

        await openPanel(control);

        expect(posts).toEqual([{ url: '/typo3/ajax/cowriter/presentation-propose?token=a', data: { uid: 10 } }]);
        expect(control.getAttribute('aria-expanded')).toBe('true');
        expect(control.hasAttribute('aria-busy')).toBe(false);

        const inputs = [...document.querySelectorAll('.cowriter-presentation-sections input')];
        expect(inputs.map((input) => input.value)).toEqual(['Introduction', 'Seat', '<img src=x onerror=alert(1)>']);
        expect(inputs[0].labels[0].textContent).toBe('Title of section 1');
        // Focus moves into the panel, onto its first control: the chosen layout.
        expect(document.activeElement).toBe(document.querySelector('.cowriter-presentation input[type="radio"]:checked'));

        const previews = inputs.map((input) => document.getElementById(input.getAttribute('aria-describedby')).textContent);
        expect(previews).toEqual(['Our chairs fit every office.', '(no text, only the heading)', '<b>Five</b> years']);
        // Model and section text are never parsed as markup.
        expect(document.querySelector('.cowriter-presentation img, .cowriter-presentation b')).toBeNull();

        expect(document.getElementById(CONTROL_ID + '-status').textContent).toContain('divided at its headings');
        expect(document.querySelector('.cowriter-presentation .alert').textContent).toContain('stays unchanged');
    });

    it('says when the language model made the proposal', async () => {
        answer({ ...PROPOSAL, source: 'model' }, null);
        const { control } = await setup();

        await openPanel(control);

        expect(document.getElementById(CONTROL_ID + '-status').textContent).toContain('language model proposed');
    });

    it('offers only the layouts both the control and the answer allow, the first chosen', async () => {
        answer({ ...PROPOSAL, layouts: ['accordion', 'tab', 'carousel'] }, null);
        const { control } = await setup();
        control.dataset.layouts = 'accordion,carousel';

        await openPanel(control);

        const radios = [...document.querySelectorAll('.cowriter-presentation input[type="radio"]')];
        expect(radios.map((radio) => radio.value)).toEqual(['accordion']);
        expect(radios[0].checked).toBe(true);
        expect(radios[0].labels[0].textContent).toBe('Accordion');
    });

    it('uses the labels the server passed', async () => {
        answer(PROPOSAL, null);
        const { control } = await setup();

        await openPanel(control);

        const tab = document.querySelector('.cowriter-presentation input[value="tab"]');
        expect(tab.labels[0].textContent).toBe('Tabs (label)');
    });

    it('creates the element with the edited titles, the start blocks and no markup', async () => {
        const posts = answer(PROPOSAL, CREATED);
        const { control } = await setup();
        await openPanel(control);

        document.querySelector('.cowriter-presentation input[value="accordion"]').checked = true;
        const inputs = document.querySelectorAll('.cowriter-presentation-sections input');
        inputs[0].value = '  Overview  ';
        document.querySelector('.cowriter-presentation-create').click();
        await flush();

        expect(posts[1]).toEqual({
            url: '/typo3/ajax/cowriter/presentation-create?token=b',
            data: {
                uid: 10,
                layout: 'accordion',
                fingerprint: 'f'.repeat(64),
                sections: [
                    { title: 'Overview', start: 0, consumesHeading: false },
                    { title: 'Seat', start: 1, consumesHeading: true },
                    { title: '<img src=x onerror=alert(1)>', start: 3, consumesHeading: true },
                ],
            },
        });
        expect(document.querySelector('.cowriter-presentation form')).toBeNull();
    });

    it('says the new element is hidden and offers the switch, the page and the element', async () => {
        answer(PROPOSAL, CREATED);
        const { control } = await setup();
        await openPanel(control);
        document.querySelector('.cowriter-presentation-create').click();
        await flush();

        const buttons = [...document.querySelectorAll('.cowriter-presentation button')];
        expect(buttons.map((button) => button.textContent)).toEqual([
            'Show the new element and hide the original',
            'Open the page in the Layout module',
            'Open the new element',
        ]);
        expect(buttons[0].classList.contains('btn-primary')).toBe(true);
        expect(document.activeElement).toBe(buttons[0]);
        expect(document.querySelector('.cowriter-presentation-result').textContent).toContain('is hidden');
        expect(document.getElementById(CONTROL_ID + '-status').textContent).toContain('until you switch');
        expect(document.querySelector('.cowriter-presentation a')).toBeNull();
    });

    it('opens the page and the new element through the content frame', async () => {
        answer(PROPOSAL, CREATED);
        const { control } = await setup();
        await openPanel(control);
        document.querySelector('.cowriter-presentation-create').click();
        await flush();

        document.querySelector('.cowriter-presentation-page').click();
        document.querySelector('.cowriter-presentation-edit').click();

        expect(Viewport.ContentContainer.setUrl.mock.calls).toEqual([
            ['/typo3/module/web/layout?id=2#element-tt_content-17'],
            ['/typo3/record/edit?x=17'],
        ]);
    });

    it('stays put when the editor cancels the unsaved changes dialog', async () => {
        answer(PROPOSAL, CREATED);
        const { control } = await setup();
        await openPanel(control);
        document.querySelector('.cowriter-presentation-create').click();
        await flush();
        Viewport.ContentContainer.setUrl.mockReturnValueOnce(Promise.reject(new Error('cancelled')));

        document.querySelector('.cowriter-presentation-edit').click();
        await flush();

        expect(document.getElementById(CONTROL_ID + '-status').classList.contains('text-danger')).toBe(false);
        expect(document.querySelector('.cowriter-presentation-edit')).not.toBeNull();
    });

    it('offers no switch without a token or a switch URL, and no links without URLs', async () => {
        answer(PROPOSAL, { success: true, uid: 17, originalUid: 10, switchToken: '', pageUrl: '', editUrl: '' });
        const { control } = await setup();
        await openPanel(control);
        document.querySelector('.cowriter-presentation-create').click();
        await flush();
        expect(document.querySelectorAll('.cowriter-presentation button')).toHaveLength(0);

        answer(PROPOSAL, CREATED);
        const second = await setup();
        second.control.dataset.switchUrl = '';
        await openPanel(second.control);
        document.querySelector('.cowriter-presentation-create').click();
        await flush();
        expect(document.querySelector('.cowriter-presentation-switch')).toBeNull();
        expect(document.querySelector('.cowriter-presentation-page')).not.toBeNull();
    });

    it('switches the pair the creation made and keeps this form from showing the original again', async () => {
        const posts = answer(PROPOSAL, CREATED);
        const { control } = await setup();
        await openPanel(control);
        document.querySelector('.cowriter-presentation-create').click();
        await flush();

        document.querySelector('.cowriter-presentation-switch').click();
        await flush();

        expect(posts[2]).toEqual({
            url: '/typo3/ajax/cowriter/presentation-switch?token=c',
            data: { newUid: 17, originalUid: 10, token: 'a'.repeat(40) },
        });
        expect(document.querySelector('.cowriter-presentation-switch')).toBeNull();
        expect(document.querySelector('.cowriter-presentation-result').textContent).toBe('The new element is visible now and this element is hidden.');
        expect(document.getElementById(CONTROL_ID + '-status').textContent).toBe('The new element is visible now and this element is hidden.');
        expect(document.activeElement).toBe(document.querySelector('.cowriter-presentation-page'));
        // The form now submits the stored state of the original.
        expect(document.querySelector('input[name="data[tt_content][10][disabled]"]').value).toBe('1');
        expect(document.getElementById('hidden-toggle').checked).toBe(false);
    });

    it('uses the hidden field the server names, and none without it', async () => {
        answer(PROPOSAL, CREATED);
        const { control } = await setup();
        delete control.dataset.hiddenField;
        await openPanel(control);
        document.querySelector('.cowriter-presentation-create').click();
        await flush();

        document.querySelector('.cowriter-presentation-switch').click();
        await flush();

        expect(document.querySelector('.cowriter-presentation-result').textContent).toBe('The new element is visible now and this element is hidden.');
        expect(document.querySelector('input[name="data[tt_content][10][disabled]"]').value).toBe('0');
        expect(document.getElementById('hidden-toggle').checked).toBe(true);
    });

    it('says why there is no switch for an original with translations', async () => {
        answer(PROPOSAL, { ...CREATED, switchToken: '', switchNote: 'Not switched: this element has translations.' });
        const { control } = await setup();
        await openPanel(control);
        document.querySelector('.cowriter-presentation-create').click();
        await flush();

        expect(document.querySelector('.cowriter-presentation-switch')).toBeNull();
        const note = document.querySelector('.cowriter-presentation-switch-note');
        expect(note.textContent).toBe('Not switched: this element has translations.');
        expect(note.classList.contains('alert-warning')).toBe(true);
        expect(document.querySelector('.cowriter-presentation-page')).not.toBeNull();
        // The result does not promise a switch, and the live region carries the reason.
        const created = 'The new element was created directly after this one and is hidden. This element stays visible.';
        expect(document.querySelector('.cowriter-presentation-result').textContent).toBe(created);
        expect(document.getElementById(CONTROL_ID + '-status').textContent).toBe(created + ' Not switched: this element has translations.');
    });

    /**
     * Answers the proposal at once and holds the creation until the test
     * settles it.
     */
    function holdCreation() {
        const held = {};
        AjaxRequest.nextPost = (url) => {
            if (url.includes('propose')) {
                return Promise.resolve(jsonResponse(PROPOSAL));
            }
            return new Promise((resolve, reject) => {
                held.resolve = () => resolve(jsonResponse(CREATED));
                held.reject = () => reject(Object.assign(new Error('500'), { resolve: async () => ({ success: false, error: 'Failed.' }) }));
            });
        };
        return held;
    }

    it('stays open while the element is being created, so its result is not lost', async () => {
        const held = holdCreation();
        const { control } = await setup();
        await openPanel(control);
        const panel = document.getElementById(CONTROL_ID + '-panel');
        document.querySelector('.cowriter-presentation-create').click();
        await flush();

        expect(document.querySelector('.cowriter-presentation-cancel').disabled).toBe(true);
        panel.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        control.click();
        await flush();
        expect(panel.hidden).toBe(false);
        expect(control.getAttribute('aria-expanded')).toBe('true');

        held.resolve();
        await flush();
        expect(document.querySelector('.cowriter-presentation-result')).not.toBeNull();

        // Once answered, the panel closes again.
        panel.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(panel.hidden).toBe(true);
    });

    it('can be closed again after a failed creation', async () => {
        const held = holdCreation();
        const { control } = await setup();
        await openPanel(control);
        const panel = document.getElementById(CONTROL_ID + '-panel');
        document.querySelector('.cowriter-presentation-create').click();
        await flush();

        held.reject();
        await flush();
        const cancel = document.querySelector('.cowriter-presentation-cancel');
        expect(cancel.disabled).toBe(false);
        cancel.click();
        expect(panel.hidden).toBe(true);
    });

    it('announces the switch promise only where there is a switch', async () => {
        answer(PROPOSAL, CREATED);
        const { control } = await setup();
        await openPanel(control);
        document.querySelector('.cowriter-presentation-create').click();
        await flush();

        const created = 'The new element was created directly after this one and is hidden. Visitors keep seeing this element until you switch the two.';
        expect(document.querySelector('.cowriter-presentation-result').textContent).toBe(created);
        expect(document.getElementById(CONTROL_ID + '-status').textContent).toBe(created);
        expect(document.querySelector('.cowriter-presentation-switch-note')).toBeNull();
    });

    it('drops the switch and shows the reason when the server refuses it for good', async () => {
        const refusal = Object.assign(new Error('409'), {
            response: { status: 409 },
            resolve: async () => ({ success: false, error: 'Not switched: a translation was added.' }),
        });
        answer(PROPOSAL, CREATED, refusal);
        const { control } = await setup();
        await openPanel(control);
        document.querySelector('.cowriter-presentation-create').click();
        await flush();

        document.querySelector('.cowriter-presentation-switch').click();
        await flush();

        expect(document.querySelector('.cowriter-presentation-switch')).toBeNull();
        expect(document.querySelector('.cowriter-presentation-switch-note').textContent).toBe('Not switched: a translation was added.');
        const status = document.getElementById(CONTROL_ID + '-status');
        expect(status.textContent).toBe('The new element was created directly after this one and is hidden. This element stays visible. Not switched: a translation was added.');
        expect(status.classList.contains('text-danger')).toBe(false);
        expect(document.querySelector('input[name="data[tt_content][10][disabled]"]').value).toBe('0');
        expect(document.activeElement).toBe(document.querySelector('.cowriter-presentation-page'));
    });

    it('leaves a form field that already says hidden alone', async () => {
        answer(PROPOSAL, CREATED);
        const { control } = await setup();
        document.querySelector('input[name="data[tt_content][10][disabled]"]').value = '1';
        await openPanel(control);
        document.querySelector('.cowriter-presentation-create').click();
        await flush();

        document.querySelector('.cowriter-presentation-switch').click();
        await flush();

        expect(document.getElementById('hidden-toggle').checked).toBe(true);
    });

    it('shows a refused switch and lets the editor try again', async () => {
        const failure = Object.assign(new Error('500'), {
            resolve: async () => ({ success: false, error: 'The elements could not be switched.' }),
        });
        answer(PROPOSAL, CREATED, failure);
        const { control } = await setup();
        await openPanel(control);
        document.querySelector('.cowriter-presentation-create').click();
        await flush();

        document.querySelector('.cowriter-presentation-switch').click();
        await flush();

        const status = document.getElementById(CONTROL_ID + '-status');
        expect(status.textContent).toBe('The elements could not be switched.');
        expect(status.classList.contains('text-danger')).toBe(true);
        expect(document.querySelector('.cowriter-presentation-switch').disabled).toBe(false);
        expect(document.querySelector('input[name="data[tt_content][10][disabled]"]').value).toBe('0');
    });

    it('shows the result again instead of a second proposal after creating', async () => {
        const posts = answer(PROPOSAL, CREATED);
        const { control } = await setup();
        await openPanel(control);
        document.querySelector('.cowriter-presentation-create').click();
        await flush();
        document.querySelector('.cowriter-presentation-switch').click();
        await flush();

        control.click();
        await openPanel(control);

        expect(posts).toHaveLength(3);
        expect(document.querySelector('.cowriter-presentation form')).toBeNull();
        expect(document.querySelector('.cowriter-presentation-switch')).toBeNull();
        expect(document.querySelector('.cowriter-presentation-result').textContent).toContain('visible now');
    });

    it('does not create when Enter is pressed in a title', async () => {
        const posts = answer(PROPOSAL, CREATED);
        const { control } = await setup();
        await openPanel(control);

        const event = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
        document.querySelector('.cowriter-presentation-sections input').dispatchEvent(event);
        await flush();

        expect(event.defaultPrevented).toBe(true);
        expect(posts).toHaveLength(1);
        const other = new KeyboardEvent('keydown', { key: 'a', bubbles: true, cancelable: true });
        document.querySelector('.cowriter-presentation-sections input').dispatchEvent(other);
        expect(other.defaultPrevented).toBe(false);
    });

    it('notes the images and warns about translations', async () => {
        answer({ ...PROPOSAL, images: 2, warnings: ['translations', 'connectedTranslation', 'unknown'] }, CREATED);
        const { control } = await setup();
        await openPanel(control);

        const notices = [...document.querySelectorAll('.cowriter-presentation-notice')];
        expect(notices.map((notice) => notice.className)).toEqual([
            'alert alert-info cowriter-presentation-notice',
            'alert alert-warning cowriter-presentation-notice',
            'alert alert-warning cowriter-presentation-notice',
        ]);
        expect(notices[0].textContent).toBe('Images of this element: 2. They go into the first section.');
        expect(notices[1].textContent).toContain('Do not delete it');
        expect(notices[2].textContent).toContain('connected to the default language');
    });

    it('says when the images are not taken over', async () => {
        answer({ ...PROPOSAL, images: 2, warnings: ['imagesNotTaken'] }, CREATED);
        const { control } = await setup();
        await openPanel(control);

        const notices = [...document.querySelectorAll('.cowriter-presentation-notice')];
        expect(notices.map((notice) => notice.textContent)).toEqual(['The images of this element are not taken over. Add them yourself.']);
    });

    it('reports an unforeseen failure of an action in the status line', async () => {
        answer(PROPOSAL, CREATED);
        const { subject } = await setup();

        subject.report(Promise.reject(new Error('Unexpected')));
        await flush();

        expect(document.getElementById(CONTROL_ID + '-status').textContent).toBe('Unexpected');
    });

    it('refuses to send an empty title and marks the field', async () => {
        const posts = answer(PROPOSAL, { success: true, uid: 17 });
        const { control } = await setup();
        await openPanel(control);

        const inputs = document.querySelectorAll('.cowriter-presentation-sections input');
        inputs[1].value = '   ';
        document.querySelector('.cowriter-presentation-create').click();
        await flush();

        expect(posts).toHaveLength(1);
        expect(inputs[1].getAttribute('aria-invalid')).toBe('true');
        expect(inputs[0].hasAttribute('aria-invalid')).toBe(false);
        expect(document.activeElement).toBe(inputs[1]);
        const status = document.getElementById(CONTROL_ID + '-status');
        expect(status.textContent).toBe('Every section needs a title.');
        expect(status.classList.contains('text-danger')).toBe(true);
        // Filled in, the mark goes away.
        inputs[1].value = 'Seat';
        inputs[0].setAttribute('aria-invalid', 'true');
        document.querySelector('.cowriter-presentation-create').click();
        await flush();
        expect(inputs[1].hasAttribute('aria-invalid')).toBe(false);
        expect(inputs[0].hasAttribute('aria-invalid')).toBe(false);
        expect(posts).toHaveLength(2);
    });

    it("shows the server's message when the creation is refused and allows a retry", async () => {
        const failure = Object.assign(new Error('409'), {
            resolve: async () => ({ success: false, error: 'The saved text changed after the sections were proposed.' }),
        });
        answer(PROPOSAL, failure);
        const { control } = await setup();
        await openPanel(control);

        document.querySelector('.cowriter-presentation-create').click();
        await flush();

        const status = document.getElementById(CONTROL_ID + '-status');
        expect(status.textContent).toBe('The saved text changed after the sections were proposed.');
        expect(status.classList.contains('text-danger')).toBe(true);
        expect(document.querySelector('.cowriter-presentation-create').disabled).toBe(false);
    });

    it("shows the server's message when the proposal is refused", async () => {
        const failure = Object.assign(new Error('403'), {
            resolve: async () => ({ success: false, error: 'This element cannot be presented as tabs or accordion.' }),
        });
        answer(failure, null);
        const { control } = await setup();

        await openPanel(control);

        expect(document.getElementById(CONTROL_ID + '-status').textContent).toBe('This element cannot be presented as tabs or accordion.');
        expect(document.querySelector('.cowriter-presentation form')).toBeNull();
    });

    it('falls back to the generic message for an answer without sections or a non-JSON failure', async () => {
        answer({ success: true, sections: [] }, null);
        const { control } = await setup();
        await openPanel(control);
        expect(document.getElementById(CONTROL_ID + '-status').textContent).toBe('The sections could not be proposed.');

        control.click();
        answer(Object.assign(new Error('500'), { resolve: async () => { throw new Error('not json'); } }), null);
        await openPanel(control);
        expect(document.getElementById(CONTROL_ID + '-status').textContent).toBe('The sections could not be proposed.');

        control.click();
        answer({ success: false }, null);
        await openPanel(control);
        expect(document.getElementById(CONTROL_ID + '-status').textContent).toBe('The sections could not be proposed.');
    });

    it('fails without a URL instead of posting somewhere', async () => {
        const posts = answer(PROPOSAL, null);
        const { control } = await setup();
        control.dataset.proposeUrl = '';

        await openPanel(control);

        expect(posts).toHaveLength(0);
        expect(document.getElementById(CONTROL_ID + '-status').textContent).toBe('The sections could not be proposed.');
    });

    it('ignores an answer that arrives after the panel was closed', async () => {
        let resolvePost;
        AjaxRequest.nextPost = () => new Promise((resolve) => { resolvePost = resolve; });
        const { control } = await setup();

        control.click();
        await flush();
        control.click();
        resolvePost(jsonResponse(PROPOSAL));
        await flush();

        expect(document.querySelector('.cowriter-presentation form')).toBeNull();
        expect(AjaxRequest.instances[0].abort).toHaveBeenCalled();
        expect(document.getElementById(CONTROL_ID + '-panel').hidden).toBe(true);
    });

    it('closes with Escape and Cancel and returns focus to the control', async () => {
        answer(PROPOSAL, null);
        const { control } = await setup();
        await openPanel(control);

        document.querySelector('.cowriter-presentation input').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(document.getElementById(CONTROL_ID + '-panel').hidden).toBe(true);
        expect(control.getAttribute('aria-expanded')).toBe('false');
        expect(document.activeElement).toBe(control);

        await openPanel(control);
        [...document.querySelectorAll('.cowriter-presentation button')].find((b) => b.textContent === 'Cancel').click();
        expect(document.getElementById(CONTROL_ID + '-panel').hidden).toBe(true);
    });

    it('opens with Space like a button', async () => {
        const posts = answer(PROPOSAL, null);
        const { control } = await setup();

        control.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));
        await flush();

        expect(posts).toHaveLength(1);
        control.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        expect(posts).toHaveLength(1);
    });
});
