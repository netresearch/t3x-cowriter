/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

// vim: ts=4 sw=4 expandtab colorcolumn=120
// @ts-check

/**
 * HtmlSanitizer - Allow-list sanitiser for HTML that a language model wrote.
 *
 * Every model answer that is shown as markup or inserted into the editor
 * passes through sanitizeHtml(): the task dialog's preview, its streamed
 * preview and its variants, and the translation result.
 *
 * @module HtmlSanitizer
 */

const ALLOWED_TAGS = new Set([
    'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'ins',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'ul', 'ol', 'li', 'dl', 'dt', 'dd',
    'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
    'blockquote', 'pre', 'code', 'hr', 'a', 'img', 'mark',
    'span', 'div', 'figure', 'figcaption', 'sub', 'sup', 'abbr',
]);

const ALLOWED_ATTRS = new Set([
    'href', 'src', 'alt', 'title', 'class', 'colspan', 'rowspan',
    'scope', 'headers', 'width', 'height', 'target', 'rel', 'lang', 'dir',
]);

const DATA_IMAGE = /^data:image\/(png|jpeg|gif|webp)(;base64)?,/;

/**
 * The absolute form of a URL as the browser would load it, or null when it
 * does not parse.
 *
 * @param {string} url
 * @param {Document} doc
 * @returns {URL|null}
 */
function resolveUrl(url, doc) {
    try {
        return new URL(url, doc.baseURI);
    } catch {
        return null;
    }
}

/**
 * The image sources found in the given HTML strings, as absolute URLs.
 * sanitizeHtml() keeps an image from another origin only when its source is
 * in this set, that is when the image was already part of the editor content
 * the model was given.
 *
 * @param {...string} htmlStrings
 * @returns {Set<string>}
 */
export function imageSourcesOf(...htmlStrings) {
    const sources = new Set();
    for (const html of htmlStrings) {
        if (typeof html !== 'string' || html === '') {
            continue;
        }
        const doc = new DOMParser().parseFromString(html, 'text/html');
        for (const img of doc.querySelectorAll('img[src]')) {
            const url = resolveUrl(img.getAttribute('src') || '', document);
            if (url) {
                sources.add(url.href);
            }
        }
    }

    return sources;
}

/**
 * Whether an image source may stay: an inline image (data:image/…), an image
 * of the backend's own origin, or an image the editor content already held.
 *
 * @param {string} src
 * @param {Set<string>} knownImageSources
 * @returns {boolean}
 */
function isAllowedImageSource(src, knownImageSources) {
    // eslint-disable-next-line no-control-regex
    const normalized = src.replace(/[\s\x00-\x1F\x7F-\x9F]/g, '').toLowerCase();
    if (normalized.startsWith('data:')) {
        return DATA_IMAGE.test(normalized);
    }

    const url = resolveUrl(src, document);
    if (!url || (url.protocol !== 'http:' && url.protocol !== 'https:')) {
        return false;
    }

    return url.origin === window.location.origin || knownImageSources.has(url.href);
}

/**
 * Parse an HTML string with DOMParser and keep only known-safe elements and
 * attributes. Every other element is unwrapped (its text stays); link targets
 * with a javascript:, vbscript: or non-image data: scheme are removed; an
 * image whose source isAllowedImageSource() rejects is removed with its
 * element, so the browser never requests it.
 *
 * @param {string} html
 * @param {Set<string>} [knownImageSources] - from imageSourcesOf()
 * @returns {HTMLElement} The sanitized body element
 */
export function sanitizeHtml(html, knownImageSources = new Set()) {
    const doc = new DOMParser().parseFromString(html, 'text/html');

    const walk = (node) => {
        // Use index-based loop so newly inserted (unwrapped) children are visited
        let i = 0;
        while (i < node.children.length) {
            const child = node.children[i];
            if (!ALLOWED_TAGS.has(child.localName)) {
                // Preserve text content, unwrap disallowed element
                while (child.firstChild) {
                    child.parentNode.insertBefore(child.firstChild, child);
                }
                child.remove();
                // Don't increment — next child is now at index i
                continue;
            }
            if (child.localName === 'img'
                && !isAllowedImageSource(child.getAttribute('src') || '', knownImageSources)) {
                child.remove();
                continue;
            }
            for (const attr of [...child.attributes]) {
                if (!ALLOWED_ATTRS.has(attr.name)) {
                    child.removeAttribute(attr.name);
                    continue;
                }
                // URI scheme validation on href and src
                if (attr.name === 'href' || attr.name === 'src') {
                    // Control characters are exactly what an obfuscated scheme hides behind.
                    // eslint-disable-next-line no-control-regex
                    const normalized = attr.value.replace(/[\s\x00-\x1F\x7F-\x9F]/g, '').toLowerCase();
                    if (normalized.startsWith('javascript:')
                        || normalized.startsWith('vbscript:')
                        || (normalized.startsWith('data:') && !DATA_IMAGE.test(normalized))) {
                        child.removeAttribute(attr.name);
                    }
                }
            }
            // Enforce rel="noopener noreferrer" on target="_blank" links
            if (child.localName === 'a' && child.getAttribute('target') === '_blank') {
                child.setAttribute('rel', 'noopener noreferrer');
            }
            walk(child);
            i++;
        }
    };
    walk(doc.body);

    return doc.body;
}
