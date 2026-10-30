/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * Tests for "About this suggestion": the section the dialogs render from the
 * `about` object of the Cowriter routes. The fixtures copy the shape
 * AboutBuilder::payload() returns.
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { resetLabels } from '@netresearch/t3_cowriter/Labels';
import {
    isAbout,
    renderAbout,
    showAbout,
    withModel,
} from '../../Resources/Public/JavaScript/Ckeditor/AboutSection.js';

const PAYLOAD = '<img src=x onerror="window.pwned=1">';

/** A field suggestion answered by a model (AboutBuilder::payload()). */
function about(overrides = {}) {
    return {
        origin: 'Cowriter, field suggestions',
        feature: 'field suggestions',
        configuration: 'Default (default)',
        model: 'gpt-5 (OpenAI)',
        note: '',
        sources: ['Field name and current value', 'Page title'],
        settings: [
            { label: 'Feature', value: 'field suggestions is switched on' },
            { label: 'Limits', value: 'at most 3 suggestions' },
        ],
        links: [{ label: 'LLM configurations', url: '/typo3/module/nrllm?token=x' }],
        ...overrides,
    };
}

/** A presentation proposal from the headings (AboutBuilder::presentation() without a model). */
const NO_MODEL = about({
    origin: 'Cowriter, alternative presentation',
    configuration: '',
    model: '',
    sources: [],
    links: [],
    note: 'No language model was asked.',
});

function rows(section) {
    return Array.from(section.querySelectorAll('dt')).map((term) => term.textContent);
}

function valueOf(section, label) {
    const term = Array.from(section.querySelectorAll('dt')).find((dt) => dt.textContent === label);
    return term ? term.nextElementSibling : null;
}

function injectLabels(labels) {
    const element = document.createElement('script');
    element.type = 'application/json';
    element.id = 'cowriter-labels-data';
    element.textContent = JSON.stringify(labels);
    document.head.appendChild(element);
}

