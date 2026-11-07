/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * Tests for the "Create an audience variant" field control.
 *
 * The fixture copies the markup TYPO3's RichTextElement renders around a
 * field control, the anchor VariantControl renders, and the select the
 * "Contexts" tab of EXT:contexts renders for the original element.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Viewport from '@typo3/backend/viewport.js';
import AudienceVariant from '../../Resources/Public/JavaScript/FormEngine/AudienceVariant.js';

const CONTROL_ID = 't3js-cowriter-variant-1';

const OPTIONS = JSON.stringify({
    audiences: [{ uid: 31, name: 'Students' }, { uid: 32, name: 'Buyers' }, { uid: 0, name: 'broken' }],
    tones: [{ uid: 34, name: 'Friendly' }],
});

const FIXTURE = `
<div class="formengine-field-item t3js-formengine-field-item">
  <div class="form-control-wrap">
    <div class="form-wizards-wrap">
      <div class="form-wizards-item-element"><textarea id="rte-1"></textarea></div>
      <div class="form-wizards-item-aside form-wizards-item-aside--field-control">
        <div class="btn-group">
          <a id="${CONTROL_ID}" role="button" aria-expanded="false" aria-controls="${CONTROL_ID}-panel"
            data-propose-url="/typo3/ajax/cowriter/variant-propose?token=a"
            data-create-url="/typo3/ajax/cowriter/variant-create?token=b"
            data-show-url="/typo3/ajax/cowriter/variant-show?token=c"
            data-uid="10"
            data-options='${OPTIONS}'
            data-label-heading="Create an audience variant (label)"
            class="btn btn-default" href="#"><span class="icon"></span></a>
        </div>
      </div>
    </div>
  </div>
</div>
<select name="data[tt_content][10][tx_contexts_settings][5][tx_contexts]">
  <option value="">n/a</option><option value="1">Yes</option><option value="0">No</option>
</select>`;

const PROPOSAL = {
    success: true,
    fingerprint: 'f'.repeat(64),
    header: 'Chairs for students',
    bodytext: '<p onclick="steal()">Cheap <b>and</b> sturdy.</p><script>alert(1)</script>',
    contexts: [{ uid: 5, title: 'Mobile <channel>' }, { uid: 'x', title: 'broken' }],
    contextNote: '',
};

const CREATED = {
    success: true,
    uid: 20,
    originalUid: 10,
    context: 5,
    contextTitle: 'Mobile <channel>',
    showToken: 'a'.repeat(64),
    note: '',
    pageUrl: '/typo3/module/web/layout?id=2#element-tt_content-20',
    editUrl: '/typo3/record/edit?x=20',
};

function render(html) {
    document.body.replaceChildren(document.createRange().createContextualFragment(html));
}

function jsonResponse(body) {
    return { resolve: async () => body };
}

async function flush() {
    for (let i = 0; i < 6; i++) {
        await Promise.resolve();
    }
}

async function setup() {
    render(FIXTURE);
    const subject = new AudienceVariant(CONTROL_ID);
    await subject.ready;
    return { subject, control: document.getElementById(CONTROL_ID), panel: document.getElementById(CONTROL_ID + '-panel') };
}

function answer(proposal, created = CREATED, shown = { success: true }) {
    const posts = [];
    AjaxRequest.nextPost = (url, data) => {
        posts.push({ url, data });
        let result = created;
        if (url.includes('propose')) {
            result = proposal;
        } else if (url.includes('show')) {
            result = shown;
        }
        return result instanceof Error ? Promise.reject(result) : Promise.resolve(jsonResponse(result));
    };
    return posts;
}

async function propose(panel, audience = '31', tone = '0') {
    panel.querySelector('select[data-role="audience"]').value = audience;
    const toneSelect = panel.querySelector('select[data-role="tone"]');
    if (toneSelect) {
        toneSelect.value = tone;
    }
    panel.querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }));
    await flush();
}

async function create(panel, context = '5') {
    const select = panel.querySelector('select[data-role="context"]');
    if (select) {
        select.value = context;
    }
    panel.querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }));
    await flush();
}

