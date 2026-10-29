/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

// vim: ts=4 sw=4 expandtab colorcolumn=120
// @ts-check

import { Plugin } from "@ckeditor/ckeditor5-core";
import { ButtonView } from "@ckeditor/ckeditor5-ui";
import { AIService } from "@netresearch/t3_cowriter/AIService";
import { t } from "@netresearch/t3_cowriter/Labels";

/**
 * Name of the model marker that holds the place of a shown suggestion.
 */
export const MARKER_NAME = 'cowriterInlineSuggestion';

/**
 * Shortest time between two requests of one editor, in milliseconds. With
 * the server's limit of 12 per minute (Services.yaml) this is the most an
 * editor can ask for.
 */
export const MIN_INTERVAL = 5000;

/**
 * Pause after a failed request (other than a refusal) before the next one.
 */
export const FAILURE_PAUSE = 30000;

/**
 * Most characters of text before the cursor a request carries; the route
 * refuses more (InlineSuggestionRequest::MAX_TEXT_LENGTH).
 */
export const MAX_CONTEXT = 1000;

/**
 * Model elements a suggestion may appear in: paragraphs and headings. Other
 * blocks that take text (image captions, code blocks) are left alone, so a
 * suggestion never competes with Tab in a code block or lands in a caption.
 */
export const SUGGESTION_BLOCKS = /^(paragraph|heading[1-6])$/;

/**
 * Milliseconds after a suggestion appears during which Tab does not accept
 * it but keeps its normal job (leaving the field) and discards the
 * suggestion. A Tab within this time was planned before the editor could
 * have seen or heard the suggestion: reacting to a visual change alone takes
 * about 250 ms, reading a few words takes longer, and a screen reader speaks
 * a polite announcement only after its current utterance. 800 ms covers an
 * already planned key press without making a deliberate accept wait
 * noticeably.
 */
export const TAB_GUARD = 800;

/**
 * Every live plugin instance on this page, so the editor's own on/off choice
 * reaches all rich text fields at once.
 *
 * @type {Set<InlineSuggestions>}
 */
const instances = new Set();

/**
 * Keys that only modify another key; pressing one keeps a suggestion shown.
 */
const MODIFIER_KEYS = new Set(['Shift', 'Control', 'Alt', 'AltGraph', 'Meta', 'CapsLock', 'Fn', 'OS']);

const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">'
    + '<path d="M3 5h11v2H3zm0 4h8v2H3zm0 4h11v2H3zm0 4h6v2H3z"/>'
    + '<path d="M16 9h2v6h-2z" opacity=".55"/><path d="m19 3 .9 2.1L22 6l-2.1.9L19 9l-.9-2.1L16 6l2.1-.9z"/></svg>';

/**
 * Key of the editor's own on/off choice in the browser storage. The storage
 * belongs to the browser profile, so the key names the backend user.
 *
 * @param {number|string} userId
 * @returns {string}
 */
export function storageKey(userId) {
    return `t3_cowriter.inlineSuggestions.${userId}`;
}

/**
 * The editor's own choice; on unless they switched it off. Storage that
 * cannot be read (private mode, blocked site data) counts as on.
 *
 * @param {number|string} userId
 * @param {Storage|undefined} [storage]
 * @returns {boolean}
 */
export function readUserChoice(userId, storage = globalThis.localStorage) {
    try {
        return storage?.getItem(storageKey(userId)) !== 'off';
    } catch {
        return true;
    }
}

/**
 * @param {number|string} userId
 * @param {boolean} on
 * @param {Storage|undefined} [storage]
 */
export function writeUserChoice(userId, on, storage = globalThis.localStorage) {
    try {
        storage?.setItem(storageKey(userId), on ? 'on' : 'off');
    } catch {
        // The choice then lasts until the page is left.
    }
}

/**
 * When to ask for a suggestion: after a pause in typing, not more often than
 * every MIN_INTERVAL, never while the previous request runs, and never after
 * the route refused for good (403, or the budget used up). Each new key cancels the pending pause
 * and aborts a running request. An answer is shown only when the editor is
 * still where the request was made.
 *
 * The CKEditor side is passed in, so the timing can be tested on its own.
 */
