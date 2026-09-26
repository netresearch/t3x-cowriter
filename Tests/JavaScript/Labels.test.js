/**
 * Tests for the Labels module: texts injected by InjectAjaxUrlsListener as
 * JSON, with the English text of each call site as fallback.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { t, resetLabels } from '../../Resources/Public/JavaScript/Ckeditor/Labels.js';

function inject(content) {
    const element = document.createElement('script');
    element.type = 'application/json';
    element.id = 'cowriter-labels-data';
    element.textContent = content;
    document.head.appendChild(element);
}

describe('Labels', () => {
    beforeEach(() => {
        resetLabels();
    });

    afterEach(() => {
        document.getElementById('cowriter-labels-data')?.remove();
        resetLabels();
        vi.restoreAllMocks();
    });

    it('returns the fallback when the backend injected no labels', () => {
        expect(t('ckeditor.button.translate', 'Cowriter - Translate')).toBe('Cowriter - Translate');
    });

    it('returns the injected label', () => {
        inject(JSON.stringify({ 'ckeditor.button.translate': 'Cowriter - Übersetzen' }));

        expect(t('ckeditor.button.translate', 'Cowriter - Translate')).toBe('Cowriter - Übersetzen');
    });

    it('returns the fallback for a key the backend did not send', () => {
        inject(JSON.stringify({ 'ckeditor.button.translate': 'Cowriter - Übersetzen' }));

        expect(t('ckeditor.button.tasks', 'Cowriter - Tasks')).toBe('Cowriter - Tasks');
    });

    it('fills the placeholders in order, in the label and in the fallback', () => {
        inject(JSON.stringify({ 'ckeditor.dialog.debug.tokens': 'Tokens: %s Prompt + %s Antwort = %s gesamt' }));

        expect(t('ckeditor.dialog.debug.tokens', 'x', 10, 5, 15)).toBe('Tokens: 10 Prompt + 5 Antwort = 15 gesamt');
        expect(t('ckeditor.translate.running.message', 'Translating to %s', 'German')).toBe('Translating to German');
    });

    it('inserts an argument literally, even when it contains a replacement pattern', () => {
        expect(t('ckeditor.dialog.error', 'Error: %s', 'costs $& and %s')).toBe('Error: costs $& and %s');
        expect(t('ckeditor.dialog.debug.tokens', '%s + %s', '%s', 'b')).toBe('%s + b');
    });

    it('ignores values that are not non-empty strings', () => {
        inject(JSON.stringify({ 'ckeditor.dialog.task': '', 'ckeditor.dialog.result': 42 }));

        expect(t('ckeditor.dialog.task', 'Task')).toBe('Task');
        expect(t('ckeditor.dialog.result', 'Result')).toBe('Result');
    });

    it('falls back when the injected data is not valid JSON', () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        inject('{not json');

        expect(t('ckeditor.dialog.task', 'Task')).toBe('Task');
        expect(console.error).toHaveBeenCalled();
    });

    it('picks up the element when it appears after a first lookup', () => {
        expect(t('ckeditor.dialog.task', 'Task')).toBe('Task');

        inject(JSON.stringify({ 'ckeditor.dialog.task': 'Aufgabe' }));

        expect(t('ckeditor.dialog.task', 'Task')).toBe('Aufgabe');
    });
});
