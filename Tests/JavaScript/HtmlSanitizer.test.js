/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * Tests for the HtmlSanitizer module: the allow-list every model answer
 * passes before it is shown as markup or inserted into the editor.
 */
import { describe, it, expect } from 'vitest';
import { imageSourcesOf, sanitizeHtml } from '../../Resources/Public/JavaScript/Ckeditor/HtmlSanitizer.js';

const sameOrigin = (path) => `${window.location.origin}${path}`;

describe('HtmlSanitizer', () => {
    describe('sanitizeHtml()', () => {
        it('keeps allowed formatting and unwraps other elements, keeping their text', () => {
            const body = sanitizeHtml('<p>Keep <strong>this</strong></p><custom-el>inner</custom-el><script>alert(1)</script>');

            expect(body.querySelector('p strong').textContent).toBe('this');
            expect(body.querySelector('custom-el')).toBeNull();
            expect(body.querySelector('script')).toBeNull();
            expect(body.textContent).toContain('inner');
        });

        it('removes attributes outside the allow-list', () => {
            const body = sanitizeHtml('<p onclick="x()" style="color:red" class="lead">Text</p>');
            const p = body.querySelector('p');

            expect(p.getAttribute('onclick')).toBeNull();
            expect(p.getAttribute('style')).toBeNull();
            expect(p.getAttribute('class')).toBe('lead');
        });

        it('removes link targets with a script or non-image data scheme', () => {
            const body = sanitizeHtml(
                '<a href="java\tscript:x()">a</a><a href="vbscript:x">b</a><a href="data:text/html,x">c</a><a href="https://example.org/">d</a>',
            );
            const links = body.querySelectorAll('a');

            expect(links[0].hasAttribute('href')).toBe(false);
            expect(links[1].hasAttribute('href')).toBe(false);
            expect(links[2].hasAttribute('href')).toBe(false);
            expect(links[3].getAttribute('href')).toBe('https://example.org/');
        });

        it('keeps images of the backend origin, relative images and inline images', () => {
            const body = sanitizeHtml(
                `<img src="${sameOrigin('/fileadmin/a.png')}" alt="a"><img src="fileadmin/b.png" alt="b"><img src="data:image/png;base64,abc" alt="c">`,
            );

            expect([...body.querySelectorAll('img')].map((img) => img.getAttribute('alt'))).toEqual(['a', 'b', 'c']);
        });

        it('removes an image from another origin that the content did not hold', () => {
            const body = sanitizeHtml('<p>Text</p><img src="https://elsewhere.example/pixel.png?q=1" alt="x"><img src="//elsewhere.example/p.png">');

            expect(body.querySelector('img')).toBeNull();
            expect(body.querySelector('p').textContent).toBe('Text');
        });

        it('keeps an image from another origin that the content already held', () => {
            const known = imageSourcesOf('<p>Before</p><img src="https://cdn.example/photo.jpg">');
            const body = sanitizeHtml('<p>After</p><img src="https://cdn.example/photo.jpg" alt="photo"><img src="https://cdn.example/other.jpg">', known);
            const images = body.querySelectorAll('img');

            expect(images).toHaveLength(1);
            expect(images[0].getAttribute('alt')).toBe('photo');
        });

        it('removes images with a non-image data or a script source', () => {
            const body = sanitizeHtml('<img src="data:text/html,x"><img src="javascript:x()"><img src="data:image/svg+xml,<svg/>">');

            expect(body.querySelector('img')).toBeNull();
        });

        it('sets rel="noopener noreferrer" on links that open a new window', () => {
            const body = sanitizeHtml('<a href="https://example.org/" target="_blank" rel="opener">x</a>');

            expect(body.querySelector('a').getAttribute('rel')).toBe('noopener noreferrer');
        });
    });

    describe('imageSourcesOf()', () => {
        it('collects the absolute image sources of every string and skips empty input', () => {
            const sources = imageSourcesOf('<img src="https://cdn.example/a.jpg">', '', '<p><img src="/b.png"></p>');

            expect([...sources]).toEqual(['https://cdn.example/a.jpg', sameOrigin('/b.png')]);
        });
    });
});