export class SuggestionScheduler {
    /**
     * @param {object} options
     * @param {number} options.delay - pause after the last key, in milliseconds
     * @param {function(): ({text: string, isCurrent: function(): boolean}|null)} options.prepare -
     *     what to ask for, or null when no suggestion fits right now
     * @param {function(string, AbortSignal): Promise<string>} options.request
     * @param {function(string): void} options.show
     * @param {number} [options.minInterval]
     * @param {function(): number} [options.now]
     * @param {{setTimeout: function(function(): void, number): any, clearTimeout: function(any): void}} [options.timers]
     */
    constructor({ delay, prepare, request, show, minInterval = MIN_INTERVAL, now = () => Date.now(), timers = globalThis }) {
        this._delay = delay;
        this._prepare = prepare;
        this._request = request;
        this._show = show;
        this._minInterval = minInterval;
        this._now = now;
        this._timers = timers;
        this._timer = null;
        /** @type {AbortController|null} */
        this._controller = null;
        this._lastRequestAt = Number.NEGATIVE_INFINITY;
        this._pausedUntil = Number.NEGATIVE_INFINITY;
        this._stopped = false;
    }

    /**
     * Whether the route refused for good; no request is made any more.
     * @returns {boolean}
     */
    get stopped() {
        return this._stopped;
    }

    /**
     * The editor typed: wait for the next pause.
     */
    schedule() {
        this.cancel();
        if (this._stopped) {
            return;
        }
        this._timer = this._timers.setTimeout(() => this._fire(), this._delay);
    }

    /**
     * Forget the pending pause and abort a running request.
     */
    cancel() {
        if (this._timer !== null) {
            this._timers.clearTimeout(this._timer);
            this._timer = null;
        }
        if (this._controller !== null) {
            this._controller.abort();
            this._controller = null;
        }
    }

    /**
     * @returns {Promise<void>|undefined}
     * @private
     */
    _fire() {
        this._timer = null;
        const wait = Math.max(this._pausedUntil, this._lastRequestAt + this._minInterval) - this._now();
        if (wait > 0) {
            this._timer = this._timers.setTimeout(() => this._fire(), wait);
            return undefined;
        }

        const prepared = this._prepare();
        if (prepared === null) {
            return undefined;
        }

        this._lastRequestAt = this._now();
        const controller = new AbortController();
        this._controller = controller;

        return this._request(prepared.text, controller.signal).then(
            (suggestion) => {
                if (this._controller !== controller) {
                    return;
                }
                this._controller = null;
                if (typeof suggestion === 'string' && suggestion.trim() !== '' && prepared.isCurrent()) {
                    this._show(suggestion);
                }
            },
            (error) => {
                if (this._controller === controller) {
                    this._controller = null;
                }
                if (error?.name === 'AbortError' || controller.signal.aborted) {
                    return;
                }
                if (error?.status === 403 || error?.budgetExceeded === true) {
                    // Switched off, no configuration the editor may use, or
                    // the editor's budget used up: none of it ends while the
                    // page is open.
                    this._stopped = true;
                } else if (error?.status === 429) {
                    const seconds = Number(error?.retryAfter);
                    this._pausedUntil = this._now() + (Number.isFinite(seconds) && seconds > 0 ? seconds * 1000 : 60000);
                } else {
                    this._pausedUntil = this._now() + FAILURE_PAUSE;
                }
            },
        );
    }
}

/**
 * The text of a model element, inline objects left out and soft breaks as
 * line breaks.
 *
 * @param {any} model
 * @param {any} element
 * @returns {string}
 */
function textOf(model, element) {
    let text = '';
    for (const item of model.createRangeIn(element).getItems()) {
        if (item.is('$textProxy')) {
            text += item.data;
        } else if (item.is('element', 'softBreak')) {
            text += '\n';
        }
    }
    return text;
}

