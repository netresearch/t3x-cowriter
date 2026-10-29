/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

// vim: ts=4 sw=4 expandtab colorcolumn=120
// @ts-check

import { Plugin } from "@ckeditor/ckeditor5-core";
import { ButtonView, createDropdown, addListToDropdown, ViewModel } from "@ckeditor/ckeditor5-ui";
import { Collection } from "@ckeditor/ckeditor5-utils";
import { AIService } from "@netresearch/t3_cowriter/AIService";
import { CowriterDialog } from "@netresearch/t3_cowriter/CowriterDialog";
import { ImageDialog } from "@netresearch/t3_cowriter/ImageDialog";
import { InlineSuggestions } from "@netresearch/t3_cowriter/InlineSuggestions";
import { t } from "@netresearch/t3_cowriter/Labels";
import { imageSourcesOf, sanitizeHtml } from "@netresearch/t3_cowriter/HtmlSanitizer";
import Notification from "@typo3/backend/notification.js";

/**
 * Target languages for the translation dropdown when the backend injected
 * none: the record belongs to no site, or the RTE was rendered outside
 * FormEngine. The label is the English fallback of the
 * "ckeditor.language.<code>" label.
 * @type {ReadonlyArray<{code: string, label: string}>}
 */
const TRANSLATION_LANGUAGES = [
    { code: 'de', label: 'German' },
    { code: 'en', label: 'English' },
    { code: 'fr', label: 'French' },
    { code: 'es', label: 'Spanish' },
    { code: 'it', label: 'Italian' },
    { code: 'nl', label: 'Dutch' },
    { code: 'pt', label: 'Portuguese' },
    { code: 'pl', label: 'Polish' },
    { code: 'ja', label: 'Japanese' },
    { code: 'zh', label: 'Chinese' },
];

/**
 * Formality values the translation endpoint accepts.
 * @type {ReadonlyArray<string>}
 */
const FORMALITIES = new Set(['default', 'formal', 'informal']);

/**
 * Build a TYPO3 Notification action that opens the LLM module.
 *
 * @param {string} label - Action link text
 * @param {string|null} url - Backend module URL (null = no action)
 * @returns {Array<{label: string, action: {execute: function}}>}
 */
function buildModuleAction(label, url) {
    if (!url) return [];
    return [{ label, action: { execute: () => { window.open(url, '_blank', 'noopener,noreferrer'); } } }];
}

/**
 * Build error notification actions based on the error.
 * Returns appropriate "Open LLM Settings" links depending on the error type.
 *
 * The backend message is in the editor's language, so a rate limit is
 * recognised by the HTTP status (429) as well as by the English wording.
 *
 * @param {string} errorMessage - The error message from the backend
 * @param {AIService} service - The AIService instance (for route access)
 * @param {number|null} [status=null] - HTTP status of the failed request, if known
 * @returns {Array<{label: string, action: {execute: function}}>}
 */
function errorActions(errorMessage, service, status = null) {
    const msg = (errorMessage || '').toLowerCase();
    const llmModuleUrl = service.getModuleUrl?.('llmModule') ?? null;
    const settingsLabel = t('ckeditor.action.openLlmSettings', 'Open LLM Settings');

    if (msg.includes('not configured') || msg.includes('no llm provider') || msg.includes('no default')
        || msg.includes('api key') || msg.includes('unauthorized') || msg.includes('401')) {
        return buildModuleAction(settingsLabel, llmModuleUrl);
    }

    if (status === 429 || msg.includes('rate limit') || msg.includes('429')) {
        return []; // No action needed — just wait
    }

    // Generic errors: offer the LLM module as a starting point
    return buildModuleAction(settingsLabel, llmModuleUrl);
}

/**
 * Cowriter CKEditor plugin.
 *
 * Provides AI-powered text completion within the TYPO3 backend RTE.
 * Uses the nr-llm extension for LLM provider abstraction.
 */
export class Cowriter extends Plugin {
    static pluginName = 'cowriter';

    /**
     * Inline suggestions come with the plugin, so a preset that imports only
     * Cowriter has them too; they stay inert until the administrator switches
     * them on (inlineSuggestions.enable).
     */
    static requires = [InlineSuggestions];

