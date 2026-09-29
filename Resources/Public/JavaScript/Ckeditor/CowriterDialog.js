/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

// vim: ts=4 sw=4 expandtab colorcolumn=120
// @ts-check

/**
 * CowriterDialog - Task-based dialog for AI text operations.
 *
 * Shows a TYPO3 Backend Modal where users can select a task,
 * choose context scope, add instructions, preview results,
 * and insert the generated content into the editor.
 *
 * @module CowriterDialog
 */

import Modal from '@typo3/backend/modal.js';
import { t } from '@netresearch/t3_cowriter/Labels';

/**
 * @typedef {object} DialogResult
 * @property {string} content - The generated content to insert
 */

/**
 * @typedef {object} TaskItem
 * @property {number} uid
 * @property {string} identifier
 * @property {string} name
 * @property {string} description
 * @property {string} [promptTemplate]
 * @property {{identifier: string, name: string}|null} [configuration] - The task's own configuration, if it has one
 */

/**
 * @typedef {object} ConfigurationItem
 * @property {string} identifier
 * @property {string} name
 * @property {boolean} isDefault
 */

/**
 * @typedef {object} SavedPromptItem
 * @property {number} uid
 * @property {string} title
 * @property {string} instruction
 * @property {boolean} own - The editor saved it
 * @property {boolean} shared
 * @property {boolean} awaitingApproval - Shared, but not yet visible to other editors
 */

/**
 * Context scope identifiers used for backend API calls.
 * @type {readonly string[]}
 */
const SCOPE_IDS = ['selection', 'text', 'element', 'page', 'ancestors_1', 'ancestors_2'];

/**
 * English fallbacks of the scope labels ("ckeditor.dialog.scope.<id>").
 * @type {readonly string[]}
 */
const SCOPE_LABELS = ['Selection', 'Full content', 'Content element', 'Page content', 'Parent page', 'Grandparent page'];

let formIdCounter = 0;
/** Id of the style element, so that a document gets it only once */
const STYLE_ELEMENT_ID = 'cowriter-dialog-styles';

/** The dialog styles */
const DIALOG_CSS = `
.cowriter-result {
    min-height: 200px;
    max-height: 400px;
    overflow-y: auto;
    border: 1px solid var(--typo3-component-border-color);
    border-radius: var(--typo3-component-border-radius);
    padding: var(--typo3-modal-padding, 1rem);
}
.cowriter-result--empty {
    color: var(--typo3-text-color-secondary);
    font-style: italic;
}
@media (forced-colors: active) {
    .cowriter-dialog [role="option"].active {
        forced-color-adjust: none;
        background: Highlight;
        color: HighlightText;
        outline: 2px solid Highlight;
    }
    .cowriter-dialog [role="option"].active * {
        color: HighlightText;
    }
}`;

/**
 * Inject the dialog styles once into the head of the document that shows
 * the dialog. From the FormEngine iframe, the TYPO3 modal opens in the
 * parent window, so this is not necessarily the script's own document. That
 * document outlives this module, which loads again with every iframe, so the
 * check reads the document rather than module state. An element left by an
 * older version of this module gets the current styles.
 *
 * @param {Document} doc
 * @private
 */
function injectStyles(doc) {
    const existing = doc.getElementById(STYLE_ELEMENT_ID);
    if (existing) {
        if (existing.textContent !== DIALOG_CSS) {
            existing.textContent = DIALOG_CSS;
        }
        return;
    }
    const style = doc.createElement('style');
    style.id = STYLE_ELEMENT_ID;
    style.textContent = DIALOG_CSS;
    doc.head.appendChild(style);
}

/**
 * Create a TYPO3 icon element in the given document.
 *
 * `typo3-backend-icon` is a Lit element. An element upgraded by one window's
 * registry adopts that window's constructed stylesheets when it connects, and
 * a browser refuses them in another document (NotAllowedError). The icon must
 * therefore be created by the document it will be shown in.
 *
 * @param {Document} doc
 * @param {string} identifier
 * @returns {HTMLElement}
 * @private
 */
function createIcon(doc, identifier) {
    const icon = doc.createElement('typo3-backend-icon');
    icon.setAttribute('identifier', identifier);
    icon.setAttribute('size', 'small');
    return icon;
}

export class CowriterDialog {
    /** @type {import('./AIService.js').AIService} */
    _service;

    /** @type {HTMLElement|null} Focus target once the modal is hidden */
    _returnFocusTo = null;

    /** @type {(() => void)|null} Hands focus back instead of _returnFocusTo */
    _returnFocus = null;

    /**
     * @param {import('./AIService.js').AIService} service
     * @param {{returnFocus?: () => void}} [options] - returnFocus is called once
     *     the modal is hidden, in place of focusing what had focus before; the
     *     CKEditor plugin passes one that focuses the editing view.
     */
    constructor(service, options = {}) {
        this._service = service;
        this._returnFocus = options.returnFocus ?? null;
    }

    /**
     * Show the cowriter task dialog.
     *
     * @param {string} selectedText - Currently selected text in the editor
     * @param {string} fullContent - Full content of the editor
     * @param {string} [editorCapabilities=''] - Available editor formatting features
     * @param {{table: string, uid: number, field: string}|null} [recordContext=null] - Record context for DB lookups
     * @param {number|null} [preSelectedTaskUid=null] - Task UID to pre-select in the dropdown
     * @returns {Promise<DialogResult>} Resolves with content to insert, rejects on cancel
     */
    async show(selectedText, fullContent, editorCapabilities = '', recordContext = null, preSelectedTaskUid = null) {
        // What had focus when the dialog was asked for: the editor or the
        // toolbar button. Opened from the FormEngine iframe, the modal lives in
        // the parent window and cannot hand focus back into this document.
        this._returnFocusTo = /** @type {HTMLElement|null} */ (document.activeElement);
        // The configuration picker is optional and does not hold up the
        // dialog: it appears once the list arrives. Without a list, every task
        // runs on its own configuration, as before.
        const configurationsRequest = this._loadConfigurations();
        const styleRequest = this._loadStyleOptions();
        const promptsRequest = this._loadSavedPrompts();
        let tasks;
        try {
            const response = await this._service.getTasks();
            tasks = response.tasks || [];
        } catch (error) {
            throw new Error(
                t('ckeditor.tasks.loadFailedWithReason', 'Failed to load tasks: %s', error.message),
                { cause: error },
            );
        }

        if (tasks.length === 0) {
            return this._showNoTasksModal();
        }

        return this._showModal(
            tasks, selectedText, fullContent, editorCapabilities, recordContext, preSelectedTaskUid,
            configurationsRequest, styleRequest, promptsRequest,
        );
    }

    /**
     * The audience and tone snippets the editor may choose from; empty lists
     * when they are unavailable.
     *
     * @returns {Promise<{audiences: Array<{uid: number, name: string}>, tones: Array<{uid: number, name: string}>}>}
     * @private
     */
    async _loadStyleOptions() {
        const none = { audiences: [], tones: [] };
        if (typeof this._service.getStyleOptions !== 'function') {
            return none;
        }
        try {
            const response = await this._service.getStyleOptions();
            return {
                audiences: Array.isArray(response?.audiences) ? response.audiences : [],
                tones: Array.isArray(response?.tones) ? response.tones : [],
            };
        } catch {
            return none;
        }
    }

    /**
     * The configurations the editor may choose from; empty when the list is
     * unavailable.
     *
     * @returns {Promise<ConfigurationItem[]>}
     * @private
     */
    async _loadConfigurations() {
        if (typeof this._service.getConfigurations !== 'function') {
            return [];
        }
        try {
            const response = await this._service.getConfigurations();
            return Array.isArray(response?.configurations) ? response.configurations : [];
        } catch {
            return [];
        }
    }

    /**
     * The saved prompts the editor sees; empty when the list is unavailable.
     *
     * @returns {Promise<SavedPromptItem[]>}
     * @private
     */
    async _loadSavedPrompts() {
        if (typeof this._service.getSavedPrompts !== 'function') {
            return [];
        }
        try {
            const response = await this._service.getSavedPrompts();
            return Array.isArray(response?.prompts) ? response.prompts : [];
        } catch {
            return [];
        }
    }

