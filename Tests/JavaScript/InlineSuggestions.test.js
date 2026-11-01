/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * The timing of inline suggestions (SuggestionScheduler), the editor's own
 * on/off choice in the browser storage and the route call in AIService. The
 * CKEditor side is in Integration/InlineSuggestions.test.js.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
    SuggestionScheduler,
    MIN_INTERVAL,
    FAILURE_PAUSE,
    readUserChoice,
    writeUserChoice,
    storageKey,
} from '../../Resources/Public/JavaScript/Ckeditor/InlineSuggestions.js';
import { AIService } from '../../Resources/Public/JavaScript/Ckeditor/AIService.js';

const DELAY = 1000;

describe('SuggestionScheduler', () => {
    /** @type {Array<{text: string, signal: AbortSignal, resolve: function(string): void, reject: function(any): void}>} */
    let requests;
    /** @type {string[]} */
    let shown;
    /** @type {string[]} */
    let notices;
    let current;
    let prepared;

    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] });
        requests = [];
        shown = [];
        notices = [];
        current = true;
        prepared = 'Some text before the cursor';
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    function scheduler() {
        return new SuggestionScheduler({
            delay: DELAY,
            prepare: () => (prepared === null ? null : { text: prepared, isCurrent: () => current }),
            request: (text, signal) => new Promise((resolve, reject) => {
                requests.push({ text, signal, resolve, reject });
            }),
            show: (suggestion) => shown.push(suggestion),
            notify: (reason) => notices.push(reason),
        });
    }

    function refusal(status, retryAfter) {
        const error = new Error('refused');
        error.status = status;
        if (retryAfter !== undefined) {
            error.retryAfter = retryAfter;
        }
        return error;
    }

    it('asks once after the pause, not before', async () => {
        const subject = scheduler();

        subject.schedule();
        await vi.advanceTimersByTimeAsync(DELAY - 1);
        expect(requests).toHaveLength(0);

        await vi.advanceTimersByTimeAsync(1);
        expect(requests).toHaveLength(1);
        expect(requests[0].text).toBe('Some text before the cursor');
    });

    it('starts the pause again with every key', async () => {
        const subject = scheduler();

        subject.schedule();
        await vi.advanceTimersByTimeAsync(DELAY - 1);
        subject.schedule();
        await vi.advanceTimersByTimeAsync(DELAY - 1);
        expect(requests).toHaveLength(0);

        await vi.advanceTimersByTimeAsync(1);
        expect(requests).toHaveLength(1);
    });

    it('shows a current answer', async () => {
        const subject = scheduler();
        subject.schedule();
        await vi.advanceTimersByTimeAsync(DELAY);

        requests[0].resolve(' and the rest.');
        await vi.advanceTimersByTimeAsync(0);

        expect(shown).toEqual([' and the rest.']);
    });

    it('drops an answer that is no longer current', async () => {
        const subject = scheduler();
        subject.schedule();
        await vi.advanceTimersByTimeAsync(DELAY);

        current = false;
        requests[0].resolve(' and the rest.');
        await vi.advanceTimersByTimeAsync(0);

        expect(shown).toEqual([]);
    });

    it.each([[''], ['   '], [null]])('shows nothing for the empty answer %j', async (answer) => {
        const subject = scheduler();
        subject.schedule();
        await vi.advanceTimersByTimeAsync(DELAY);

        requests[0].resolve(answer);
        await vi.advanceTimersByTimeAsync(0);

        expect(shown).toEqual([]);
    });

    it('does not ask when nothing fits', async () => {
        prepared = null;
        const subject = scheduler();

        subject.schedule();
        await vi.advanceTimersByTimeAsync(DELAY * 10);

        expect(requests).toHaveLength(0);
    });

    it('aborts the running request on the next key and ignores its answer', async () => {
        const subject = scheduler();
        subject.schedule();
        await vi.advanceTimersByTimeAsync(DELAY);

        subject.schedule();

        expect(requests[0].signal.aborted).toBe(true);
        requests[0].resolve(' late.');
        await vi.advanceTimersByTimeAsync(0);
        expect(shown).toEqual([]);
    });

    it('cancel() forgets the pause and aborts the request', async () => {
        const subject = scheduler();
        subject.schedule();
        await vi.advanceTimersByTimeAsync(DELAY);
        subject.schedule();

        subject.cancel();
        await vi.advanceTimersByTimeAsync(DELAY * 10);

        expect(requests).toHaveLength(1);
        expect(requests[0].signal.aborted).toBe(true);
    });

    it('waits for the minimum interval between two requests and then asks', async () => {
        const subject = scheduler();
        subject.schedule();
        await vi.advanceTimersByTimeAsync(DELAY);
        requests[0].resolve('');
        await vi.advanceTimersByTimeAsync(0);

        subject.schedule();
        await vi.advanceTimersByTimeAsync(DELAY);
        expect(requests).toHaveLength(1);

        await vi.advanceTimersByTimeAsync(MIN_INTERVAL - DELAY - 1);
        expect(requests).toHaveLength(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(requests).toHaveLength(2);
    });

    it('is silent about an aborted request and asks again later', async () => {
        const subject = scheduler();
        subject.schedule();
        await vi.advanceTimersByTimeAsync(DELAY);
        const abort = new Error('aborted');
        abort.name = 'AbortError';
        requests[0].reject(abort);
        await vi.advanceTimersByTimeAsync(0);

        subject.schedule();
        await vi.advanceTimersByTimeAsync(MIN_INTERVAL);

        expect(requests).toHaveLength(2);
        expect(subject.stopped).toBe(false);
    });

    it('pauses for Retry-After after a rate limit', async () => {
        const subject = scheduler();
        subject.schedule();
        await vi.advanceTimersByTimeAsync(DELAY);
        requests[0].reject(refusal(429, 20));
        await vi.advanceTimersByTimeAsync(0);

        subject.schedule();
        await vi.advanceTimersByTimeAsync(20000 - 1);
        expect(requests).toHaveLength(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(requests).toHaveLength(2);
    });

    it('pauses for a minute after a rate limit without Retry-After', async () => {
        const subject = scheduler();
        subject.schedule();
        await vi.advanceTimersByTimeAsync(DELAY);
        requests[0].reject(refusal(429));
        await vi.advanceTimersByTimeAsync(0);

        subject.schedule();
        await vi.advanceTimersByTimeAsync(60000 - 1);
        expect(requests).toHaveLength(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(requests).toHaveLength(2);
    });

    it('tells the editor once why a guardrail stopped a suggestion', async () => {
        const subject = scheduler();
        const stop = (reason) => Object.assign(new Error(reason), { status: 502, guardrailStop: true });

        subject.schedule();
        await vi.advanceTimersByTimeAsync(DELAY);
        requests[0].reject(stop('Es enthält Rabatt.'));
        await vi.advanceTimersByTimeAsync(0);

        subject.schedule();
        await vi.advanceTimersByTimeAsync(FAILURE_PAUSE);
        requests[1].reject(stop('Es enthält Sale.'));
        await vi.advanceTimersByTimeAsync(0);

        subject.schedule();
        await vi.advanceTimersByTimeAsync(FAILURE_PAUSE);
        requests[2].reject(stop('Es enthält Rabatt.'));
        await vi.advanceTimersByTimeAsync(0);

        expect(notices).toEqual(['Es enthält Rabatt.', 'Es enthält Sale.']);
    });

    it('pauses after a failed request', async () => {
        const subject = scheduler();
        subject.schedule();
        await vi.advanceTimersByTimeAsync(DELAY);
        requests[0].reject(refusal(502));
        await vi.advanceTimersByTimeAsync(0);

        subject.schedule();
        await vi.advanceTimersByTimeAsync(FAILURE_PAUSE - 1);
        expect(requests).toHaveLength(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(requests).toHaveLength(2);
    });

    it.each([
        ['the route refuses with 403', () => refusal(403)],
        ['the budget is used up', () => Object.assign(refusal(429), { budgetExceeded: true })],
    ])('stops for good when %s', async (name, error) => {
        const subject = scheduler();
        subject.schedule();
        await vi.advanceTimersByTimeAsync(DELAY);
        requests[0].reject(error());
        await vi.advanceTimersByTimeAsync(0);

        subject.schedule();
        await vi.advanceTimersByTimeAsync(FAILURE_PAUSE * 10);

        expect(subject.stopped).toBe(true);
        expect(requests).toHaveLength(1);
    });
});

describe('the editor\'s own choice', () => {
    function memoryStorage() {
        const values = new Map();
        return {
            getItem: (key) => (values.has(key) ? values.get(key) : null),
            setItem: (key, value) => values.set(key, String(value)),
        };
    }

    it('is on until switched off, per backend user', () => {
        const storage = memoryStorage();

        expect(readUserChoice(7, storage)).toBe(true);
        writeUserChoice(7, false, storage);
        expect(readUserChoice(7, storage)).toBe(false);
        expect(readUserChoice(8, storage)).toBe(true);
        expect(storage.getItem(storageKey(7))).toBe('off');

        writeUserChoice(7, true, storage);
        expect(readUserChoice(7, storage)).toBe(true);
    });

    it('counts as on, and is not kept, when the storage throws', () => {
        const storage = {
            getItem: () => { throw new Error('SecurityError'); },
            setItem: () => { throw new Error('QuotaExceededError'); },
        };

        expect(readUserChoice(7, storage)).toBe(true);
        expect(() => writeUserChoice(7, false, storage)).not.toThrow();
    });
});

describe('AIService.inlineSuggestion', () => {
    beforeEach(() => {
        globalThis.TYPO3 = { settings: { ajaxUrls: { tx_cowriter_inline_suggestion: '/typo3/ajax/inline' } } };
    });

    afterEach(() => {
        delete globalThis.TYPO3;
        vi.restoreAllMocks();
    });

    function respond(status, body, headers = {}) {
        globalThis.fetch = vi.fn(async () => ({
            ok: status >= 200 && status < 300,
            status,
            headers: { get: (name) => headers[name] ?? null },
            json: async () => body,
        }));
        return globalThis.fetch;
    }

    it('posts the text, the record and the abort signal', async () => {
        const fetchMock = respond(200, { success: true, suggestion: ' goes on.' });
        const controller = new AbortController();

        const suggestion = await new AIService().inlineSuggestion('Text', { table: 'tt_content', uid: 3 }, controller.signal);

        expect(suggestion).toBe(' goes on.');
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/typo3/ajax/inline');
        expect(init.method).toBe('POST');
        expect(JSON.parse(init.body)).toEqual({ text: 'Text', recordContext: { table: 'tt_content', uid: 3 } });
        expect(init.signal).toBe(controller.signal);
    });

    it('sends no record when there is none', async () => {
        const fetchMock = respond(200, { success: true, suggestion: '' });

        await new AIService().inlineSuggestion('Text');

        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ text: 'Text' });
    });

    it('answers an empty string for an answer without a suggestion', async () => {
        respond(200, { success: true, suggestion: 42 });

        expect(await new AIService().inlineSuggestion('Text')).toBe('');
    });

    it('throws the status and Retry-After of a rate limit', async () => {
        respond(429, { success: false, error: 'Rate limit exceeded.' }, { 'Retry-After': '17' });

        await expect(new AIService().inlineSuggestion('Text')).rejects.toMatchObject({
            message: 'Rate limit exceeded.',
            status: 429,
            retryAfter: 17,
        });
    });

    it('throws the status of a refusal', async () => {
        respond(403, { success: false, error: 'Switched off.' });

        const error = await new AIService().inlineSuggestion('Text').catch((e) => e);

        expect(error.status).toBe(403);
        expect(error.retryAfter).toBeUndefined();
    });

    it('refuses without the route', async () => {
        globalThis.TYPO3 = { settings: { ajaxUrls: {} } };

        await expect(new AIService().inlineSuggestion('Text')).rejects.toThrow('Inline suggestion route not configured.');
    });
});