    /**
     * @type {AIService}
     * @private
     */
    _service;

    /**
     * Guards against concurrent LLM requests from double-clicks.
     * @type {boolean}
     * @private
     */
    _isProcessing = false;

    /**
     * Detect available CKEditor formatting capabilities at runtime.
     *
     * @returns {string} Comma-separated list of available formatting features
     * @private
     */
    _getEditorCapabilities() {
        const editor = this.editor;
        const caps = [];

        const pluginMap = [
            ['HeadingEditing', 'headings (h2, h3, h4)'],
            ['BoldEditing', 'bold'],
            ['ItalicEditing', 'italic'],
            ['UnderlineEditing', 'underline'],
            ['StrikethroughEditing', 'strikethrough'],
            ['ListEditing', 'bulleted and numbered lists'],
            ['TableEditing', 'tables with headers and merged cells'],
            ['BlockQuoteEditing', 'blockquotes'],
            ['AlignmentEditing', 'text alignment (left, center, right, justify)'],
            ['LinkEditing', 'hyperlinks'],
            ['HorizontalLineEditing', 'horizontal rules (<hr>)'],
            ['HighlightEditing', 'text highlighting'],
            ['FontColorEditing', 'font colors'],
            ['FontBackgroundColorEditing', 'background colors'],
            ['SubscriptEditing', 'subscript'],
            ['SuperscriptEditing', 'superscript'],
        ];

        for (const [plugin, label] of pluginMap) {
            if (editor.plugins.has(plugin)) {
                caps.push(label);
            }
        }

        const styleConfig = editor.config.get('style');
        if (styleConfig?.definitions?.length) {
            const styleNames = styleConfig.definitions.map((d) => d.name);
            caps.push(`styles: ${styleNames.join(', ')}`);
        }

        return caps.join(', ');
    }

    /**
     * Extract record context (table, uid, field) from the CKEditor source element's DOM.
     *
     * TYPO3 FormEngine names textareas as: data[table][uid][field]
     * e.g. data[tt_content][123][bodytext]
     *
     * @returns {{table: string, uid: number, field: string}|null}
     * @private
     */
    _getRecordContext() {
        const pattern = /^data\[(\w+)]\[(\d+)]\[(\w+)]$/;
        const urlPattern = /^edit\[(\w+)]\[(\d+)]$/;

        // Strategy 1: CKEditor 5 sourceElement (textarea itself or a wrapper containing one)
        const el = this.editor.sourceElement;
        if (el) {
            const textarea = el.tagName === 'TEXTAREA' ? el : el.querySelector('textarea[name^="data["]');
            if (textarea?.name) {
                const match = textarea.name.match(pattern);
                if (match) return { table: match[1], uid: parseInt(match[2], 10), field: match[3] };
            }
        }

        // Strategy 2: Walk up from CKEditor's editable DOM root to find FormEngine textarea
        // In TYPO3 v13/v14, the textarea may be a sibling/ancestor of the editable area,
        // not a child of sourceElement (especially with web component wrappers)
        const editableRoot = this.editor.editing?.view?.getDomRoot();
        if (editableRoot) {
            let parent = editableRoot.parentElement;
            while (parent && parent !== document.body) {
                const ta = parent.querySelector('textarea[name^="data["]');
                if (ta?.name) {
                    const match = ta.name.match(pattern);
                    if (match) return { table: match[1], uid: parseInt(match[2], 10), field: match[3] };
                }
                parent = parent.parentElement;
            }
        }

        // Strategy 3: Search all textareas in the document (fallback for unusual DOM layouts)
        for (const ta of document.querySelectorAll('textarea[name^="data["]')) {
            const match = ta.name.match(pattern);
            if (match) return { table: match[1], uid: parseInt(match[2], 10), field: match[3] };
        }

        // Strategy 4: Parse TYPO3 edit URL (e.g. ?edit[tt_content][123]=edit).
        // Only "=edit" names a record: for "=new" the number is the target page.
        try {
            const searchStr = window.location.search
                || window.parent?.location?.search || '';
            const params = new URLSearchParams(searchStr);
            for (const [key, value] of params) {
                const urlMatch = key.match(urlPattern);
                if (urlMatch && value === 'edit') {
                    return {
                        table: urlMatch[1],
                        uid: parseInt(urlMatch[2], 10),
                        field: 'bodytext',
                    };
                }
            }
        } catch {
            // URL parsing or cross-origin access failed
        }

        return null;
    }