    /**
     * The style row: audience and tone (hidden until the operator's snippets
     * arrive) and the length step, which needs no data and is always there.
     *
     * @param {string} idPrefix
     * @returns {HTMLElement}
     * @private
     */
    _buildStyleRow(idPrefix) {
        const row = document.createElement('div');
        row.className = 'row mb-3';
        row.dataset.role = 'style-row';

        for (const [role, label, fallback] of [
            ['audience', 'ckeditor.dialog.audience', 'Audience'],
            ['tone', 'ckeditor.dialog.tone', 'Tone of voice'],
        ]) {
            const id = `${idPrefix}-${role}`;
            const group = this._createFormGroup(t(label, fallback), id);
            const select = document.createElement('select');
            select.className = 'form-select';
            select.id = id;
            select.dataset.role = `${role}-select`;
            const none = document.createElement('option');
            none.value = '0';
            none.textContent = t('ckeditor.dialog.styleDefault', 'No preference');
            select.appendChild(none);
            group.appendChild(select);

            const col = document.createElement('div');
            col.className = 'col-md-3';
            col.dataset.role = `${role}-col`;
            col.hidden = true;
            col.appendChild(group);
            row.appendChild(col);
        }

        const lengthId = `${idPrefix}-length`;
        const lengthGroup = this._createFormGroup(t('ckeditor.dialog.length', 'Length'), lengthId);
        const lengthSelect = document.createElement('select');
        lengthSelect.className = 'form-select';
        lengthSelect.id = lengthId;
        lengthSelect.dataset.role = 'length-select';
        for (const [value, key, fallback] of [
            ['-2', 'ckeditor.dialog.length.muchShorter', 'Much shorter'],
            ['-1', 'ckeditor.dialog.length.shorter', 'Shorter'],
            ['0', 'ckeditor.dialog.length.unchanged', 'Unchanged'],
            ['1', 'ckeditor.dialog.length.longer', 'Longer'],
            ['2', 'ckeditor.dialog.length.muchLonger', 'Much longer'],
        ]) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = t(key, fallback);
            lengthSelect.appendChild(option);
        }
        lengthSelect.value = '0';
        lengthGroup.appendChild(lengthSelect);
        const lengthCol = document.createElement('div');
        lengthCol.className = 'col-md-3';
        lengthCol.appendChild(lengthGroup);
        row.appendChild(lengthCol);

        const variantsId = `${idPrefix}-variants`;
        const variantsGroup = this._createFormGroup(t('ckeditor.dialog.variants', 'Versions'), variantsId);
        const variantsSelect = document.createElement('select');
        variantsSelect.className = 'form-select';
        variantsSelect.id = variantsId;
        variantsSelect.dataset.role = 'variants-select';
        for (const count of ['1', '2', '3']) {
            const option = document.createElement('option');
            option.value = count;
            option.textContent = count;
            variantsSelect.appendChild(option);
        }
        variantsGroup.appendChild(variantsSelect);
        const variantsCol = document.createElement('div');
        variantsCol.className = 'col-md-3';
        variantsCol.appendChild(variantsGroup);
        row.appendChild(variantsCol);

        // Tools: off by default. The model may then look things up with the
        // tools that need no approval; the answer is not streamed and comes
        // as one version.
        const toolsId = `${idPrefix}-tools`;
        const toolsWrap = document.createElement('div');
        toolsWrap.className = 'col-12 form-check ms-2';
        const toolsInput = document.createElement('input');
        toolsInput.type = 'checkbox';
        toolsInput.className = 'form-check-input';
        toolsInput.id = toolsId;
        toolsInput.dataset.role = 'tools-toggle';
        const toolsLabel = document.createElement('label');
        toolsLabel.className = 'form-check-label';
        toolsLabel.htmlFor = toolsId;
        toolsLabel.textContent = t('ckeditor.dialog.tools', 'Let the AI look up content with tools that change nothing');
        toolsInput.addEventListener('change', () => {
            if (toolsInput.checked) {
                variantsSelect.value = '1';
            }
            variantsSelect.disabled = toolsInput.checked;
        });
        toolsWrap.append(toolsInput, toolsLabel);
        row.appendChild(toolsWrap);