/**
 * Inline suggestions in the rich text editor: after a pause in typing at the
 * end of a paragraph, the language model proposes how the text goes on. The
 * proposal is grey text after the cursor; Tab inserts it, Escape or any other
 * key, a click or leaving the editor discards it.
 *
 * The grey text exists only in the editing view: a collapsed model marker
 * (not part of the data, no operation, so neither undo nor the change
 * detection of the form sees it) is converted for the editing pipeline only,
 * into a UIElement. getData() never contains it.
 *
 * Nothing is wired while the administrator's setting is off (no
 * `cowriter.inlineSuggestions` in the editor configuration): no listener, no
 * request. The toolbar toggle is registered in every case.
 */
export class InlineSuggestions extends Plugin {
    static pluginName = 'cowriterInlineSuggestions';

    /**
     * The shown suggestion, null when none is shown.
     * @type {string|null}
     */
    _suggestion = null;

    /** @type {HTMLElement|null} */
    _liveRegion = null;

    /** @type {SuggestionScheduler|null} */
    _scheduler = null;

    init() {
        const editor = this.editor;
        const settings = editor.config.get('cowriter')?.inlineSuggestions;
        const available = settings?.enabled === true;
        this._userId = settings?.userId ?? 0;

        this.set('isOn', available && readUserChoice(this._userId));

        /** @type {number} */
        this._shownAt = Number.NEGATIVE_INFINITY;

        editor.ui.componentFactory.add(InlineSuggestions.pluginName, () => {
            const button = new ButtonView();
            button.set({
                label: t('ckeditor.button.inlineSuggestions', 'Cowriter - Inline suggestions'),
                tooltip: true,
                icon: ICON,
                isToggleable: true,
                isEnabled: available,
            });
            button.bind('isOn').to(this, 'isOn');
            button.on('execute', () => {
                this.setUserChoice(!this.isOn);
                editor.editing.view.focus();
            });
            return button;
        });

        if (!available) {
            return;
        }

        const delay = Number(settings.delay);
        const minChars = Number(settings.minChars);
        this._minChars = Number.isFinite(minChars) && minChars > 0 ? minChars : 30;
        this._service = new AIService();
        this._scheduler = new SuggestionScheduler({
            delay: Number.isFinite(delay) && delay > 0 ? delay : 1200,
            prepare: () => this._prepare(),
            request: (text, signal) => this._service.inlineSuggestion(text, this._recordContext(), signal),
            show: (suggestion) => this._showSuggestion(suggestion),
        });

        this._setUpConversion();
        this._setUpListeners();

        // The choice made in another tab or window of the backend.
        this._onStorage = (event) => {
            if (event.key === storageKey(this._userId)) {
                this._applyUserChoice(event.newValue !== 'off');
            }
        };
        globalThis.addEventListener?.('storage', this._onStorage);
        instances.add(this);
    }

    /**
     * Switch suggestions on or off for the editor's user, remember the
     * choice, and apply it to every rich text field on this page. Other tabs
     * and windows follow through the storage event.
     *
     * @param {boolean} on
     */
    setUserChoice(on) {
        if (this._scheduler === null) {
            return;
        }
        writeUserChoice(this._userId, on);
        for (const instance of instances) {
            if (instance._userId === this._userId) {
                instance._applyUserChoice(on);
            }
        }
    }

    /**
     * @param {boolean} on
     * @private
     */
    _applyUserChoice(on) {
        this.isOn = on;
        if (!on) {
            this._scheduler.cancel();
            this._hideSuggestion();
        }
    }

    /**
     * The shown suggestion, null when none is shown.
     * @returns {string|null}
     */
    get suggestion() {
        return this._suggestion;
    }

    destroy() {
        instances.delete(this);
        if (this._onStorage) {
            globalThis.removeEventListener?.('storage', this._onStorage);
        }
        this._scheduler?.cancel();
        this._liveRegion?.remove();
        this._liveRegion = null;
        super.destroy();
    }

    /**
     * The marker becomes a UIElement in the editing view only. Its DOM is
     * built with textContent; it is hidden from assistive technology, which
     * hears the live region instead.
     *
     * @private
     */
    _setUpConversion() {
        const plugin = this;
        this.editor.conversion.for('editingDowncast').markerToElement({
            model: MARKER_NAME,
            view: (data, { writer }) => writer.createUIElement('span', {
                class: 'cowriter-inline-suggestion',
                'aria-hidden': 'true',
                contenteditable: 'false',
                'data-cowriter-inline-suggestion': '',
            }, function (domDocument) {
                const dom = this.toDomElement(domDocument);
                dom.textContent = plugin._suggestion ?? '';
                // CSSOM, not a style attribute: allowed by the backend's CSP.
                dom.style.opacity = '0.5';
                dom.style.pointerEvents = 'none';
                dom.style.userSelect = 'none';
                dom.style.whiteSpace = 'pre-wrap';
                return dom;
            }),
        });
    }