    /**
     * The translate menu's languages: those of the edited record's site, which
     * InjectTranslationLanguagesListener puts into the editor configuration as
     * `cowriter.translationLanguages`, or the fixed list when there are none.
     *
     * @returns {Array<{code: string, label: string, formality: string}>}
     * @private
     */
    _getTranslationLanguages() {
        const configured = this.editor.config.get('cowriter')?.translationLanguages;
        if (Array.isArray(configured)) {
            const languages = configured
                .filter((lang) => typeof lang?.code === 'string' && lang.code !== '')
                .map((lang) => ({
                    code: lang.code,
                    label: typeof lang.label === 'string' && lang.label !== '' ? lang.label : lang.code,
                    formality: FORMALITIES.has(lang.formality) ? lang.formality : 'default',
                }));
            if (languages.length > 0) {
                return languages;
            }
        }

        return TRANSLATION_LANGUAGES.map((lang) => ({
            code: lang.code,
            label: t(`ckeditor.language.${lang.code}`, lang.label),
            formality: 'default',
        }));
    }

    /**
     * Whether a generated image can be inserted so that it survives saving:
     * only as the `typo3image` element of the TYPO3 image plugin
     * (netresearch/rte-ckeditor-image), which keeps the FAL reference
     * (data-htmlarea-file-uid). TYPO3's CKEditor loads no image plugin of its
     * own, and its RTE processing (Processing.yaml, `denyTags: img`) removes a
     * plain <img> on save.
     *
     * @returns {boolean}
     * @private
     */
    _canInsertImage() {
        return this.editor.model?.schema?.isRegistered?.('typo3image') === true;
    }

    /**
     * Insert a generated and stored image at the selection as the
     * `typo3image` element, built as the TYPO3 image plugin builds a newly
     * inserted image.
     *
     * @param {{image: {fileUid: number, fileTable: string, url: string, width: number, height: number}, alt: string}} result
     * @param {object|null} [insertionPoint] marker from _rememberInsertionPoint(); without one, the selection
     * @private
     */
    _insertGeneratedImage(result, insertionPoint = null) {
        const model = this.editor.model;
        const { image } = result;

        model.change((writer) => {
            const element = writer.createElement('typo3image', {
                fileUid: String(image.fileUid),
                fileTable: image.fileTable || 'sys_file',
                src: image.url,
                width: String(image.width),
                height: String(image.height),
                class: 'image-block',
                title: '',
                alt: typeof result.alt === 'string' ? result.alt : '',
                enableZoom: false,
                noScale: false,
                quality: '',
            });
            // Where the cursor was when the editor asked for the image. The
            // marker moves with edits made while the image was generated; if
            // its text was deleted, the current selection is used instead.
            const position = insertionPoint?.getStart?.();
            if (position && position.root?.rootName !== '$graveyard') {
                model.insertObject(element, position);
            } else {
                model.insertObject(element);
            }
        });
    }

    /**
     * Remember the cursor position for an image that is inserted later, as
     * a marker, which moves along with edits made in the meantime.
     *
     * @returns {object|null} the marker, null without a selection
     * @private
     */
    _rememberInsertionPoint() {
        const model = this.editor.model;
        const position = model.document.selection.getFirstPosition();
        if (!position) {
            return null;
        }

        let marker = null;
        model.change((writer) => {
            marker = writer.addMarker(`cowriter:image:${Date.now()}:${Math.random().toString(36).slice(2)}`, {
                range: model.createRange(position),
                usingOperation: false,
                affectsData: false,
            });
        });
        return marker;
    }

    /**
     * @param {object|null} marker
     * @private
     */
    _forgetInsertionPoint(marker) {
        const model = this.editor.model;
        if (marker && this.editor.state !== 'destroyed' && model.markers.has(marker)) {
            model.change((writer) => writer.removeMarker(marker));
        }
    }