        return row;
    }

    /**
     * A radio group choosing which version the preview shows and Insert takes.
     *
     * @param {number} count
     * @param {function(number): void} onSelect - Called with the chosen index
     * @returns {HTMLFieldSetElement}
     * @private
     */
    _buildVariantPicker(count, onSelect) {
        const fieldset = document.createElement('fieldset');
        fieldset.className = 'mb-2';
        fieldset.dataset.role = 'variant-picker';
        const legend = document.createElement('legend');
        legend.className = 'form-label fw-bold fs-6';
        legend.textContent = t('ckeditor.dialog.variants', 'Versions');
        fieldset.appendChild(legend);

        const name = `cowriter-variant-${++formIdCounter}`;
        for (let index = 0; index < count; index++) {
            const wrapper = document.createElement('div');
            wrapper.className = 'form-check form-check-inline';
            const input = document.createElement('input');
            input.type = 'radio';
            input.className = 'form-check-input';
            input.name = name;
            input.id = `${name}-${index}`;
            input.value = String(index);
            input.checked = index === 0;
            input.addEventListener('change', () => onSelect(index));
            const label = document.createElement('label');
            label.className = 'form-check-label';
            label.htmlFor = input.id;
            label.textContent = t('ckeditor.dialog.variant', 'Version %s', index + 1);
            wrapper.append(input, label);
            fieldset.appendChild(wrapper);
        }

        return fieldset;
    }

    /**
     * Fill the audience and tone selects and show each that has options.
     *
     * @param {HTMLElement} container
     * @param {{audiences: Array<{uid: number, name: string}>, tones: Array<{uid: number, name: string}>}} options
     * @private
     */
    _offerStyleOptions(container, options) {
        for (const [role, list] of [['audience', options.audiences], ['tone', options.tones]]) {
            const select = container.querySelector(`[data-role="${role}-select"]`);
            const col = container.querySelector(`[data-role="${role}-col"]`);
            if (!select || !col || list.length === 0) {
                continue;
            }
            for (const item of list) {
                const option = document.createElement('option');
                option.value = String(item.uid);
                option.textContent = item.name;
                select.appendChild(option);
            }
            col.hidden = false;
        }
    }

    /**
     * The style choices as numbers; 0 means "no preference".
     *
     * @param {HTMLElement} container
     * @returns {{audience: number, tone: number, length: number, variants: number}}
     * @private
     */
    _readStyle(container) {
        const read = (role) => parseInt(container.querySelector(`[data-role="${role}-select"]`)?.value ?? '0', 10) || 0;
        return {
            audience: read('audience'), tone: read('tone'), length: read('length'), variants: Math.max(1, read('variants')),
            useTools: container.querySelector('[data-role="tools-toggle"]')?.checked === true,
        };
    }

    /**
     * Whether the answer can be streamed: the service offers it and the page
     * knows the stream route.
     *
     * @returns {boolean}
     * @private
     */
    _canStream() {
        return typeof this._service.executeTaskStream === 'function'
            && Boolean(this._service.getModuleUrl?.('taskStream'));
    }

    /**
     * Run the task as a stream and show the answer in the preview while it is
     * written. The preview is marked busy meanwhile, so a screen reader
     * announces the finished answer once rather than every piece of it.
     *
     * @param {HTMLElement} preview
     * @param {object} request - The executeTask fields
     * @param {AbortSignal} signal
     * @returns {Promise<{success: boolean, content: string, model?: string}>}
     * @private
     */
    async _streamTask(preview, request, signal) {
        let streamed = '';
        preview.setAttribute('aria-busy', 'true');
        try {
            const final = await this._service.executeTaskStream(request, (chunk) => {
                streamed += chunk;
                preview.replaceChildren(...Array.from(this._sanitizeHtml(streamed).childNodes));
            }, signal);

            return { success: true, content: final.content ?? streamed, model: final.model, targetWords: final.targetWords };
        } finally {
            preview.removeAttribute('aria-busy');
        }
    }

    /**
     * Fill the configuration picker and show it. Leaves it hidden when there is
     * nothing to choose from, so the dialog looks as it did without the list.
     *
     * @param {HTMLElement} container
     * @param {ConfigurationItem[]} configurations
     * @private
     */
    _offerConfigurations(container, configurations) {
        const select = container.querySelector('[data-role="configuration-select"]');
        const column = container.querySelector('[data-role="configuration-col"]');
        if (!select || !column || configurations.length === 0) {
            return;
        }

        for (const configuration of configurations) {
            const option = document.createElement('option');
            option.value = configuration.identifier;
            option.textContent = configuration.isDefault
                ? t('ckeditor.dialog.configuration.defaultMarker', '%s (default)', configuration.name)
                : configuration.name;
            select.appendChild(option);
        }

        column.hidden = false;
        container.querySelector('[data-role="task-col"]')?.classList.replace('col-md-8', 'col-md-5');
        container.querySelector('[data-role="scope-col"]')?.classList.replace('col-md-4', 'col-md-3');
    }

    /**
     * Add the saved prompts to the task select: the editor's own prompts in
     * one group, the shared prompts of others in a second. Choosing one fills
     * the instruction and runs it as a custom instruction.
     *
     * @param {HTMLElement} container
     * @param {SavedPromptItem[]} prompts
     * @private
     */
    _offerSavedPrompts(container, prompts) {
        for (const prompt of prompts) {
            this._addPromptOption(container, prompt);
        }
    }

    /**
     * @param {HTMLElement} container
     * @param {SavedPromptItem} prompt
     * @returns {HTMLOptionElement|null}
     * @private
     */
    _addPromptOption(container, prompt) {
        const select = container.querySelector('[data-role="task-select"]');
        if (!select) {
            return null;
        }
        const role = prompt.own ? 'prompts-own' : 'prompts-shared';
        let group = select.querySelector(`optgroup[data-role="${role}"]`);
        if (!group) {
            group = document.createElement('optgroup');
            group.dataset.role = role;
            group.label = prompt.own
                ? t('ckeditor.dialog.prompts.own', 'My prompts')
                : t('ckeditor.dialog.prompts.shared', 'Shared prompts');
            // Own prompts stand before the shared ones.
            const shared = select.querySelector('optgroup[data-role="prompts-shared"]');
            select.insertBefore(group, prompt.own ? shared : null);
        }

        const option = document.createElement('option');
        option.value = `prompt-${prompt.uid}`;
        option.textContent = prompt.awaitingApproval
            ? t('ckeditor.dialog.prompts.awaitingApproval', '%s (awaiting approval)', prompt.title)
            : prompt.title;
        option.dataset.promptUid = String(prompt.uid);
        option.dataset.promptOwn = prompt.own ? '1' : '';
        option.dataset.instruction = prompt.instruction;
        option.dataset.description = '';
        option.dataset.configurationName = '';
        group.appendChild(option);

        return option;
    }

    /**
     * Save the instruction as a prompt of the editor and select it.
     *
     * @param {HTMLElement} container
     * @private
     */
    async _savePrompt(container) {
        const titleInput = container.querySelector('[data-role="prompt-title"]');
        const shareInput = container.querySelector('[data-role="prompt-share"]');
        const instruction = container.querySelector('[data-role="instruction"]').value.trim();
        const title = titleInput.value.trim();
        if (title === '' || instruction === '') {
            titleInput.focus();
            this._promptStatus(container, t('ckeditor.dialog.prompts.failed', 'The prompt could not be saved or deleted.'));
            return;
        }

        const button = container.querySelector('[data-role="prompt-save"]');
        if (!this._beginPending(button)) {
            return;
        }
        try {
            const response = await this._service.savePrompt({ title, instruction, shared: shareInput.checked });
            const option = this._addPromptOption(container, response.prompt);
            const select = container.querySelector('[data-role="task-select"]');
            select.value = option.value;
            select.dispatchEvent(new Event('change'));
            titleInput.value = '';
            shareInput.checked = false;
            this._promptStatus(container, response.prompt.awaitingApproval
                ? t('ckeditor.dialog.prompts.savedForApproval', 'Prompt saved. Other editors see it once an administrator approves it.')
                : t('ckeditor.dialog.prompts.saved', 'Prompt saved.'));
        } catch (error) {
            this._promptStatus(container, error.message || t('ckeditor.dialog.prompts.failed', 'The prompt could not be saved or deleted.'));
        } finally {
            this._endPending(button);
        }
    }

    /**
     * Delete the selected own prompt and fall back to the custom instruction.
     *
     * @param {HTMLElement} container
     * @private
     */
    async _deletePrompt(container) {
        const select = container.querySelector('[data-role="task-select"]');
        const option = select.options[select.selectedIndex];
        const uid = parseInt(option?.dataset.promptUid || '', 10);
        if (!uid || option.dataset.promptOwn !== '1') {
            return;
        }

        const button = container.querySelector('[data-role="prompt-delete"]');
        if (!this._beginPending(button)) {
            return;
        }
        try {
            await this._service.deletePrompt(uid);
            const group = option.parentElement;
            option.remove();
            if (group?.tagName === 'OPTGROUP' && group.children.length === 0) {
                group.remove();
            }
            select.value = '0';
            select.dispatchEvent(new Event('change'));
            this._promptStatus(container, t('ckeditor.dialog.prompts.deleted', 'Prompt deleted.'));
        } catch (error) {
            this._promptStatus(container, error.message || t('ckeditor.dialog.prompts.failed', 'The prompt could not be saved or deleted.'));
        } finally {
            this._endPending(button);
        }
    }

    /**
     * Mark a button busy for the length of one request, so a second click sends
     * nothing. aria-disabled keeps the button focusable for keyboard users,
     * where the disabled attribute would drop the focus.
     *
     * @param {HTMLElement|null} button
     * @returns {boolean} false when a request of this button is still running
     * @private
     */
    _beginPending(button) {
        if (button?.getAttribute('aria-disabled') === 'true') {
            return false;
        }
        button?.setAttribute('aria-disabled', 'true');
        button?.classList.add('disabled');

        return true;
    }

    /**
     * @param {HTMLElement|null} button
     * @private
     */
    _endPending(button) {
        button?.removeAttribute('aria-disabled');
        button?.classList.remove('disabled');
    }

    /**
     * @param {HTMLElement} container
     * @param {string} message
     * @private
     */
    _promptStatus(container, message) {
        const status = container.querySelector('[data-role="prompt-status"]');
        if (status) {
            status.textContent = message;
        }
    }

    /**
     * "Save as prompt": a disclosure below the instruction with a title, the
     * share switch and the save button. Hidden when the server offers no
     * prompt storage.
     *
     * @param {string} idPrefix
     * @returns {HTMLElement}
     * @private
     */
    _buildPromptSaver(idPrefix) {
        const details = document.createElement('details');
        details.className = 'mt-2';
        details.dataset.role = 'prompt-saver';
        details.hidden = typeof this._service.savePrompt !== 'function';

        const summary = document.createElement('summary');
        summary.textContent = t('ckeditor.dialog.prompts.save', 'Save as prompt');
        details.appendChild(summary);

        const titleId = `${idPrefix}-prompt-title`;
        const titleGroup = this._createFormGroup(t('ckeditor.dialog.prompts.title', 'Prompt title'), titleId);
        const titleInput = document.createElement('input');
        titleInput.type = 'text';
        titleInput.className = 'form-control';
        titleInput.id = titleId;
        titleInput.maxLength = 255;
        titleInput.dataset.role = 'prompt-title';
        titleGroup.appendChild(titleInput);
        details.appendChild(titleGroup);

        const shareId = `${idPrefix}-prompt-share`;
        const shareWrap = document.createElement('div');
        shareWrap.className = 'form-check mb-2';
        const shareInput = document.createElement('input');
        shareInput.type = 'checkbox';
        shareInput.className = 'form-check-input';
        shareInput.id = shareId;
        shareInput.dataset.role = 'prompt-share';
        const shareLabel = document.createElement('label');
        shareLabel.className = 'form-check-label';
        shareLabel.htmlFor = shareId;
        shareLabel.textContent = t('ckeditor.dialog.prompts.share', 'Share with other editors');
        shareWrap.append(shareInput, shareLabel);
        details.appendChild(shareWrap);

        const saveButton = document.createElement('button');
        saveButton.type = 'button';
        saveButton.className = 'btn btn-sm btn-default';
        saveButton.dataset.role = 'prompt-save';
        saveButton.textContent = t('ckeditor.dialog.prompts.save', 'Save as prompt');
        details.appendChild(saveButton);

        return details;
    }

    /**
     * Label of the picker's first option: what runs when the editor chooses
     * nothing, which is the task's own configuration or else the default.
     *
     * @param {string} taskConfigurationName
     * @returns {string}
     * @private
     */
    _taskSettingLabel(taskConfigurationName) {
        return taskConfigurationName
            ? t('ckeditor.dialog.configuration.task', 'Task setting (%s)', taskConfigurationName)
            : t('ckeditor.dialog.configuration.default', 'Default configuration');
    }

    /**
     * Show an informational modal when no tasks are configured.
     *
     * Guides the user to the LLM Tasks backend module where they can
     * create or activate cowriter tasks.
     *
     * @returns {Promise<DialogResult>} Always rejects (no content to insert)
     * @private
     */
    _showNoTasksModal() {
        return new Promise((_resolve, reject) => {
            const container = document.createElement('div');
            container.className = 'cowriter-dialog';

            const alert = document.createElement('div');
            alert.className = 'alert alert-info';

            const heading = document.createElement('h4');
            heading.className = 'alert-heading';
            heading.textContent = t('ckeditor.tasks.none.title', 'No tasks configured');
            alert.appendChild(heading);

            const desc = document.createElement('p');
            desc.textContent = t(
                'ckeditor.dialog.noTasks.description',
                'The Cowriter needs at least one active task with '
                + 'category "content" to work. Tasks define what the AI should '
                + 'do with your text (e.g., improve, summarize, translate).',
            );
            alert.appendChild(desc);

            alert.appendChild(document.createElement('hr'));

            const steps = document.createElement('p');
            steps.className = 'mb-0';
            // Each step is a sentence with one bold part, which fills its %s.
            const stepsLines = [
                [t('ckeditor.dialog.noTasks.steps', 'To set up tasks:'), null],
                [
                    t('ckeditor.dialog.noTasks.step1', '1. Go to %s'),
                    t('ckeditor.dialog.noTasks.step1.path', 'Admin Tools \u2192 LLM \u2192 Tasks'),
                ],
                [t('ckeditor.dialog.noTasks.step2', '2. Create tasks with category %s'), '"content"'],
                [
                    t('ckeditor.dialog.noTasks.step3', '3. Make sure they are %s'),
                    t('ckeditor.dialog.noTasks.step3.active', 'active'),
                ],
            ];
            stepsLines.forEach(([line, bold], i) => {
                if (i > 0) steps.appendChild(document.createElement('br'));
                if (bold !== null && line.includes('%s')) {
                    const [before, ...rest] = line.split('%s');
                    steps.appendChild(document.createTextNode(before));
                    const strong = document.createElement('strong');
                    strong.textContent = bold;
                    steps.appendChild(strong);
                    const after = rest.join('%s');
                    if (after) steps.appendChild(document.createTextNode(after));
                } else {
                    steps.appendChild(document.createTextNode(line));
                }
            });
            alert.appendChild(steps);

            container.appendChild(alert);

            const modal = Modal.advanced({
                title: 'Cowriter',
                content: container,
                size: Modal.sizes.small,
                buttons: [
                    {
                        text: t('ckeditor.dialog.button.close', 'Close'),
                        btnClass: 'btn-default',
                        name: 'close',
                        icon: 'actions-close',
                        trigger: () => {
                            modal.hideModal();
                        },
                    },
                ],
            });

            // Handle modal dismissal via Escape key, X button, or Close button
            modal?.addEventListener?.('typo3-modal-hidden', () => {
                reject(new Error('User cancelled'));
                this._restoreFocus();
            });
        });
    }

    /**
     * Build and show the modal dialog.
     *
     * @param {TaskItem[]} tasks
     * @param {string} selectedText
     * @param {string} fullContent
     * @param {string} editorCapabilities
     * @param {{table: string, uid: number, field: string}|null} recordContext
     * @param {number|null} [preSelectedTaskUid=null]
     * @returns {Promise<DialogResult>}
     * @private
     */
    _showModal(
        tasks, selectedText, fullContent, editorCapabilities, recordContext, preSelectedTaskUid = null,
        configurationsRequest = Promise.resolve([]), styleRequest = Promise.resolve({ audiences: [], tones: [] }),
        promptsRequest = Promise.resolve([]),
    ) {
        /** @type {Set<AbortController>} Track reference row listeners for cleanup */
        const referenceAbortControllers = new Set();
        const container = this._buildDialogContent(
            tasks, selectedText, fullContent, recordContext, referenceAbortControllers, preSelectedTaskUid,
        );
        configurationsRequest.then((configurations) => this._offerConfigurations(container, configurations));
        styleRequest.then((options) => this._offerStyleOptions(container, options));
        promptsRequest.then((prompts) => this._offerSavedPrompts(container, prompts));
        container.querySelector('[data-role="prompt-save"]')
            ?.addEventListener('click', () => this._savePrompt(container));
        container.querySelector('[data-role="prompt-delete"]')
            ?.addEventListener('click', () => this._deletePrompt(container));
        /** @type {'idle'|'loading'|'result'} */
        let state = 'idle';
        let resultContent = '';
        const hasSelection = Boolean(selectedText && selectedText.trim().length > 0);
        let currentContext = hasSelection ? selectedText : fullContent;
        const originalContext = currentContext;

        return new Promise((resolve, reject) => {
            let modal;
            let resolved = false;
            /** @type {AbortController|null} */
            let activeRequest = null;

            const cancelTrigger = () => {
                activeRequest?.abort();
                for (const ac of referenceAbortControllers) ac.abort();
                referenceAbortControllers.clear();
                modal.hideModal();
                if (!resolved) {
                    reject(new Error('User cancelled'));
                }
            };

            const resetTrigger = () => {
                activeRequest?.abort();
                state = 'idle';
                resultContent = '';
                currentContext = originalContext;

                const preview = container.querySelector('[data-role="result-preview"]');
                preview.replaceChildren();
                preview.textContent = originalContext;
                preview.classList.add('cowriter-result--empty');

                container.querySelector('[data-role="model-info"]').style.display = 'none';
                container.querySelector('[data-role="debug-details"]')?.remove();
                container.querySelector('[data-role="variant-picker"]')?.remove();

                this._updateButtonVisibility(modal, 'idle');
            };

            const executeTrigger = async () => {
                if (state === 'loading') return;

                state = 'loading';
                this._setButtonsDisabled(modal, true);
                this._updateButtonVisibility(modal, 'loading');

                const preview = container.querySelector('[data-role="result-preview"]');
                const modelInfo = container.querySelector('[data-role="model-info"]');
                preview.replaceChildren();
                const spinner = document.createElement('span');
                spinner.className = 'spinner-border spinner-border-sm me-2';
                spinner.setAttribute('role', 'status');
                spinner.setAttribute('aria-hidden', 'true');
                preview.appendChild(spinner);
                preview.appendChild(document.createTextNode(t('ckeditor.dialog.generating', 'Generating\u2026')));
                preview.classList.remove('cowriter-result--empty');
                modelInfo.style.display = 'none';

                try {
                    const taskSelect = container.querySelector('[data-role="task-select"]');
                    const taskUid = taskSelect.options[taskSelect.selectedIndex]?.dataset.promptUid
                        ? 0
                        : parseInt(taskSelect.value, 10);
                    const contextScope = container.querySelector('[data-role="scope-select"]').value;
                    const contextType = hasSelection ? 'selection' : 'content_element';
                    const instruction = container.querySelector(
                        '[data-role="instruction"]',
                    ).value.trim();

                    const refRows = container.querySelectorAll('[data-role="reference-row"]');
                    const referencePages = [];
                    for (const row of refRows) {
                        const pid = parseInt(row.querySelector('[data-role="ref-pid"]')?.value, 10);
                        const relation = row.querySelector('[data-role="ref-relation"]')?.value?.trim() || '';
                        if (pid > 0) {
                            referencePages.push({ pid, relation });
                        }
                    }

                    const configuration = container.querySelector('[data-role="configuration-select"]')?.value || '';
                    const style = this._readStyle(container);

                    const inputText = currentContext;
                    activeRequest = new AbortController();
                    const result = this._canStream() && style.variants === 1 && !style.useTools
                        ? await this._streamTask(preview, {
                            taskUid, context: currentContext, contextType, instruction, editorCapabilities,
                            contextScope, recordContext, referencePages, configuration, ...style,
                        }, activeRequest.signal)
                        : await this._service.executeTask(
                            taskUid, currentContext, contextType, instruction, editorCapabilities,
                            contextScope, recordContext, referencePages, activeRequest.signal, configuration, style,
                        );
                    activeRequest = null;

                    if (result.success && result.content && result.content.trim()) {
                        const safeBody = this._sanitizeHtml(result.content);
                        const sanitized = safeBody.innerHTML;
                        preview.replaceChildren(...Array.from(safeBody.childNodes));
                        resultContent = sanitized;
                        currentContext = sanitized;
                        state = 'result';
                        preview.classList.remove('cowriter-result--empty');

                        if (result.model) {
                            let infoText = t('ckeditor.dialog.model', 'Model: %s', result.model);
                            if (result.usage && result.usage.totalTokens) {
                                infoText += ' | ' + t('ckeditor.dialog.tokens', '%s tokens', result.usage.totalTokens);
                            }
                            modelInfo.textContent = infoText;
                            modelInfo.style.display = 'block';
                        }
                        if (result.toolIterations) {
                            const toolsText = t('ckeditor.dialog.toolIterations', 'Tool steps: %s', result.toolIterations);
                            modelInfo.textContent = modelInfo.textContent ? `${modelInfo.textContent} | ${toolsText}` : toolsText;
                            modelInfo.style.display = 'block';
                        }
                        if (result.targetWords) {
                            // safeBody's nodes now live in the preview, so count what it shows.
                            const words = (preview.textContent || '').trim().split(/\s+/).filter(Boolean).length;
                            const wordsText = t('ckeditor.dialog.wordsTarget', 'About %s words (target %s)', words, result.targetWords);
                            modelInfo.textContent = modelInfo.textContent ? `${modelInfo.textContent} | ${wordsText}` : wordsText;
                            modelInfo.style.display = 'block';
                        }

                        container.querySelector('[data-role="variant-picker"]')?.remove();
                        if (Array.isArray(result.variants) && result.variants.length > 1) {
                            preview.before(this._buildVariantPicker(result.variants.length, (index) => {
                                const chosen = this._sanitizeHtml(result.variants[index]);
                                const html = chosen.innerHTML;
                                preview.replaceChildren(...Array.from(chosen.childNodes));
                                resultContent = html;
                                currentContext = html;
                            }));
                        }

                        this._showDebugDetails(container, result, inputText, instruction);
                        this._updateButtonVisibility(modal, 'result');
                    } else {
                        preview.textContent = result.error || t('ckeditor.dialog.noContent', 'No content returned');
                        if (result.statusUrl) {
                            this._appendStatusLink(preview, result.statusUrl);
                        }
                        preview.classList.add('cowriter-result--empty');
                        this._showDebugDetails(container, result, inputText, instruction);
                        resultContent = '';
                        state = 'idle';
                        this._updateButtonVisibility(modal, 'idle');
                    }
                } catch (error) {
                    activeRequest = null;
                    if (error?.name === 'AbortError') {
                        state = 'idle';
                        this._updateButtonVisibility(modal, 'idle');
                        this._setButtonsDisabled(modal, false);
                        return;
                    }
                    preview.textContent = t('ckeditor.dialog.error', 'Error: %s', error.message);
                    if (error.statusUrl) {
                        this._appendStatusLink(preview, error.statusUrl);
                    }
                    preview.classList.add('cowriter-result--empty');
                    this._showDebugDetails(container, {error: error.message}, currentContext, '');
                    resultContent = '';
                    state = 'idle';
                    this._updateButtonVisibility(modal, 'idle');
                }

                this._setButtonsDisabled(modal, false);
            };

            const insertTrigger = () => {
                if (!resultContent) return;
                resolved = true;
                modal.hideModal();
                resolve({ content: resultContent });
            };

            modal = Modal.advanced({
                title: 'Cowriter',
                content: container,
                size: Modal.sizes.large,
                buttons: [
                    {
                        text: t('ckeditor.dialog.button.cancel', 'Cancel'),
                        btnClass: 'btn-default', name: 'cancel', icon: 'actions-close', trigger: cancelTrigger,
                    },
                    {
                        text: t('ckeditor.dialog.button.reset', 'Reset'),
                        btnClass: 'btn-default', name: 'reset', icon: 'actions-undo', trigger: resetTrigger,
                    },
                    {
                        text: t('ckeditor.dialog.button.execute', 'Execute'),
                        btnClass: 'btn-default', name: 'execute', icon: 'actions-play', trigger: executeTrigger,
                    },
                    {
                        text: t('ckeditor.dialog.button.insert', 'Insert'),
                        btnClass: 'btn-primary', name: 'insert', icon: 'actions-insert', trigger: insertTrigger,
                    },
                ],
            });

            // Opened from the FormEngine iframe, the TYPO3 modal lives in the
            // parent window, so the content built above changes document. Plain
            // nodes survive that; styles and Lit elements have to be made by the
            // modal's document. Modal.advanced() renders the content only after
            // it has returned, so the icon is in place before it connects.
            const modalDocument = modal?.ownerDocument ?? document;
            injectStyles(modalDocument);
            container.querySelector('[data-role="add-reference"]')
                ?.prepend(createIcon(modalDocument, 'actions-plus'));

            // Handle modal dismissal — always clean up listeners/requests
            modal?.addEventListener?.('typo3-modal-hidden', () => {
                activeRequest?.abort();
                for (const ac of referenceAbortControllers) ac.abort();
                referenceAbortControllers.clear();
                if (!resolved) {
                    reject(new Error('User cancelled'));
                }
                // Last: settling and clean-up must not depend on it.
                this._restoreFocus();
            });

            this._updateButtonVisibility(modal, 'idle');
            // The TYPO3 modal is a Lit element: Modal.advanced() returns before its
            // footer buttons are rendered, so the call above finds none. Hide them
            // once the first render has completed. No button exists before then,
            // so no click can have changed the state in between.
            modal?.updateComplete?.then?.(() => this._updateButtonVisibility(modal, 'idle'));
        });
    }

    /**
     * Give focus back to what had it when show() was called. Runs once the
     * modal is hidden: while it is open, the rest of the page is inert.
     * @private
     */
    _restoreFocus() {
        const target = this._returnFocusTo;
        this._returnFocusTo = null;
        try {
            if (this._returnFocus) {
                this._returnFocus();
                return;
            }
            if (target && target.isConnected && target !== target.ownerDocument.body) {
                target.focus({ preventScroll: true });
            }
        } catch (error) {
            // Focus is a courtesy; a failing callback must not break closing.
            console.error('[Cowriter]', error);
        }
    }

    /**
     * Build dialog content using native DOM.
     *
     * @param {TaskItem[]} tasks
     * @param {string} selectedText
     * @param {string} fullContent
     * @param {{table: string, uid: number, field: string}|null} recordContext
     * @param {Set<AbortController>} referenceAbortControllers
     * @param {number|null} [preSelectedTaskUid=null]
     * @returns {HTMLElement}
     * @private
     */
    _buildDialogContent(
        tasks, selectedText, fullContent, recordContext, referenceAbortControllers, preSelectedTaskUid = null,
    ) {
        const container = document.createElement('div');
        container.className = 'cowriter-dialog';
        const idPrefix = `cowriter-${++formIdCounter}`;

        // Task selector
        const taskSelectId = `${idPrefix}-task`;
        const taskGroup = this._createFormGroup(t('ckeditor.dialog.task', 'Task'), taskSelectId);
        const taskSelect = document.createElement('select');
        taskSelect.className = 'form-select';
        taskSelect.id = taskSelectId;
        taskSelect.dataset.role = 'task-select';
        // Start keyboard users in the content, not on the close button: the
        // 13.4 modal focuses [autofocus], the 14.3 native dialog does too.
        taskSelect.autofocus = true;

        // "Custom instruction" option first
        const customOption = document.createElement('option');
        customOption.value = '0';
        customOption.textContent = t('ckeditor.dialog.customInstruction', 'Custom instruction');
        customOption.dataset.description = t(
            'ckeditor.dialog.customInstruction.description',
            'Write your own instruction for the AI',
        );
        customOption.dataset.promptTemplate = '';
        taskSelect.appendChild(customOption);

        for (const task of tasks) {
            const option = document.createElement('option');
            option.value = String(task.uid);
            option.textContent = task.name;
            option.dataset.description = task.description || '';
            option.dataset.promptTemplate = task.promptTemplate || '';
            option.dataset.configurationName = task.configuration?.name || '';
            taskSelect.appendChild(option);
        }

        // Select pre-selected task or first real task by default
        if (preSelectedTaskUid !== null && preSelectedTaskUid !== undefined && tasks.some(t => t.uid === preSelectedTaskUid)) {
            taskSelect.value = String(preSelectedTaskUid);
        } else if (tasks.length > 0) {
            taskSelect.value = String(tasks[0].uid);
        }
        taskGroup.appendChild(taskSelect);

        const taskDescRow = document.createElement('div');
        taskDescRow.className = 'd-flex justify-content-between align-items-start';
        const taskDesc = document.createElement('div');
        taskDesc.className = 'form-text text-body-secondary';
        taskDesc.dataset.role = 'task-description';
        const selectedOption = taskSelect.options[taskSelect.selectedIndex];
        taskDesc.textContent = selectedOption?.dataset.description || '';
        taskDescRow.appendChild(taskDesc);

        const tasksModuleUrl = this._service.getModuleUrl?.('tasksModule') ?? null;
        if (tasksModuleUrl) {
            const editLink = document.createElement('a');
            editLink.href = tasksModuleUrl;
            editLink.target = '_blank';
            editLink.rel = 'noopener noreferrer';
            editLink.className = 'form-text text-body-secondary text-nowrap ms-2';
            editLink.textContent = t('ckeditor.dialog.editTasks', 'Edit tasks \u2197');
            editLink.title = t('ckeditor.dialog.editTasks.title', 'Manage tasks in LLM module');
            taskDescRow.appendChild(editLink);
        }
        const deletePromptButton = document.createElement('button');
        deletePromptButton.type = 'button';
        deletePromptButton.className = 'btn btn-sm btn-link text-nowrap ms-2 p-0';
        deletePromptButton.dataset.role = 'prompt-delete';
        deletePromptButton.textContent = t('ckeditor.dialog.prompts.delete', 'Delete prompt');
        deletePromptButton.hidden = true;
        taskDescRow.appendChild(deletePromptButton);
        taskGroup.appendChild(taskDescRow);

        // Context scope dropdown
        const scopeId = `${idPrefix}-scope`;
        const contextGroup = this._createFormGroup(t('ckeditor.dialog.contextScope', 'Context scope'), scopeId);
        const hasSelection = Boolean(selectedText && selectedText.trim().length > 0);

        const scopeSelect = document.createElement('select');
        scopeSelect.className = 'form-select';
        scopeSelect.id = scopeId;
        scopeSelect.dataset.role = 'scope-select';

        for (let i = 0; i < SCOPE_IDS.length; i++) {
            const opt = document.createElement('option');
            opt.value = SCOPE_IDS[i];
            opt.textContent = t(`ckeditor.dialog.scope.${SCOPE_IDS[i]}`, SCOPE_LABELS[i]);
            if (i === 0 && !hasSelection) {
                opt.disabled = true;
            }
            if (i >= 2 && !recordContext) {
                opt.disabled = true;
            }
            scopeSelect.appendChild(opt);
        }

        scopeSelect.value = hasSelection ? 'selection' : 'text';
        contextGroup.appendChild(scopeSelect);

        // LLM configuration picker, hidden until _offerConfigurations() fills
        // it. The first option runs the task on its own configuration (or the
        // default); the others override it.
        const configurationId = `${idPrefix}-configuration`;
        const configurationGroup = this._createFormGroup(
            t('ckeditor.dialog.configuration', 'LLM configuration'), configurationId,
        );
        const configurationSelect = document.createElement('select');
        configurationSelect.className = 'form-select';
        configurationSelect.id = configurationId;
        configurationSelect.dataset.role = 'configuration-select';

        const taskSettingOption = document.createElement('option');
        taskSettingOption.value = '';
        taskSettingOption.dataset.role = 'configuration-task-setting';
        taskSettingOption.textContent = this._taskSettingLabel(selectedOption?.dataset.configurationName || '');
        configurationSelect.appendChild(taskSettingOption);
        configurationGroup.appendChild(configurationSelect);

        // Config row: task, configuration and scope side by side
        const configRow = document.createElement('div');
        configRow.className = 'row mb-3';
        configRow.dataset.role = 'config-row';

        const taskCol = document.createElement('div');
        taskCol.className = 'col-md-8';
        taskCol.dataset.role = 'task-col';
        taskCol.appendChild(taskGroup);
        configRow.appendChild(taskCol);

        const configurationCol = document.createElement('div');
        configurationCol.className = 'col-md-4';
        configurationCol.dataset.role = 'configuration-col';
        configurationCol.hidden = true;
        configurationCol.appendChild(configurationGroup);
        configRow.appendChild(configurationCol);

        const scopeCol = document.createElement('div');
        scopeCol.className = 'col-md-4';
        scopeCol.dataset.role = 'scope-col';
        scopeCol.appendChild(contextGroup);
        configRow.appendChild(scopeCol);
        container.appendChild(configRow);
        container.appendChild(this._buildStyleRow(idPrefix));

        // Reference pages section
        const refGroup = this._createFormGroup(t('ckeditor.dialog.referencePages', 'Reference pages (optional)'));

        const refContainer = document.createElement('div');
        refContainer.dataset.role = 'reference-list';
        refGroup.appendChild(refContainer);

        const addRefBtn = document.createElement('button');
        addRefBtn.type = 'button';
        // btn-default: the backend CSS (14.3) has no btn-outline-* rules, so
        // those buttons had no border and no visible focus outline.
        addRefBtn.className = 'btn btn-sm btn-default mt-1';
        addRefBtn.dataset.role = 'add-reference';
        // The icon is added by _showModal() with the modal's document.
        addRefBtn.append(' ' + t('ckeditor.dialog.addReference', 'Add reference page'));
        addRefBtn.addEventListener('click', () => {
            // By the time of a click, the list shows in the modal's document.
            refContainer.appendChild(
                this._createReferenceRow(referenceAbortControllers, refContainer.ownerDocument),
            );
        });
        refGroup.appendChild(addRefBtn);

        container.appendChild(refGroup);

        // Relation presets datalist
        if (!document.getElementById('cowriter-relation-presets')) {
            const datalist = document.createElement('datalist');
            datalist.id = 'cowriter-relation-presets';
            const presets = [
                t('ckeditor.dialog.relationPreset.referenceMaterial', 'reference material'),
                t('ckeditor.dialog.relationPreset.parentTopic', 'parent topic'),
                t('ckeditor.dialog.relationPreset.styleGuide', 'style guide'),
                t('ckeditor.dialog.relationPreset.similarContent', 'similar content'),
            ];
            for (const preset of presets) {
                const option = document.createElement('option');
                option.value = preset;
                datalist.appendChild(option);
            }
            container.appendChild(datalist);
        }

        // Instruction textarea (primary input)
        const instructionId = `${idPrefix}-instruction`;
        const instructionGroup = this._createFormGroup(t('ckeditor.dialog.instruction', 'Instruction'), instructionId);
        const instructionInput = document.createElement('textarea');
        instructionInput.className = 'form-control';
        instructionInput.id = instructionId;
        instructionInput.dataset.role = 'instruction';
        instructionInput.rows = 10;
        instructionInput.placeholder = t('ckeditor.dialog.instruction.placeholder', 'Describe what the AI should do\u2026');

        // Prefill with resolved template from the initially selected task
        const initialTemplate = selectedOption?.dataset.promptTemplate || '';
        if (initialTemplate) {
            instructionInput.value = this._resolveTemplate(initialTemplate);
        }
        instructionGroup.appendChild(instructionInput);
        instructionGroup.appendChild(this._buildPromptSaver(idPrefix));

        const promptStatus = document.createElement('div');
        promptStatus.className = 'form-text';
        promptStatus.dataset.role = 'prompt-status';
        promptStatus.setAttribute('role', 'status');
        promptStatus.setAttribute('aria-live', 'polite');
        instructionGroup.appendChild(promptStatus);

        // Task change handler: prefill the instruction with the resolved task
        // template, or with the text of a saved prompt as it was saved.
        taskSelect.addEventListener('change', () => {
            const selected = taskSelect.options[taskSelect.selectedIndex];
            taskDesc.textContent = selected?.dataset.description || '';
            taskSettingOption.textContent = this._taskSettingLabel(selected?.dataset.configurationName || '');
            deletePromptButton.hidden = selected?.dataset.promptOwn !== '1';
            if (selected?.dataset.promptUid) {
                instructionInput.value = selected.dataset.instruction || '';
                return;
            }
            const template = selected?.dataset.promptTemplate || '';
            instructionInput.value = template
                ? this._resolveTemplate(template)
                : '';
        });

        // Result preview
        const resultGroup = this._createFormGroup(t('ckeditor.dialog.result', 'Result'));
        const preview = document.createElement('div');
        preview.className = 'cowriter-result cowriter-result--empty';
        preview.dataset.role = 'result-preview';
        preview.setAttribute('role', 'status');
        preview.setAttribute('aria-live', 'polite');
        const inputPreview = selectedText || fullContent;
        preview.textContent = inputPreview;
        resultGroup.appendChild(preview);

        const modelInfo = document.createElement('small');
        modelInfo.className = 'form-text text-body-secondary';
        modelInfo.dataset.role = 'model-info';
        modelInfo.style.display = 'none';
        resultGroup.appendChild(modelInfo);

        // Work row: instruction + result side by side
        const workRow = document.createElement('div');
        workRow.className = 'row mb-3';
        workRow.dataset.role = 'work-row';

        const instructionCol = document.createElement('div');
        instructionCol.className = 'col-md-6';
        instructionCol.appendChild(instructionGroup);

        const resultCol = document.createElement('div');
        resultCol.className = 'col-md-6';
        resultCol.appendChild(resultGroup);

        workRow.appendChild(instructionCol);
        workRow.appendChild(resultCol);
        container.appendChild(workRow);

        return container;
    }

    /**
     * @param {string} label
     * @param {string|null} [inputId=null]
     * @returns {HTMLElement}
     * @private
     */
    _createFormGroup(label, inputId = null) {
        const group = document.createElement('div');
        group.className = 'mb-3';
        const labelEl = document.createElement('label');
        labelEl.className = 'form-label fw-bold';
        labelEl.textContent = label;
        if (inputId) {
            labelEl.setAttribute('for', inputId);
        }
        group.appendChild(labelEl);
        return group;
    }

    /**
     * Create a reference page row with page ID, relation, and remove button.
     * @param {Set<AbortController>} referenceAbortControllers
     * @param {Document} [doc=document] - The document the row is shown in (the modal's)
     * @returns {HTMLElement}
     * @private
     */
    _createReferenceRow(referenceAbortControllers, doc = document) {
        const row = document.createElement('div');
        // Top-aligned: "No pages found" below the search field makes the
        // wrapper taller, and centring would move the other controls.
        row.className = 'd-flex gap-2 mb-1 align-items-start';
        row.dataset.role = 'reference-row';

        // Wrapper for search input + dropdown
        const searchWrapper = document.createElement('div');
        searchWrapper.className = 'position-relative';
        // The TYPO3 backend CSS has no Bootstrap position utilities (14.3), so
        // the classes alone leave the list in the flow; set the position here.
        searchWrapper.style.position = 'relative';
        searchWrapper.style.minWidth = '250px';
        // Share the free width of the row with the relation field, so that
        // page titles fit.
        searchWrapper.style.flex = '1 1 0';

        const dropdownId = 'cowriter-ref-dropdown-' + Math.random().toString(36).slice(2, 9);

        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.className = 'form-control form-control-sm';
        searchInput.dataset.role = 'ref-search';
        searchInput.placeholder = t('ckeditor.dialog.pageSearch.placeholder', 'Search pages by title or ID...');
        searchInput.setAttribute('aria-label', t('ckeditor.dialog.pageSearch.label', 'Search pages by title or ID'));
        searchInput.setAttribute('role', 'combobox');
        searchInput.setAttribute('aria-expanded', 'false');
        searchInput.setAttribute('aria-controls', dropdownId);
        searchInput.setAttribute('aria-autocomplete', 'list');
        searchWrapper.appendChild(searchInput);

        const hiddenPid = document.createElement('input');
        hiddenPid.type = 'hidden';
        hiddenPid.dataset.role = 'ref-pid';
        searchWrapper.appendChild(hiddenPid);

        const dropdown = document.createElement('div');
        dropdown.className = 'list-group position-absolute w-100 shadow-sm';
        dropdown.id = dropdownId;
        dropdown.setAttribute('role', 'listbox');
        dropdown.style.position = 'absolute';
        dropdown.style.zIndex = '1050';
        dropdown.style.maxHeight = '200px';
        dropdown.style.overflowY = 'auto';
        // A scrolling box whose options are no Tab stops is one itself in
        // Chromium; keep the list out of the Tab order as well.
        dropdown.tabIndex = -1;
        dropdown.style.display = 'none';
        dropdown.dataset.role = 'ref-dropdown';
        dropdown.setAttribute('aria-label', t('ckeditor.dialog.pageSearch.results', 'Matching pages'));
        // A press on the list (its scrollbar, an option) must not take focus
        // from the field: the arrow keys work there only, and an option click
        // then does not depend on focusout's relatedTarget.
        dropdown.addEventListener('mousedown', (e) => e.preventDefault());
        searchWrapper.appendChild(dropdown);

        // Result count for screen readers, and the visible "No pages found":
        // outside the listbox, which may only hold options.
        const status = document.createElement('div');
        status.className = 'form-text';
        status.dataset.role = 'ref-status';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        searchWrapper.appendChild(status);

        // AbortController for cleanup of event listeners and in-flight requests
        const controller = new AbortController();
        referenceAbortControllers?.add(controller);

        // Debounced search with stale-response guard
        let debounceTimer;
        let currentSearchId = 0;
        // Closing also drops a search still pending or in flight: its answer
        // would open the list again, over whatever has focus by then.
        const closeList = () => {
            clearTimeout(debounceTimer);
            currentSearchId++;
            this._closePageList(dropdown, searchInput);
        };
        searchInput.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            hiddenPid.value = '';  // Clear selection on new input
            const query = searchInput.value.trim();
            if (query.length < 2) {
                closeList();
                return;
            }
            const searchId = ++currentSearchId;
            debounceTimer = setTimeout(async () => {
                try {
                    const result = await this._service.searchPages(query);
                    if (searchId !== currentSearchId) return; // discard stale response
                    this._renderPageDropdown(dropdown, result.pages, searchInput, hiddenPid, closeList);
                } catch {
                    if (searchId === currentSearchId) {
                        closeList();
                    }
                }
            }, 300);
        }, { signal: controller.signal });

        // Escape anywhere in the search (field, list, "No pages found")
        // closes only the list and keeps focus in the field. Keep the key from
        // the modal: the 14.3 modal (native <dialog>) closes unless the keydown
        // is default-prevented, the 13.4 modal listens on the document.
        searchWrapper.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape' || !this._isPageListOpen(dropdown)) return;
            e.preventDefault();
            e.stopPropagation();
            closeList();
            searchInput.focus();
        }, { signal: controller.signal });

        // Keyboard navigation for dropdown
        searchInput.addEventListener('keydown', (e) => {
            const items = dropdown.querySelectorAll('[role="option"]');
            if (!items.length || dropdown.style.display === 'none') {
                return;
            }
            const active = dropdown.querySelector('.active');
            let idx = active ? Array.from(items).indexOf(active) : -1;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (active) { active.classList.remove('active'); active.setAttribute('aria-selected', 'false'); }
                idx = (idx + 1) % items.length;
                items[idx].classList.add('active');
                items[idx].setAttribute('aria-selected', 'true');
                items[idx].scrollIntoView?.({ block: 'nearest' });
                searchInput.setAttribute('aria-activedescendant', items[idx].id);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (active) { active.classList.remove('active'); active.setAttribute('aria-selected', 'false'); }
                idx = idx <= 0 ? items.length - 1 : idx - 1;
                items[idx].classList.add('active');
                items[idx].setAttribute('aria-selected', 'true');
                items[idx].scrollIntoView?.({ block: 'nearest' });
                searchInput.setAttribute('aria-activedescendant', items[idx].id);
            } else if (e.key === 'Enter' && active) {
                e.preventDefault();
                active.click();
            }
        }, { signal: controller.signal });

        // Close dropdown on outside click (scoped to AbortController). The
        // clicks happen in the modal's document, not necessarily in this one,
        // so the listener is removed by hand: the controller belongs to this
        // window, and not every implementation accepts a signal from another
        // window (jsdom rejects it).
        const closeOnOutsidePointer = (e) => {
            if (!searchWrapper.contains(e.target)) {
                closeList();
            }
        };
        doc.addEventListener('pointerdown', closeOnOutsidePointer);
        controller.signal.addEventListener('abort', () => doc.removeEventListener('pointerdown', closeOnOutsidePointer));
        // Close it when focus leaves the search, so that the open list never
        // covers the control that receives focus (WCAG 2.4.11).
        searchWrapper.addEventListener('focusout', (e) => {
            if (!searchWrapper.contains(/** @type {Node|null} */ (e.relatedTarget))) {
                closeList();
            }
        }, { signal: controller.signal });

        row.appendChild(searchWrapper);

        const relationInput = document.createElement('input');
        relationInput.type = 'text';
        relationInput.className = 'form-control form-control-sm';
        relationInput.dataset.role = 'ref-relation';
        relationInput.placeholder = t('ckeditor.dialog.relation.placeholder', 'Relation (e.g., style guide)');
        relationInput.setAttribute('aria-label', t('ckeditor.dialog.relation.label', 'Relation to reference page'));
        relationInput.setAttribute('list', 'cowriter-relation-presets');
        relationInput.style.flex = '1 1 0';
        row.appendChild(relationInput);

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-sm btn-default';
        removeBtn.dataset.role = 'remove-reference';
        removeBtn.appendChild(createIcon(doc, 'actions-delete'));
        removeBtn.setAttribute('aria-label', t('ckeditor.dialog.removeReference', 'Remove reference page'));
        removeBtn.addEventListener('click', () => {
            clearTimeout(debounceTimer);
            controller.abort();
            referenceAbortControllers?.delete(controller);
            // The focused button goes away with the row: move focus to the
            // next row's page search, or to the add button after the last row.
            const next = row.nextElementSibling?.querySelector('[data-role="ref-search"]')
                ?? row.parentElement?.parentElement?.querySelector('[data-role="add-reference"]');
            row.remove();
            next?.focus();
        });
        row.appendChild(removeBtn);

        return row;
    }

    /**
     * Render the page search dropdown with results.
     *
     * @param {HTMLElement} dropdown
     * @param {Array<{uid: number, title: string, slug: string}>} pages
     * @param {HTMLInputElement} searchInput
     * @param {HTMLInputElement} hiddenPid
     * @param {() => void} [closeList] - Closes the list; the reference row
     *     passes one that also drops a pending search.
     * @private
     */
    _renderPageDropdown(dropdown, pages, searchInput, hiddenPid,
        closeList = () => this._closePageList(dropdown, searchInput)) {
        dropdown.replaceChildren();
        searchInput.removeAttribute('aria-activedescendant');
        const status = this._pageStatus(dropdown);
        dropdown.setAttribute('role', 'listbox');
        if (!pages || pages.length === 0) {
            // No listbox without options: the list stays closed, the status
            // says so, visibly and to screen readers.
            dropdown.style.display = 'none';
            searchInput.setAttribute('aria-expanded', 'false');
            if (status) {
                this._setStatusText(status, t('ckeditor.dialog.noPagesFound', 'No pages found'));
                status.dataset.state = 'empty';
                status.classList.remove('visually-hidden');
            }
            return;
        }
        const prefix = dropdown.id || 'ref-opt';
        for (let i = 0; i < pages.length; i++) {
            const page = pages[i];
            const item = document.createElement('button');
            item.type = 'button';
            item.id = `${prefix}-${i}`;
            item.className = 'list-group-item list-group-item-action small';
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', 'false');
            // Combobox pattern: focus stays in the field, the arrow keys move
            // aria-activedescendant; an option is no Tab stop of its own.
            item.tabIndex = -1;
            // Long titles wrap instead of being cut off: at 200 % zoom the
            // list is narrow, and the full text must stay readable.
            item.style.whiteSpace = 'normal';
            item.style.overflowWrap = 'anywhere';
            item.textContent = `[${page.uid}] ${page.title}`;
            if (page.slug) {
                const slug = document.createElement('span');
                slug.className = 'text-muted';
                // Separated in the text itself, so that the accessible name
                // reads "Title – /slug", not "Title/slug".
                slug.textContent = ` \u2013 ${page.slug}`;
                item.appendChild(slug);
            }
            // The whole text as a tooltip as well.
            item.title = item.textContent;
            item.addEventListener('click', () => {
                hiddenPid.value = String(page.uid);
                searchInput.value = `[${page.uid}] ${page.title}`;
                closeList();
                // A mouse click focuses the option, which the list now hides.
                searchInput.focus();
            });
            dropdown.appendChild(item);
        }
        dropdown.style.display = 'block';
        searchInput.setAttribute('aria-expanded', 'true');
        if (status) {
            this._setStatusText(status, pages.length === 1
                ? t('ckeditor.dialog.pageFound', '1 page found')
                : t('ckeditor.dialog.pagesFound', '%s pages found', pages.length));
            delete status.dataset.state;
            // Announced only: the open list covers the place below the field.
            status.classList.add('visually-hidden');
        }
    }

    /**
     * Set the live region's text only when it changes: a screen reader
     * announces every write, and each keystroke of a slow typist searches
     * again with the same result.
     *
     * @param {HTMLElement} status
     * @param {string} text
     * @private
     */
    _setStatusText(status, text) {
        if (status.textContent !== text) {
            status.textContent = text;
        }
    }

    /**
     * @param {HTMLElement} dropdown
     * @returns {HTMLElement|null} The status element of the page search
     * @private
     */
    _pageStatus(dropdown) {
        return dropdown.parentElement?.querySelector('[data-role="ref-status"]') ?? null;
    }

    /**
     * Whether the page search shows anything to close: the list, or the
     * "No pages found" status.
     *
     * @param {HTMLElement} dropdown
     * @returns {boolean}
     * @private
     */
    _isPageListOpen(dropdown) {
        return dropdown.style.display !== 'none' || this._pageStatus(dropdown)?.dataset.state === 'empty';
    }

    /**
     * Close the page list, wherever it is closed from: the list, the combobox
     * state, the active option and the status go together.
     *
     * @param {HTMLElement} dropdown
     * @param {HTMLInputElement} searchInput
     * @private
     */
    _closePageList(dropdown, searchInput) {
        dropdown.style.display = 'none';
        searchInput.setAttribute('aria-expanded', 'false');
        searchInput.removeAttribute('aria-activedescendant');
        const status = this._pageStatus(dropdown);
        if (status) {
            status.textContent = '';
            delete status.dataset.state;
        }
    }

    /**
     * Decode HTML entities back to their original characters.
     *
     * Uses a textarea element which safely decodes entities without executing scripts.
     * Kept for prompt template decoding and debug display.
     *
     * @param {string} escaped
     * @returns {string} The decoded HTML string
     * @private
     */
    _decodeEntities(escaped) {
        // Safe: textarea.innerHTML decodes entities but never executes scripts
        const textarea = document.createElement('textarea');
        textarea.innerHTML = escaped;
        return textarea.value;
    }

    /**
     * Parse HTML string safely via DOMParser and remove dangerous elements/attributes.
     *
     * Uses an allowlist approach: only known-safe HTML elements and attributes
     * are preserved. Everything else is removed. This protects against SVG,
     * MathML, custom elements, and future HTML additions.
     *
     * @param {string} html
     * @returns {HTMLElement} The sanitized body element
     * @private
     */
    _sanitizeHtml(html) {
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
                            || (normalized.startsWith('data:')
                                && !/^data:image\/(png|jpeg|gif|webp)(;base64)?,/.test(normalized))) {
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

    /**
     * Show or hide Reset and Insert buttons based on dialog state. The TYPO3
     * modal renders a button's name as its name attribute.
     *
     * @param {object} modal
     * @param {'idle'|'loading'|'result'} state
     * @private
     */
    _updateButtonVisibility(modal, state) {
        const reset = modal?.querySelector?.('button[name="reset"]');
        const insert = modal?.querySelector?.('button[name="insert"]');
        if (reset) reset.style.display = state === 'result' ? '' : 'none';
        if (insert) insert.style.display = state === 'result' ? '' : 'none';
    }

    /**
     * Resolve a prompt template by decoding entities and stripping {{input}} placeholders.
     *
     * Editor content is now sent separately as a structured system message,
     * so {{input}} is stripped rather than substituted.
     *
     * @param {string} template - HTML-escaped template from server
     * @returns {string} The resolved template
     * @private
     */
    _resolveTemplate(template) {
        const decoded = this._decodeEntities(template);
        return decoded.replace(/\{\{input\}\}/g, '').trim();
    }

    /**
     * Defence in depth on the locally injected route.
     *
     * The route comes from `TYPO3.settings.ajaxUrls`, so it is ours and this
     * check should never reject it. It stays because a value that reaches
     * `href` deserves a guard regardless of how trustworthy its source looks
     * today, and because the cost is one comparison.
     */
    _isSameOriginHttpUrl(candidate) {
        try {
            const url = new URL(candidate, globalThis.location.origin);
            return (url.protocol === 'http:' || url.protocol === 'https:')
                && url.origin === globalThis.location.origin;
        } catch {
            return false;
        }
    }

    /**
     * Append the setup-status link.
     *
     * `hasStatus` is a SIGNAL, not a URL. Both callers read it off an API
     * payload — one of them out of a catch block, i.e. from an exception — and
     * a value from there must never reach `href`. Earlier revisions did exactly
     * that and tried to make it safe by validating the scheme, then also the
     * origin; CodeQL kept reporting js/xss-through-exception because the
     * exception-derived value still flowed into the sink, guard or no guard.
     *
     * There is no need for it to. `TYPO3.settings.ajaxUrls.cowriter_status`
     * puts the very same backend route on the page independently of any
     * response, so the href is resolved locally and the payload only decides
     * WHETHER a link is worth showing. The taint path is gone rather than
     * fenced off, which is both the stronger property and the one a scanner
     * can see.
     *
     * @param {HTMLElement} container
     * @param {unknown} hasStatus truthy when the backend reported a status
     *                            endpoint; its value is deliberately unused
     */
    _appendStatusLink(container, hasStatus) {
        if (!hasStatus) {
            return;
        }
        const url = this._service.getModuleUrl('statusModule');
        if (!url || !this._isSameOriginHttpUrl(url)) {
            return;
        }
        const link = document.createElement('a');
        link.href = url;
        link.textContent = ' ' + t('ckeditor.dialog.openSetupStatus', 'Open Setup Status');
        link.className = 'ms-1';
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        container.appendChild(link);
    }

    /**
     * Show collapsible debug details below the model info.
     *
     * @param {HTMLElement} container
     * @param {object} result - The API response
     * @param {string} inputText - The original input text sent
     * @param {string} instructionSent - The instruction that was sent
     * @private
     */
    _showDebugDetails(container, result, inputText, instructionSent) {
        // Remove existing debug section if re-executing
        container.querySelector('[data-role="debug-details"]')?.remove();

        const details = document.createElement('details');
        details.className = 'mt-2';
        details.dataset.role = 'debug-details';

        const summary = document.createElement('summary');
        summary.className = 'text-body-secondary';
        summary.style.cursor = 'pointer';
        summary.style.fontSize = '0.85rem';
        summary.textContent = t('ckeditor.dialog.debug.summary', 'Debug info');
        details.appendChild(summary);

        const content = document.createElement('div');
        content.className = 'mt-1';
        content.style.fontSize = '0.8rem';
        content.style.fontFamily = 'monospace';
        content.style.whiteSpace = 'pre-wrap';
        content.style.wordBreak = 'break-word';

        const unknown = t('ckeditor.dialog.debug.unknown', 'unknown');
        const heading = (key, fallback) => `--- ${t(key, fallback)} ---`;
        const lines = [];
        if (result.error) {
            lines.push(t('ckeditor.dialog.error', 'Error: %s', result.error));
        }
        if (result.debugError) {
            lines.push(t('ckeditor.dialog.debug.providerError', 'Provider error: %s', result.debugError));
        }
        lines.push(t('ckeditor.dialog.model', 'Model: %s', result.model || unknown));
        lines.push(t('ckeditor.dialog.debug.finishReason', 'Finish reason: %s', result.finishReason || unknown));
        if (result.usage) {
            lines.push(t(
                'ckeditor.dialog.debug.tokens',
                'Tokens: %s prompt + %s completion = %s total',
                result.usage.promptTokens || 0,
                result.usage.completionTokens || 0,
                result.usage.totalTokens || 0,
            ));
        }

        // Show all messages sent to the LLM (system + user)
        if (result.debugMessages && Array.isArray(result.debugMessages)) {
            for (const msg of result.debugMessages) {
                lines.push('');
                lines.push(`--- [${msg.role}] ---`);
                lines.push(msg.content);
            }
        } else {
            // Fallback: show input text and instruction separately
            const truncatedInput = inputText.length > 500
                ? inputText.substring(0, 500) + '\u2026'
                : inputText;
            lines.push('');
            lines.push(heading('ckeditor.dialog.debug.inputText', 'Input text'));
            lines.push(truncatedInput);

            lines.push('');
            lines.push(heading('ckeditor.dialog.debug.instructionSent', 'Instruction sent'));
            lines.push(instructionSent);
        }

        if (result.thinking) {
            lines.push('');
            lines.push(heading('ckeditor.dialog.debug.thinking', 'Thinking'));
            lines.push(this._decodeEntities(result.thinking));
        }

        lines.push('');
        lines.push(heading('ckeditor.dialog.debug.contentReturned', 'Content returned'));
        lines.push(this._decodeEntities(result.content || ''));

        content.textContent = lines.join('\n');
        details.appendChild(content);

        // Copy button inside the details
        const copyLabel = t('ckeditor.dialog.copy', 'Copy to clipboard');
        const copyBtn = document.createElement('button');
        copyBtn.type = 'button';
        copyBtn.className = 'btn btn-sm btn-default mt-1';
        copyBtn.dataset.role = 'debug-copy';
        copyBtn.textContent = copyLabel;
        copyBtn.addEventListener('click', () => {
            const text = content.textContent;
            // The click happens in the modal's document, which may be the
            // parent window's: only that document has focus, and the
            // clipboard refuses a document without it.
            const doc = copyBtn.ownerDocument;
            const clipboard = doc.defaultView?.navigator.clipboard ?? navigator.clipboard;
            const copied = () => {
                copyBtn.textContent = t('ckeditor.dialog.copied', 'Copied!');
                setTimeout(() => { copyBtn.textContent = copyLabel; }, 2000);
            };
            // Without a secure context (plain http) there is no clipboard API
            // at all; go straight to the fallback.
            const write = clipboard
                ? clipboard.writeText(text)
                : Promise.reject(new Error('Clipboard API unavailable'));
            write.then(copied).catch(() => {
                const ta = doc.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                // Inside the modal: a modal <dialog> makes the rest inert.
                copyBtn.after(ta);
                try {
                    ta.select();
                    // Browsers report a refused copy by returning false, older
                    // ones by throwing. On false the label stays as it is.
                    if (doc.execCommand('copy')) {
                        copied();
                    }
                } catch {
                    // execCommand threw: copying is not possible here, and
                    // the label stays as it is.
                } finally {
                    ta.remove();
                    copyBtn.focus();
                }
            });
        });
        details.appendChild(copyBtn);

        const modelInfo = container.querySelector('[data-role="model-info"]');
        modelInfo.parentNode.insertBefore(details, modelInfo.nextSibling);
    }

    /**
     * Enable or disable modal buttons during loading.
     * The Cancel button always remains enabled so the user can abort.
     *
     * @param {object} modal
     * @param {boolean} disabled
     * @private
     */
    _setButtonsDisabled(modal, disabled) {
        modal?.querySelectorAll?.('.btn')?.forEach((btn) => {
            if (btn.getAttribute('name') === 'cancel') return;
            btn.disabled = disabled;
        });
    }
}
