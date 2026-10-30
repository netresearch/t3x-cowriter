/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

// vim: ts=4 sw=4 expandtab colorcolumn=120
// @ts-check

import { t } from '@netresearch/t3_cowriter/Labels';

/**
 * @typedef {Object} About
 * @property {string} origin - which extension and feature answered
 * @property {string} configuration - the nr-llm configuration, '' when none
 * @property {string} model - the model and provider that answered, '' when no model was asked
 * @property {string} note - a remark such as "no language model was asked", may be ''
 * @property {string[]} sources - what was sent, as names and counts, never the data
 * @property {{label: string, value: string}[]} settings - current settings of the feature
 * @property {{label: string, url: string}[]} links - settings the user may open
 */

/**
 * "About this suggestion": a collapsed disclosure that says where a dialog's
 * answer comes from (the server's `about` object of the Cowriter routes).
 *
 * A native <details>/<summary> element: the summary is a button for the
 * keyboard (Enter, Space) and for screen readers, which also announce whether
 * it is expanded. Every text from the server goes in as text, never as markup;
 * links open in a new window so the edited record stays open.
 *
 * @param {unknown} about - the `about` object of a route's answer
 * @param {Document} [doc]
 * @returns {HTMLDetailsElement|null} null when the answer carries none
 */
export function renderAbout(about, doc = document) {
    if (!isAbout(about)) {
        return null;
    }

    /** @param {string} value */
    const text = (value) => doc.createTextNode(value);
    const details = doc.createElement('details');
    details.className = 'cowriter-about mt-3';

    const summary = doc.createElement('summary');
    summary.className = 'cowriter-about-summary';
    summary.textContent = t('ckeditor.about.title', 'About this suggestion');
    details.append(summary);

    const list = doc.createElement('dl');
    list.className = 'cowriter-about-list mt-2 mb-0';
    const add = (label, ...values) => {
        const term = doc.createElement('dt');
        term.textContent = label;
        const description = doc.createElement('dd');
        description.append(...values);
        list.append(term, description);
    };

    add(t('ckeditor.about.label.origin', 'Created by'), text(about.origin));
    if (about.configuration !== '') {
        add(t('ckeditor.about.label.configuration', 'nr-llm configuration'), text(about.configuration));
    }
    if (about.model !== '') {
        add(t('ckeditor.about.label.model', 'Model'), text(about.model));
    }
    if (about.sources.length > 0) {
        add(t('ckeditor.about.label.sources', 'Context sent to the model'), items(doc, about.sources.map((source) => text(source))));
    }
    if (about.settings.length > 0) {
        add(t('ckeditor.about.label.settings', 'Current settings'), items(doc, about.settings.map((setting) => {
            const name = doc.createElement('span');
            name.className = 'fw-bold';
            name.textContent = setting.label + ': ';
            const span = doc.createElement('span');
            span.append(name, text(setting.value));
            return span;
        })));
    }
    if (about.links.length > 0) {
        add(t('ckeditor.about.label.links', 'Change the settings'), items(doc, about.links.map((link) => {
            const anchor = doc.createElement('a');
            anchor.className = 'link-primary text-decoration-underline';
            anchor.href = link.url;
            anchor.target = '_blank';
            anchor.rel = 'noopener';
            anchor.textContent = link.label;
            const hint = doc.createElement('span');
            hint.className = 'visually-hidden';
            hint.textContent = ' ' + t('ckeditor.about.label.newWindow', '(opens in a new window)');
            anchor.append(hint);
            return anchor;
        })));
    }
    details.append(list);

    if (about.note !== '') {
        const note = doc.createElement('p');
        note.className = 'cowriter-about-note form-text mb-0';
        note.textContent = about.note;
        details.append(note);
    }

    return details;
}

/**
 * A success message with the model that generated the image appended, when
 * the generation route's answer names it ("Generated with gpt-image-2.").
 *
 * @param {string} message
 * @param {{about?: {model?: unknown}}|null|undefined} result
 * @returns {string}
 */
export function withModel(message, result) {
    const model = result?.about?.model;
    return typeof model === 'string' && model !== ''
        ? message + ' ' + t('ckeditor.image.done.model', 'Generated with %s.', model)
        : message;
}

/**
 * Shape check of the server's object, so a malformed answer renders nothing
 * rather than half a section.
 *
 * @param {unknown} value
 * @returns {value is About}
 */
export function isAbout(value) {
    if (!value || typeof value !== 'object') {
        return false;
    }
    const about = /** @type {Record<string, unknown>} */ (value);
    const pairs = (list, keys) => Array.isArray(list)
        && list.every((entry) => entry && typeof entry === 'object' && keys.every((key) => typeof entry[key] === 'string'));

    return typeof about.origin === 'string' && about.origin !== ''
        && typeof about.configuration === 'string'
        && typeof about.model === 'string'
        && typeof about.note === 'string'
        && Array.isArray(about.sources) && about.sources.every((source) => typeof source === 'string')
        && pairs(about.settings, ['label', 'value'])
        && pairs(about.links, ['label', 'url']);
}

/**
 * Replace the about section inside $container, or remove it when the answer
 * carries none.
 *
 * @param {HTMLElement} container
 * @param {unknown} about
 */
export function showAbout(container, about) {
    container.querySelector(':scope > .cowriter-about')?.remove();
    const section = renderAbout(about, container.ownerDocument);
    if (section) {
        container.append(section);
    }
}

/**
 * @param {Document} doc
 * @param {Node[]} nodes
 * @returns {HTMLUListElement}
 */
function items(doc, nodes) {
    const list = doc.createElement('ul');
    list.className = 'list-unstyled mb-0';
    for (const node of nodes) {
        const item = doc.createElement('li');
        item.append(node);
        list.append(item);
    }
    return list;
}
