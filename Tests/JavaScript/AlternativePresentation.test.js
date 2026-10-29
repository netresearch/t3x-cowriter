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
            data-uid="10" data-layouts="tab,accordion"
            data-label-heading="Present as tabs or accordion" data-label-layout-tab="Tabs (label)"
            data-label-title-required="Every section needs a title."
            class="btn btn-default" href="#"><span class="icon"></span></a>
        </div>
      </div>
    </div>
  </div>
</div>`;

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
function answer(proposal, created) {
    const posts = [];
    AjaxRequest.nextPost = (url, data) => {
        posts.push({ url, data });
        const result = url.includes('propose') ? proposal : created;
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
        const posts = answer(PROPOSAL, { success: true, uid: 17, layout: 'accordion', editUrl: '/typo3/record/edit?x=17' });
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

        const link = document.querySelector('.cowriter-presentation a');
        expect(link.getAttribute('href')).toBe('/typo3/record/edit?x=17');
        expect(link.textContent).toBe('Open the new element');
        expect(document.activeElement).toBe(link);
        expect(document.getElementById(CONTROL_ID + '-status').textContent).toContain('This element is unchanged');
        expect(document.querySelector('.cowriter-presentation form')).toBeNull();
    });

    it('shows no link when the server sent no edit URL', async () => {
        answer(PROPOSAL, { success: true, uid: 17, editUrl: '' });
        const { control } = await setup();
        await openPanel(control);

        document.querySelector('.cowriter-presentation-create').click();
        await flush();

        expect(document.querySelector('.cowriter-presentation a')).toBeNull();
        expect(document.querySelector('.cowriter-presentation p').textContent).toContain('created directly after');
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