    /**
     * @private
     */
    _setUpListeners() {
        const editor = this.editor;
        const model = editor.model;
        const viewDocument = editor.editing.view.document;

        // Typing schedules a suggestion; any other change of the content
        // (paste, undo, setData, an accepted suggestion) does not. Every
        // change aborts a running request, so an answer never meets text
        // other than the text it was asked for.
        this.listenTo(model.document, 'change:data', (evt, batch) => {
            this._hideSuggestion();
            if (batch.isTyping && batch.isLocal) {
                this._scheduler.schedule();
            } else {
                this._scheduler.cancel();
            }
        });

        // The caret moved away from the suggestion.
        this.listenTo(model.document, 'change', () => {
            if (this._suggestion !== null && !this._markerAtCaret()) {
                this._hideSuggestion();
            }
        });

        // Ahead of every other keydown handler: only while a suggestion is
        // shown, Tab accepts and Escape discards; any other key discards and
        // goes on as usual. Without a suggestion nothing is touched, so Tab
        // keeps its own behaviour. A Tab right after the suggestion appeared
        // was meant to leave the field (TAB_GUARD): it discards and goes on.
        this.listenTo(viewDocument, 'keydown', (evt, data) => {
            if (this._suggestion === null) {
                return;
            }
            const key = data.domEvent?.key;
            const plain = !data.shiftKey && !data.ctrlKey && !data.altKey && !data.metaKey;
            const isTab = key === 'Tab' || data.keyCode === 9;
            if (isTab && plain && Date.now() - this._shownAt >= TAB_GUARD) {
                this._acceptSuggestion();
                data.preventDefault();
                evt.stop();
                return;
            }
            if (key === 'Escape' || data.keyCode === 27) {
                this._scheduler.cancel();
                this._hideSuggestion();
                data.preventDefault();
                evt.stop();
                return;
            }
            if (!MODIFIER_KEYS.has(key)) {
                this._hideSuggestion();
            }
        }, { priority: 'highest' });

        const discard = () => {
            this._scheduler.cancel();
            this._hideSuggestion();
        };
        this.listenTo(viewDocument, 'mousedown', discard);
        this.listenTo(viewDocument, 'compositionstart', discard);
        this.listenTo(viewDocument, 'change:isFocused', (evt, name, isFocused) => {
            if (!isFocused) {
                discard();
            }
        });
        this.listenTo(editor, 'change:isReadOnly', discard);
    }

    /**
     * What to ask for now, or null when no suggestion fits: suggestions
     * switched off, a selection, a composition (IME) in progress, the editor
     * not focused or read-only, source editing, the cursor not at the end of
     * a text block, a list item or table cell, or fewer than minChars
     * characters in that block.
     *
     * Not in list items and table cells: there Tab indents the item or moves
     * to the next cell, so a suggestion would compete with it.
     *
     * The text is the block's, after up to the preceding blocks' text, cut to
     * the last MAX_CONTEXT characters (code points, so a surrogate pair is
     * never split).
     *
     * @returns {{text: string, isCurrent: function(): boolean}|null}
     * @private
     */
    _prepare() {
        const editor = this.editor;
        const model = editor.model;
        const view = editor.editing.view.document;
        const selection = model.document.selection;

        if (!this.isOn || editor.isReadOnly || !view.isFocused || view.isComposing || this._inSourceEditing()
            || !selection.isCollapsed) {
            return null;
        }

        const position = selection.getFirstPosition();
        const block = position?.parent;
        if (!block || !block.is('element') || !SUGGESTION_BLOCKS.test(block.name) || !position.isAtEnd
            || block.hasAttribute('listItemId')
            || block.findAncestor('tableCell') !== null) {
            return null;
        }

        const blockText = textOf(model, block);
        if (blockText.trim().length < this._minChars) {
            return null;
        }

        let text = blockText;
        for (let sibling = block.previousSibling; sibling && text.length < MAX_CONTEXT; sibling = sibling.previousSibling) {
            if (sibling.is('element')) {
                text = `${textOf(model, sibling)}\n\n${text}`;
            }
        }
        const characters = Array.from(text);
        if (characters.length > MAX_CONTEXT) {
            text = characters.slice(-MAX_CONTEXT).join('');
        }

        // The text cannot have changed by the time the answer arrives: every
        // change of the content aborts the request. The cursor can have moved.
        return {
            text,
            isCurrent: () => selection.getFirstPosition()?.isEqual(position) === true && this._prepare() !== null,
        };
    }