describe('AudienceVariant', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        AjaxRequest.instances.length = 0;
    });

    it('does nothing when the control element is missing', async () => {
        render('<div></div>');
        const subject = new AudienceVariant('missing');
        await subject.ready;
        expect(subject.control).toBeNull();
    });

    it('offers the valid audiences and the tones, the labels from the control', async () => {
        const { control, panel } = await setup();
        control.click();

        expect(panel.hidden).toBe(false);
        expect(control.getAttribute('aria-expanded')).toBe('true');
        expect(panel.querySelector('strong').textContent).toBe('Create an audience variant (label)');
        const audiences = Array.from(panel.querySelectorAll('select[data-role="audience"] option')).map((option) => option.textContent);
        expect(audiences).toEqual(['Students', 'Buyers']);
        const tones = Array.from(panel.querySelectorAll('select[data-role="tone"] option')).map((option) => option.value);
        expect(tones).toEqual(['0', '34']);
        expect(document.activeElement).toBe(panel.querySelector('select[data-role="audience"]'));
    });

    it('shows no tone select without tones and survives broken options', async () => {
        render(FIXTURE.replace(`data-options='${OPTIONS}'`, 'data-options="{nope"'));
        const subject = new AudienceVariant(CONTROL_ID);
        await subject.ready;
        expect(subject.options).toEqual({ audiences: [], tones: [] });
        document.getElementById(CONTROL_ID).click();
        expect(document.querySelector('select[data-role="tone"]')).toBeNull();
    });

    it('sends the choice and shows the proposal sanitised, nothing else written', async () => {
        const posts = answer(PROPOSAL);
        const { control, panel } = await setup();
        control.click();

        await propose(panel, '32', '34');

        expect(posts).toHaveLength(1);
        expect(posts[0].data).toEqual({ uid: 10, audience: 32, tone: 34 });
        expect(panel.querySelector('input[type="text"]').value).toBe('Chairs for students');
        const preview = panel.querySelector('.cowriter-variant-preview');
        expect(preview.innerHTML).toContain('<b>and</b>');
        expect(preview.innerHTML).not.toContain('onclick');
        expect(preview.querySelector('script')).toBeNull();
        const contexts = Array.from(panel.querySelectorAll('select[data-role="context"] option'));
        expect(contexts.map((option) => option.value)).toEqual(['0', '5']);
        expect(contexts[1].textContent).toBe('Mobile <channel>');
        expect(document.getElementById(CONTROL_ID + '-status').textContent).toContain('proposed this variant');
    });

    it('shows the server note when no context can be chosen', async () => {
        answer({ ...PROPOSAL, contexts: [], contextNote: 'EXT:contexts is not installed.' });
        const { control, panel } = await setup();
        control.click();
        await propose(panel);

        expect(panel.querySelector('select[data-role="context"]')).toBeNull();
        expect(panel.querySelector('.cowriter-variant-notice').textContent).toBe('EXT:contexts is not installed.');
    });

    it('reports a refused proposal with the server message', async () => {
        const failure = { resolve: async () => ({ success: false, error: 'Choose an audience.' }) };
        AjaxRequest.nextPost = () => Promise.reject(failure);
        const { control, panel } = await setup();
        control.click();
        await propose(panel);

        const status = document.getElementById(CONTROL_ID + '-status');
        expect(status.textContent).toBe('Choose an audience.');
        expect(status.classList.contains('text-danger')).toBe(true);
        expect(panel.querySelector('button[type="submit"]').disabled).toBe(false);
    });

    it('treats an answer without text as a failure', async () => {
        answer({ ...PROPOSAL, bodytext: '' });
        const { control, panel } = await setup();
        control.click();
        await propose(panel);

        expect(panel.querySelector('.cowriter-variant-preview')).toBeNull();
        expect(document.getElementById(CONTROL_ID + '-status').classList.contains('text-danger')).toBe(true);
    });

    it('creates the variant with the reviewed header and only the sanitised text', async () => {
        const posts = answer(PROPOSAL);
        const { control, panel } = await setup();
        control.click();
        await propose(panel);
        panel.querySelector('input[type="text"]').value = '  Chairs, reviewed ';

        await create(panel, '5');

        expect(posts[1].url).toContain('variant-create');
        expect(posts[1].data).toEqual({
            uid: 10,
            fingerprint: 'f'.repeat(64),
            header: 'Chairs, reviewed',
            // The sanitiser unwraps the script: its text stays, as plain text.
            bodytext: '<p>Cheap <b>and</b> sturdy.</p>alert(1)',
            context: 5,
        });
        expect(panel.querySelector('.cowriter-variant-result').textContent).toContain('"Mobile <channel>"');
        expect(panel.querySelector('#' + CONTROL_ID + '-hide-original').checked).toBe(true);
    });

    it('keeps an image of another origin only when the saved text already held it', async () => {
        const known = 'https://cdn.example.org/chair.png';
        const posts = answer({
            ...PROPOSAL,
            bodytext: `<p>Cheap.</p><img src="${known}" alt="Chair"><img src="https://tracker.example.net/x.png" alt="">`,
            originalBodytext: `<p>Our chairs.</p><img src="${known}" alt="Chair">`,
        });
        const { control, panel } = await setup();
        control.click();
        await propose(panel);

        const sources = Array.from(panel.querySelectorAll('.cowriter-variant-preview img')).map((img) => img.getAttribute('src'));
        expect(sources).toEqual([known]);

        await create(panel, '5');
        expect(posts[1].data.bodytext).toContain(known);
        expect(posts[1].data.bodytext).not.toContain('tracker.example.net');
    });

    it('without a context the result is the server note and there is no show step', async () => {
        answer(PROPOSAL, { ...CREATED, context: 0, contextTitle: '', showToken: '', note: 'Created hidden, no context.' });
        const { control, panel } = await setup();
        control.click();
        await propose(panel);
        await create(panel, '0');

        expect(panel.querySelector('.cowriter-variant-result').textContent).toBe('Created hidden, no context.');
        expect(panel.querySelector('.cowriter-variant-show')).toBeNull();
        expect(panel.querySelector('.cowriter-variant-edit')).not.toBeNull();
    });

    it('shows the variant, hides the original and syncs its context field in this form', async () => {
        const posts = answer(PROPOSAL);
        const { control, panel } = await setup();
        control.click();
        await propose(panel);
        await create(panel, '5');

        panel.querySelector('.cowriter-variant-show').click();
        await flush();

        expect(posts[2].url).toContain('variant-show');
        expect(posts[2].data).toEqual({ variantUid: 20, originalUid: 10, context: 5, hideOriginal: true, token: 'a'.repeat(64) });
        expect(document.querySelector('select[name="data[tt_content][10][tx_contexts_settings][5][tx_contexts]"]').value).toBe('0');
        expect(panel.querySelector('.cowriter-variant-show')).toBeNull();
        expect(panel.querySelector('.cowriter-variant-result').textContent).toContain('this element is hidden there');
    });

    it('can show the variant and leave the original visible', async () => {
        const posts = answer(PROPOSAL);
        const { control, panel } = await setup();
        control.click();
        await propose(panel);
        await create(panel, '5');
        panel.querySelector('#' + CONTROL_ID + '-hide-original').checked = false;

        panel.querySelector('.cowriter-variant-show').click();
        await flush();

        expect(posts[2].data.hideOriginal).toBe(false);
        expect(document.querySelector('select[name="data[tt_content][10][tx_contexts_settings][5][tx_contexts]"]').value).toBe('');
        expect(panel.querySelector('.cowriter-variant-result').textContent).toContain('stays visible there too');
    });

    it('keeps the show step after a failed show', async () => {
        answer(PROPOSAL, CREATED, new Error('boom'));
        const { control, panel } = await setup();
        control.click();
        await propose(panel);
        await create(panel, '5');

        panel.querySelector('.cowriter-variant-show').click();
        await flush();

        expect(panel.querySelector('.cowriter-variant-show').disabled).toBe(false);
        expect(document.getElementById(CONTROL_ID + '-status').classList.contains('text-danger')).toBe(true);
    });

    it('opens the variant through the content frame', async () => {
        answer(PROPOSAL);
        const { control, panel } = await setup();
        control.click();
        await propose(panel);
        await create(panel, '5');

        panel.querySelector('.cowriter-variant-edit').click();

        expect(Viewport.ContentContainer.setUrl).toHaveBeenCalledWith('/typo3/record/edit?x=20');
    });

    it('reopens on the result instead of a second proposal, and Escape closes', async () => {
        const posts = answer(PROPOSAL);
        const { control, panel } = await setup();
        control.click();
        await propose(panel);
        await create(panel, '5');

        panel.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(panel.hidden).toBe(true);
        expect(document.activeElement).toBe(control);

        control.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));
        expect(panel.hidden).toBe(false);
        expect(panel.querySelector('.cowriter-variant-result')).not.toBeNull();
        expect(posts).toHaveLength(2);
    });

    it('keeps an edited header and the context choice when the panel is reopened', async () => {
        answer(PROPOSAL);
        const { control, panel } = await setup();
        control.click();
        await propose(panel);
        const header = panel.querySelector('input[type="text"]');
        header.value = 'Edited';
        header.dispatchEvent(new Event('input'));
        const context = panel.querySelector('select[data-role="context"]');
        context.value = '5';
        context.dispatchEvent(new Event('change'));

        control.click();
        control.click();

        expect(panel.querySelector('input[type="text"]').value).toBe('Edited');
        expect(panel.querySelector('select[data-role="context"]').value).toBe('5');
    });

    it('goes back to the choice for another proposal', async () => {
        answer(PROPOSAL);
        const { control, panel } = await setup();
        control.click();
        await propose(panel, '32');

        panel.querySelector('.cowriter-variant-retry').click();

        expect(panel.querySelector('select[data-role="audience"]').value).toBe('32');
        expect(panel.querySelector('.cowriter-variant-preview')).toBeNull();
    });
});