describe('AboutSection', () => {
    beforeEach(() => {
        resetLabels();
    });

    afterEach(() => {
        document.getElementById('cowriter-labels-data')?.remove();
        resetLabels();
        document.body.replaceChildren();
    });

    describe('renderAbout', () => {
        it('renders a closed disclosure whose summary names the section', () => {
            const section = renderAbout(about());

            expect(section.tagName).toBe('DETAILS');
            expect(section.open).toBe(false);
            expect(section.classList.contains('cowriter-about')).toBe(true);
            const summary = section.firstElementChild;
            expect(summary.tagName).toBe('SUMMARY');
            expect(summary.textContent).toBe('About this suggestion');
        });

        it('renders every row of a complete answer', () => {
            const section = renderAbout(about());

            expect(rows(section)).toEqual([
                'Created by',
                'nr-llm configuration',
                'Model',
                'Context sent to the model',
                'Current settings',
                'Change the settings',
            ]);
            expect(valueOf(section, 'Created by').textContent).toBe('Cowriter, field suggestions');
            expect(valueOf(section, 'nr-llm configuration').textContent).toBe('Default (default)');
            expect(valueOf(section, 'Model').textContent).toBe('gpt-5 (OpenAI)');
            expect(Array.from(valueOf(section, 'Context sent to the model').querySelectorAll('li'))
                .map((li) => li.textContent)).toEqual(['Field name and current value', 'Page title']);
            expect(Array.from(valueOf(section, 'Current settings').querySelectorAll('li'))
                .map((li) => li.textContent)).toEqual([
                'Feature: field suggestions is switched on',
                'Limits: at most 3 suggestions',
            ]);
            expect(section.querySelector('.cowriter-about-note')).toBeNull();
        });

        it('omits the configuration, model, sources and links rows when they are empty, and shows the note', () => {
            const section = renderAbout(NO_MODEL);

            expect(rows(section)).toEqual(['Created by', 'Current settings']);
            const note = section.querySelector('.cowriter-about-note');
            expect(note.tagName).toBe('P');
            expect(note.textContent).toBe('No language model was asked.');
            // The note follows the list, inside the disclosure.
            expect(note.previousElementSibling.tagName).toBe('DL');
        });

        it('omits the settings row when there are none', () => {
            expect(rows(renderAbout(about({ settings: [] })))).not.toContain('Current settings');
        });

        it('opens links in a new window and says so to screen readers', () => {
            const link = renderAbout(about()).querySelector('a');

            expect(link.getAttribute('href')).toBe('/typo3/module/nrllm?token=x');
            expect(link.target).toBe('_blank');
            expect(link.rel).toBe('noopener');
            const hint = link.querySelector('.visually-hidden');
            expect(hint.textContent).toBe(' (opens in a new window)');
            expect(link.textContent).toBe('LLM configurations (opens in a new window)');
        });

        it.each([
            ['origin', about({ origin: PAYLOAD }), 'Created by'],
            ['configuration', about({ configuration: PAYLOAD }), 'nr-llm configuration'],
            ['model', about({ model: PAYLOAD }), 'Model'],
            ['source', about({ sources: [PAYLOAD] }), 'Context sent to the model'],
        ])('puts a %s with markup in as text', (_name, value, label) => {
            const section = renderAbout(value);

            expect(section.querySelector('img')).toBeNull();
            expect(valueOf(section, label).textContent).toBe(PAYLOAD);
        });

        it('puts a setting label and value with markup in as text', () => {
            const section = renderAbout(about({ settings: [{ label: PAYLOAD, value: PAYLOAD }] }));

            expect(section.querySelector('img')).toBeNull();
            expect(valueOf(section, 'Current settings').textContent).toBe(PAYLOAD + ': ' + PAYLOAD);
        });

        it('puts a link label with markup in as text', () => {
            const section = renderAbout(about({ links: [{ label: PAYLOAD, url: '/x' }] }));

            expect(section.querySelector('img')).toBeNull();
            expect(section.querySelector('a').firstChild.textContent).toBe(PAYLOAD);
        });

        it('puts a note with markup in as text', () => {
            const section = renderAbout(about({ note: PAYLOAD }));

            expect(section.querySelector('img')).toBeNull();
            expect(section.querySelector('.cowriter-about-note').textContent).toBe(PAYLOAD);
        });

        it('returns null for an answer without about data', () => {
            expect(renderAbout(null)).toBeNull();
            expect(renderAbout(undefined)).toBeNull();
        });

        it('uses the labels the backend injected', () => {
            injectLabels({
                'ckeditor.about.title': 'Über diesen Vorschlag',
                'ckeditor.about.label.origin': 'Erstellt von',
                'ckeditor.about.label.configuration': 'nr-llm-Konfiguration',
                'ckeditor.about.label.model': 'Modell',
                'ckeditor.about.label.sources': 'An das Modell gesendet',
                'ckeditor.about.label.settings': 'Aktuelle Einstellungen',
                'ckeditor.about.label.links': 'Einstellungen ändern',
                'ckeditor.about.label.newWindow': '(öffnet in neuem Fenster)',
            });

            const section = renderAbout(about());

            expect(section.querySelector('summary').textContent).toBe('Über diesen Vorschlag');
            expect(rows(section)).toEqual([
                'Erstellt von',
                'nr-llm-Konfiguration',
                'Modell',
                'An das Modell gesendet',
                'Aktuelle Einstellungen',
                'Einstellungen ändern',
            ]);
            expect(section.querySelector('a .visually-hidden').textContent).toBe(' (öffnet in neuem Fenster)');
        });
    });

    describe('isAbout', () => {
        it('accepts the answer of the server', () => {
            expect(isAbout(about())).toBe(true);
            expect(isAbout(NO_MODEL)).toBe(true);
        });

        it.each([
            ['null', null],
            ['a string', 'about'],
            ['a number', 42],
            ['an empty origin', about({ origin: '' })],
            ['a missing origin', about({ origin: undefined })],
            ['a non-string configuration', about({ configuration: null })],
            ['a non-string model', about({ model: 1 })],
            ['a non-string note', about({ note: undefined })],
            ['sources that are not a list', about({ sources: 'Page title' })],
            ['a source that is not a string', about({ sources: ['Page title', 3] })],
            ['settings that are not a list', about({ settings: {} })],
            ['a setting that is null', about({ settings: [null] })],
            ['a setting without a value', about({ settings: [{ label: 'Limits' }] })],
            ['a setting without a label', about({ settings: [{ value: 'on' }] })],
            ['links that are not a list', about({ links: null })],
            ['a link without a url', about({ links: [{ label: 'Open' }] })],
            ['a link without a label', about({ links: [{ url: '/x' }] })],
        ])('rejects %s', (_name, value) => {
            expect(isAbout(value)).toBe(false);
            expect(renderAbout(value)).toBeNull();
        });
    });

    describe('showAbout', () => {
        it('replaces the section in the container instead of adding a second one', () => {
            const container = document.createElement('div');
            const other = document.createElement('p');
            container.append(other);

            showAbout(container, about({ model: 'first' }));
            showAbout(container, about({ model: 'second' }));

            const sections = container.querySelectorAll('.cowriter-about');
            expect(sections).toHaveLength(1);
            expect(valueOf(sections[0], 'Model').textContent).toBe('second');
            expect(container.firstElementChild).toBe(other);
            expect(container.lastElementChild).toBe(sections[0]);
        });

        it('removes the section when the next answer carries none', () => {
            const container = document.createElement('div');
            showAbout(container, about());

            showAbout(container, null);

            expect(container.querySelector('.cowriter-about')).toBeNull();
        });

        it('leaves a section of a nested container alone', () => {
            const container = document.createElement('div');
            const nested = document.createElement('div');
            container.append(nested);
            showAbout(nested, about());

            showAbout(container, null);

            expect(nested.querySelector('.cowriter-about')).not.toBeNull();
        });
    });

    describe('withModel', () => {
        it('appends the model that generated the image', () => {
            expect(withModel('Stored as a.png.', { about: { model: 'gpt-image-2' } }))
                .toBe('Stored as a.png. Generated with gpt-image-2.');
        });

        it.each([
            ['no result', null],
            ['no about', {}],
            ['an empty model', { about: { model: '' } }],
            ['a model that is not a string', { about: { model: 42 } }],
        ])('leaves the message alone for %s', (_name, result) => {
            expect(withModel('Stored as a.png.', result)).toBe('Stored as a.png.');
        });

        it('uses the injected label', () => {
            injectLabels({ 'ckeditor.image.done.model': 'Erzeugt mit %s.' });

            expect(withModel('Gespeichert.', { about: { model: 'gpt-image-2' } })).toBe('Gespeichert. Erzeugt mit gpt-image-2.');
        });
    });
});