    /**
     * @param {string} suggestion
     * @private
     */
    _showSuggestion(suggestion) {
        const model = this.editor.model;
        this._hideSuggestion();
        this._suggestion = suggestion;
        this._shownAt = Date.now();
        model.enqueueChange({ isUndoable: false }, (writer) => {
            writer.addMarker(MARKER_NAME, {
                range: model.createRange(model.document.selection.getFirstPosition()),
                usingOperation: false,
                affectsData: false,
            });
        });
        this._announce(t(
            'ckeditor.inlineSuggestion.available',
            'Suggestion available, press Tab to accept: %s',
            suggestion.trim(),
        ));
    }

    /**
     * @private
     */
    _hideSuggestion() {
        const model = this.editor.model;
        if (this._suggestion === null) {
            return;
        }
        this._suggestion = null;
        if (model.markers.has(MARKER_NAME)) {
            model.enqueueChange({ isUndoable: false }, (writer) => writer.removeMarker(MARKER_NAME));
        }
        this._announce('');
    }

    /**
     * Insert the suggestion as plain text with the attributes at the caret,
     * as one undo step.
     *
     * @private
     */
    _acceptSuggestion() {
        const model = this.editor.model;
        const text = this._suggestion;
        this._hideSuggestion();
        if (!text) {
            return;
        }
        model.change((writer) => {
            model.insertContent(writer.createText(text, model.document.selection.getAttributes()));
        });
    }

    /**
     * @returns {boolean}
     * @private
     */
    _markerAtCaret() {
        const model = this.editor.model;
        const marker = model.markers.get(MARKER_NAME);
        const selection = model.document.selection;
        return marker !== null && selection.isCollapsed
            && marker.getStart().isEqual(selection.getFirstPosition());
    }

    /**
     * @returns {boolean}
     * @private
     */
    _inSourceEditing() {
        const plugins = this.editor.plugins;
        return plugins.has('SourceEditing') && plugins.get('SourceEditing').isSourceEditingMode === true;
    }

    /**
     * Table and uid of the edited record, for the record's language; from the
     * Cowriter plugin, which reads it from the FormEngine form.
     *
     * @returns {{table: string, uid: number}|null}
     * @private
     */
    _recordContext() {
        const plugins = this.editor.plugins;
        const context = plugins.has('cowriter') ? plugins.get('cowriter')._getRecordContext?.() : null;
        return context ? { table: context.table, uid: context.uid } : null;
    }

    /**
     * Say something in the polite live region next to the editor; an empty
     * text clears it.
     *
     * @param {string} text
     * @private
     */
    _announce(text) {
        if (this._liveRegion === null) {
            if (text === '') {
                return;
            }
            const parent = this.editor.ui.view?.element ?? this.editor.sourceElement?.parentElement ?? null;
            if (!parent) {
                return;
            }
            const region = parent.ownerDocument.createElement('div');
            region.className = 'visually-hidden cowriter-inline-suggestion-status';
            region.setAttribute('role', 'status');
            region.setAttribute('aria-live', 'polite');
            region.style.position = 'absolute';
            region.style.width = '1px';
            region.style.height = '1px';
            region.style.overflow = 'hidden';
            region.style.clipPath = 'inset(50%)';
            region.style.whiteSpace = 'nowrap';
            parent.appendChild(region);
            this._liveRegion = region;
        }
        this._liveRegion.textContent = text;
    }
}