    async init() {
        // Initialize the AIService (now uses TYPO3 AJAX backend)
        this._service = new AIService();

        const editor = this.editor,
            model = editor.model;

        // Button to add text at current text cursor position:
        editor.ui.componentFactory.add(Cowriter.pluginName, () => {
            const button = new ButtonView();

            button.set({
                label: t('ckeditor.button.complete', 'Cowriter - AI text completion'),
                tooltip: true,
                icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><path d="M19,10H7V6h2c1.654,0,3-1.346,3-3s-1.346-3-3-3H3C1.346,0,0,1.346,0,3s1.346,3,3,3h2v4c-2.757,0-5,2.243-5,5v3H24v-3c0-2.757-2.243-5-5-5ZM6,15c-.552,0-1-.448-1-1s.448-1,1-1,1,.448,1,1-.448,1-1,1Zm4,0c-.552,0-1-.448-1-1s.448-1,1-1,1,.448,1,1-.448,1-1,1Zm4,0c-.552,0-1-.448-1-1s.448-1,1-1,1,.448,1,1-.448,1-1,1Zm4,0c-.552,0-1-.448-1-1s.448-1,1-1,1,.448,1,1-.448,1-1,1ZM.101,20H23.899c-.465,2.279-2.485,4-4.899,4H5c-2.414,0-4.435-1.721-4.899-4Z"/></svg>'
            });

            button.on('execute', async () => {
                if (this._isProcessing) return;
                this._isProcessing = true;

                try {
                    const selection = editor.model.document.selection;
                    const selectedRange = selection.getFirstRange();

                    // Get the selected content as HTML via CKEditor's data pipeline
                    let selectedText = '';
                    if (selectedRange && !selectedRange.isCollapsed) {
                        const content = model.getSelectedContent(selection);
                        selectedText = editor.data.stringify(content);
                    }

                    const fullContent = editor.getData();
                    const caps = this._getEditorCapabilities();
                    const recordContext = this._getRecordContext();

                    // Show the task dialog
                    // Once the dialog is closed, focus goes back into the editing
                    // view, with its selection, as CKEditor's own dialogs do.
                    const dialog = new CowriterDialog(this._service, {
                        returnFocus: () => editor.editing.view.focus(),
                    });
                    const result = await dialog.show(selectedText || '', fullContent, caps, recordContext);

                    if (result?.content) {
                        // Use CKEditor's HTML processing pipeline (handles schema/sanitization)
                        const viewFragment = editor.data.processor.toView(result.content);
                        const modelFragment = editor.data.toModel(viewFragment);

                        model.change((writer) => {
                            if (selectedRange && !selectedRange.isCollapsed) {
                                // Replace the selected range with the LLM output
                                writer.setSelection(selectedRange);
                            }
                            editor.model.insertContent(modelFragment);
                        });
                    }
                } catch (error) {
                    // User cancelled the dialog — no action needed.
                    // Log unexpected errors for debugging (cancellations have message 'User cancelled').
                    if (error?.message && error.message !== 'User cancelled') {
                        console.error('[Cowriter]', error);
                    }
                } finally {
                    this._isProcessing = false;
                }
            });

            return button;
        });

        // Vision button — analyze image and generate alt text
        editor.ui.componentFactory.add('cowriterVision', () => {
            const button = new ButtonView();

            button.set({
                label: t('ckeditor.button.vision', 'Cowriter - Generate alt text'),
                tooltip: true,
                icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zm-5-7l-3 3.72L9 13l-3 4h12l-4-5z"/></svg>',
            });

            button.on('execute', async () => {
                if (this._isProcessing) return;
                this._isProcessing = true;

                try {
                    // Detect selected image in the editor
                    const selectedElement = editor.model.document.selection.getSelectedElement();
                    let imageUrl = '';

                    if (selectedElement && (selectedElement.is('element', 'imageBlock') || selectedElement.is('element', 'imageInline'))) {
                        imageUrl = selectedElement.getAttribute('src') || '';
                    }

                    if (!imageUrl) {
                        // Fallback: check editing view for selected image
                        const viewSelection = editor.editing.view.document.selection;
                        const viewElement = viewSelection.getSelectedElement();
                        if (viewElement) {
                            const img = viewElement.is('element', 'img')
                                ? viewElement
                                : viewElement.getChild?.(0)?.is?.('element', 'img') ? viewElement.getChild(0) : null;
                            if (img) {
                                imageUrl = img.getAttribute('src') || '';
                            }
                        }
                    }

                    if (!imageUrl) {
                        Notification.warning(
                            t('ckeditor.vision.noImage.title', 'No image selected'),
                            t('ckeditor.vision.noImage.message', 'Click on an image in the editor first.'),
                            5,
                        );
                        return;
                    }

                    Notification.info(
                        t('ckeditor.vision.analyzing.title', 'Analyzing image...'),
                        t('ckeditor.vision.analyzing.message', 'Generating alt text'),
                        15,
                    );

                    // The record context lets the server write the alt text in the record's language.
                    const result = await this._service.analyzeImage(imageUrl, undefined, this._getRecordContext());
                    if (result?.success && result.altText) {
                        editor.model.change(writer => {
                            // If the selected element is an image, set its alt attribute
                            if (selectedElement && (selectedElement.is('element', 'imageBlock') || selectedElement.is('element', 'imageInline'))) {
                                writer.setAttribute('alt', result.altText, selectedElement);
                            } else {
                                writer.insertText(result.altText, editor.model.document.selection.getFirstPosition());
                            }
                        });
                        Notification.success(t('ckeditor.vision.done', 'Alt text generated'), result.altText.substring(0, 80), 3);
                    } else {
                        const msg = result?.error || t('ckeditor.unknownError', 'Unknown error');
                        Notification.error(t('ckeditor.vision.failed', 'Alt text generation failed'), msg, 0, errorActions(msg, this._service));
                    }
                } catch (error) {
                    const msg = error?.message || t('ckeditor.unknownError', 'Unknown error');
                    Notification.error(
                        t('ckeditor.vision.failed', 'Alt text generation failed'),
                        msg,
                        0,
                        errorActions(msg, this._service, error?.status ?? null),
                    );
                    console.error('[Cowriter Vision]', error);
                } finally {
                    this._isProcessing = false;
                }
            });

            return button;
        });

        // Image button — generate an image from a description and insert it.
        // Registered in every case; RemoveDisabledImageButtonListener takes it
        // out of the toolbar while the setting image.enable is off. Without the
        // TYPO3 image plugin the button is disabled and says why: an image
        // could not be kept, so none may be paid for.
        editor.ui.componentFactory.add('cowriterImage', () => {
            const button = new ButtonView();
            const insertable = this._canInsertImage();

            button.set({
                label: insertable
                    ? t('ckeditor.button.image', 'Cowriter - Generate image')
                    : t('ckeditor.image.unavailable', 'Cowriter - Generate image: needs the image plugin of rte-ckeditor-image'),
                isEnabled: insertable,
                tooltip: true,
                icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h9v-2H5V5h14v7h2V5c0-1.1-.9-2-2-2zm-5 8-3 3.72L9 12.5 6 16.5h9l-1-1.33V11zm6 3-1 2.5-2.5 1 2.5 1 1 2.5 1-2.5 2.5-1-2.5-1L20 14z"/></svg>',
            });

            button.on('execute', async () => {
                if (this._isProcessing || !this._canInsertImage()) return;
                this._isProcessing = true;
                const insertionPoint = this._rememberInsertionPoint();

                try {
                    const dialog = new ImageDialog(this._service, {
                        returnFocus: () => editor.editing.view.focus(),
                    });
                    // Also resolves when the editor closed the dialog while the
                    // image was generated: the image is paid for and stored.
                    const result = await dialog.show();
                    const file = result.image?.file || '';

                    if (editor.state === 'destroyed') {
                        Notification.warning(
                            t('ckeditor.image.stored.title', 'Image stored'),
                            t('ckeditor.image.stored.message', 'Stored as %s. The editor was closed before the image was ready, so it was not inserted.', file),
                            0,
                        );
                        return;
                    }

                    this._insertGeneratedImage(result, insertionPoint);
                    Notification.success(
                        t('ckeditor.image.done.title', 'Image inserted'),
                        t('ckeditor.image.done.message', 'Stored as %s. Check the alternative text: it is your description, not a description of the generated image.', file),
                        8,
                    );
                } catch (error) {
                    if (error?.message && error.message !== 'User cancelled') {
                        const msg = error.message || t('ckeditor.unknownError', 'Unknown error');
                        Notification.error(
                            t('ckeditor.image.failed', 'Image generation failed'),
                            msg,
                            0,
                            errorActions(msg, this._service, error?.status ?? null),
                        );
                        console.error('[Cowriter Image]', error);
                    }
                } finally {
                    this._forgetInsertionPoint(insertionPoint);
                    this._isProcessing = false;
                }
            });

            return button;
        });

        // Translation dropdown — translate selected text
        editor.ui.componentFactory.add('cowriterTranslate', (locale) => {
            const dropdown = createDropdown(locale);
            const items = new Collection();

            for (const lang of this._getTranslationLanguages()) {
                const itemModel = new ViewModel({
                    label: lang.label,
                    languageCode: lang.code,
                    formality: lang.formality,
                    withText: true,
                });
                items.add({ type: 'button', model: itemModel });
            }

            addListToDropdown(dropdown, items);

            dropdown.buttonView.set({
                label: t('ckeditor.button.translate', 'Cowriter - Translate'),
                tooltip: true,
                withText: false,
                icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M12.87 15.07l-2.54-2.51.03-.03A17.52 17.52 0 0014.07 6H17V4h-7V2H8v2H1v2h11.17C11.5 7.92 10.44 9.75 9 11.35 8.07 10.32 7.3 9.19 6.69 8h-2c.73 1.63 1.73 3.17 2.98 4.56l-5.09 5.02L4 19l5-5 3.11 3.11.76-2.04zM18.5 10h-2L12 22h2l1.12-3h4.75L21 22h2l-4.5-12zm-2.62 7l1.62-4.33L19.12 17h-3.24z"/></svg>',
            });

            dropdown.on('execute', async (event) => {
                if (this._isProcessing) return;
                this._isProcessing = true;

                try {
                    const langCode = event.source.languageCode;
                    const langLabel = event.source.label || langCode;
                    const formality = event.source.formality || 'default';
                    const selection = editor.model.document.selection;
                    const selectedRange = selection.getFirstRange();

                    let selectedText = '';
                    if (selectedRange && !selectedRange.isCollapsed) {
                        const content = model.getSelectedContent(selection);
                        selectedText = editor.data.stringify(content);
                    }

                    if (!selectedText) {
                        Notification.warning(
                            t('ckeditor.translate.noText.title', 'No text selected'),
                            t('ckeditor.translate.noText.message', 'Please select text in the editor before translating.'),
                            5,
                        );
                        return;
                    }

                    Notification.info(
                        t('ckeditor.translate.running.title', 'Translating...'),
                        t('ckeditor.translate.running.message', 'Translating to %s', langLabel),
                        15,
                    );

                    const result = await this._service.translate(selectedText, langCode, { formality });
                    if (result?.success && result.translation) {
                        // The same allow-list as the dialog's result; images from
                        // another origin stay only when the selection held them.
                        const safeTranslation = sanitizeHtml(result.translation, imageSourcesOf(selectedText)).innerHTML;
                        const viewFragment = editor.data.processor.toView(safeTranslation);
                        const modelFragment = editor.data.toModel(viewFragment);

                        model.change((writer) => {
                            writer.setSelection(selectedRange);
                            editor.model.insertContent(modelFragment);
                        });

                        // A language model revised the translator's text (the
                        // admin setting translation.polish); say so. Nothing is
                        // added when the revision was not applied.
                        Notification.success(
                            t('ckeditor.translate.done.title', 'Translation complete'),
                            result.polished === true
                                ? t('ckeditor.translate.done.polishedMessage', 'Translated to %s and revised by AI', langLabel)
                                : t('ckeditor.translate.done.message', 'Translated to %s', langLabel),
                            3,
                        );
                    } else {
                        const msg = result?.error || t('ckeditor.unknownError', 'Unknown error');
                        Notification.error(t('ckeditor.translate.failed', 'Translation failed'), msg, 0, errorActions(msg, this._service));
                    }
                } catch (error) {
                    const msg = error?.message || t('ckeditor.unknownError', 'Unknown error');
                    Notification.error(
                        t('ckeditor.translate.failed', 'Translation failed'),
                        msg,
                        0,
                        errorActions(msg, this._service, error?.status ?? null),
                    );
                    console.error('[Cowriter Translate]', error);
                } finally {
                    this._isProcessing = false;
                }
            });

            return dropdown;
        });

        // Tasks dropdown — open dialog with a pre-selected task
        editor.ui.componentFactory.add('cowriterTemplates', (locale) => {
            const dropdown = createDropdown(locale);
            let tasksLoaded = false;
            let tasksLoading = false;

            dropdown.buttonView.set({
                label: t('ckeditor.button.tasks', 'Cowriter - Tasks'),
                tooltip: true,
                withText: false,
                icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zM6 20V4h5v5h5v11H6z"/></svg>',
            });

            // Load tasks when dropdown opens (once per session, guarded against concurrent fetches)
            dropdown.on('change:isOpen', async () => {
                if (!dropdown.isOpen || tasksLoaded || tasksLoading) return;
                tasksLoading = true;

                try {
                    const result = await this._service.getTasks();
                    if (!result?.success || !result.tasks?.length) {
                        Notification.info(
                            t('ckeditor.tasks.none.title', 'No tasks configured'),
                            t('ckeditor.tasks.none.message', 'Create tasks in the LLM module first.'),
                            5,
                        );
                        return;
                    }

                    const items = new Collection();
                    for (const task of result.tasks) {
                        const itemModel = new ViewModel({
                            label: task.name,
                            taskUid: task.uid,
                            withText: true,
                        });
                        items.add({ type: 'button', model: itemModel });
                    }

                    addListToDropdown(dropdown, items);
                    tasksLoaded = true;
                } catch (error) {
                    const msg = error?.message || t('ckeditor.unknownError', 'Unknown error');
                    Notification.error(
                        t('ckeditor.tasks.loadFailed', 'Failed to load tasks'),
                        msg,
                        0,
                        errorActions(msg, this._service, error?.status ?? null),
                    );
                    console.error('[Cowriter Tasks]', error);
                } finally {
                    tasksLoading = false;
                }
            });

            dropdown.on('execute', async (event) => {
                if (this._isProcessing) return;
                this._isProcessing = true;

                try {
                    const taskUid = event.source.taskUid;
                    const selection = editor.model.document.selection;
                    const selectedRange = selection.getFirstRange();

                    let selectedText = '';
                    if (selectedRange && !selectedRange.isCollapsed) {
                        const content = model.getSelectedContent(selection);
                        selectedText = editor.data.stringify(content);
                    }

                    const fullContent = editor.getData();
                    const caps = this._getEditorCapabilities();
                    const recordContext = this._getRecordContext();

                    // Once the dialog is closed, focus goes back into the editing
                    // view, with its selection, as CKEditor's own dialogs do.
                    const dialog = new CowriterDialog(this._service, {
                        returnFocus: () => editor.editing.view.focus(),
                    });
                    const dialogResult = await dialog.show(
                        selectedText || '', fullContent, caps, recordContext, taskUid,
                    );

                    if (dialogResult?.content) {
                        const viewFragment = editor.data.processor.toView(dialogResult.content);
                        const modelFragment = editor.data.toModel(viewFragment);
                        model.change((writer) => {
                            if (selectedRange && !selectedRange.isCollapsed) {
                                writer.setSelection(selectedRange);
                            }
                            editor.model.insertContent(modelFragment);
                        });
                    }
                } catch (error) {
                    if (error?.message && error.message !== 'User cancelled') {
                        const msg = error?.message || t('ckeditor.unknownError', 'Unknown error');
                        Notification.error(
                            t('ckeditor.tasks.failed', 'Task failed'),
                            msg,
                            0,
                            errorActions(msg, this._service, error?.status ?? null),
                        );
                        console.error('[Cowriter Tasks]', error);
                    }
                } finally {
                    this._isProcessing = false;
                }
            });

            return dropdown;
        });
    }
}
