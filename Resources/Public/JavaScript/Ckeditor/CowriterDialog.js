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
 */

/**
 * Context scope identifiers used for backend API calls.
 * @type {readonly string[]}
 */
const SCOPE_IDS = ['selection', 'text', 'element', 'page', 'ancestors_1', 'ancestors_2'];

/**
 * Human-readable scope labels for the dropdown UI.
 * @type {readonly string[]}
 */
const SCOPE_LABELS = ['Selection', 'Full content', 'Content element', 'Page content', 'Parent page', 'Grandparent page'];

let formIdCounter = 0;
let cssInjected = false;

/**
 * Inject cowriter-result styles once into the document head.
 * @private
 */
function injectStyles() {
    if (cssInjected) return;
    const style = document.createElement('style');
    style.textContent = `
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
}`;
    document.head.appendChild(style);
    cssInjected = true;
}

export class CowriterDialog {
    /** @type {import('./AIService.js').AIService} */
    _service;

    /**
     * @param {import('./AIService.js').AIService} service
     */
    constructor(service) {
        this._service = service;
    }

    /**
     * Show the cowriter task dialog.
     *
     * @param {string} selectedText - Currently selected text in the editor
     * @param {string} fullContent - Full content of the editor
     * @param {string} [editorCapabilities=''] - Available editor formatting features
     * @param {{table: string, uid: number, field: string}|null} [recordContext=null] - Record context for DB lookups
     * @returns {Promise<DialogResult>} Resolves with content to insert, rejects on cancel
     */
    async show(selectedText, fullContent, editorCapabilities = '', recordContext = null) {
        let tasks;
        try {
            const response = await this._service.getTasks();
            tasks = response.tasks || [];
        } catch (error) {
            throw new Error(`Failed to load tasks: ${error.message}`);
        }

        if (tasks.length === 0) {
            return this._showNoTasksModal();
        }

        return this._showModal(tasks, selectedText, fullContent, editorCapabilities, recordContext);
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
            heading.textContent = 'No tasks configured';
            alert.appendChild(heading);

            const desc = document.createElement('p');
            desc.textContent = 'The Cowriter needs at least one active task with '
                + 'category "content" to work. Tasks define what the AI should '
                + 'do with your text (e.g., improve, summarize, translate).';
            alert.appendChild(desc);

            alert.appendChild(document.createElement('hr'));

            const steps = document.createElement('p');
            steps.className = 'mb-0';
            const stepsLines = [
                'To set up tasks:',
                '1. Go to Admin Tools \u2192 LLM \u2192 Tasks',
                '2. Create tasks with category "content"',
                '3. Make sure they are active',
            ];
            stepsLines.forEach((line, i) => {
                if (i > 0) steps.appendChild(document.createElement('br'));
                const bold = (i === 1) ? 'Admin Tools \u2192 LLM \u2192 Tasks'
                    : (i === 2) ? '"content"'
                        : (i === 3) ? 'active' : null;
                if (bold) {
                    const text = line.split(bold);
                    steps.appendChild(document.createTextNode(text[0]));
                    const strong = document.createElement('strong');
                    strong.textContent = bold;
                    steps.appendChild(strong);
                    if (text[1]) steps.appendChild(document.createTextNode(text[1]));
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
                        text: 'Close',
                        btnClass: 'btn-default',
                        name: 'close',
                        icon: 'actions-close',
                        trigger: () => {
                            modal.hideModal();
                            reject(new Error('User cancelled'));
                        },
                    },
                ],
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
     * @returns {Promise<DialogResult>}
     * @private
     */
    _showModal(tasks, selectedText, fullContent, editorCapabilities, recordContext) {
        /** @type {Set<AbortController>} Track reference row listeners for cleanup */
        const referenceAbortControllers = new Set();
        const container = this._buildDialogContent(tasks, selectedText, fullContent, recordContext, referenceAbortControllers);
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
                preview.appendChild(document.createTextNode('Generating\u2026'));
                preview.classList.remove('cowriter-result--empty');
                modelInfo.style.display = 'none';

                try {
                    const taskUid = parseInt(
                        container.querySelector('[data-role="task-select"]').value, 10,
                    );
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

                    const inputText = currentContext;
                    activeRequest = new AbortController();
                    const result = await this._service.executeTask(
                        taskUid, currentContext, contextType, instruction, editorCapabilities,
                        contextScope, recordContext, referencePages, activeRequest.signal,
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
                            let infoText = `Model: ${result.model}`;
                            if (result.usage && result.usage.totalTokens) {
                                infoText += ` | ${result.usage.totalTokens} tokens`;
                            }
                            modelInfo.textContent = infoText;
                            modelInfo.style.display = 'block';
                        }

                        this._showDebugDetails(container, result, inputText, instruction);
                        this._updateButtonVisibility(modal, 'result');
                    } else {
                        preview.textContent = result.error || 'No content returned';
                        preview.classList.add('cowriter-result--empty');
                        this._showDebugDetails(container, result, inputText, instruction);
                        resultContent = '';
                        state = 'idle';
                        this._updateButtonVisibility(modal, 'idle');
                    }
                } catch (error) {
                    activeRequest = null;
                    if (error?.name === 'AbortError') return; // Request was cancelled
                    preview.textContent = `Error: ${error.message}`;
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
                    { text: 'Cancel', btnClass: 'btn-default', name: 'cancel', icon: 'actions-close', trigger: cancelTrigger },
                    { text: 'Reset', btnClass: 'btn-default', name: 'reset', icon: 'actions-undo', trigger: resetTrigger },
                    { text: 'Execute', btnClass: 'btn-default', name: 'execute', icon: 'actions-play', trigger: executeTrigger },
                    { text: 'Insert', btnClass: 'btn-primary', name: 'insert', icon: 'actions-insert', trigger: insertTrigger },
                ],
            });

            // Handle modal dismissal via Escape key or X button (Bootstrap hidden event)
            modal?.addEventListener?.('typo3-modal-hidden', () => {
                if (!resolved) {
                    activeRequest?.abort();
                    for (const ac of referenceAbortControllers) ac.abort();
                    referenceAbortControllers.clear();
                    reject(new Error('User cancelled'));
                }
            });

            this._updateButtonVisibility(modal, 'idle');
        });
    }

    /**
     * Build dialog content using native DOM.
     *
     * @param {TaskItem[]} tasks
     * @param {string} selectedText
     * @param {string} fullContent
     * @param {{table: string, uid: number, field: string}|null} recordContext
     * @returns {HTMLElement}
     * @private
     */
    _buildDialogContent(tasks, selectedText, fullContent, recordContext, referenceAbortControllers) {
        injectStyles();
        const container = document.createElement('div');
        container.className = 'cowriter-dialog';
        const idPrefix = `cowriter-${++formIdCounter}`;

        // Task selector
        const taskSelectId = `${idPrefix}-task`;
        const taskGroup = this._createFormGroup('Task', taskSelectId);
        const taskSelect = document.createElement('select');
        taskSelect.className = 'form-select';
        taskSelect.id = taskSelectId;
        taskSelect.dataset.role = 'task-select';

        // "Custom instruction" option first
        const customOption = document.createElement('option');
        customOption.value = '0';
        customOption.textContent = 'Custom instruction';
        customOption.dataset.description = 'Write your own instruction for the AI';
        customOption.dataset.promptTemplate = '';
        taskSelect.appendChild(customOption);

        for (const task of tasks) {
            const option = document.createElement('option');
            option.value = String(task.uid);
            option.textContent = task.name;
            option.dataset.description = task.description || '';
            option.dataset.promptTemplate = task.promptTemplate || '';
            taskSelect.appendChild(option);
        }

        // Select first real task by default if tasks exist
        if (tasks.length > 0) {
            taskSelect.value = String(tasks[0].uid);
        }
        taskGroup.appendChild(taskSelect);

        const taskDesc = document.createElement('div');
        taskDesc.className = 'form-text text-body-secondary';
        taskDesc.dataset.role = 'task-description';
        const selectedOption = taskSelect.options[taskSelect.selectedIndex];
        taskDesc.textContent = selectedOption?.dataset.description || '';
        taskGroup.appendChild(taskDesc);

        // Context scope dropdown
        const scopeId = `${idPrefix}-scope`;
        const contextGroup = this._createFormGroup('Context scope', scopeId);
        const hasSelection = Boolean(selectedText && selectedText.trim().length > 0);

        const scopeSelect = document.createElement('select');
        scopeSelect.className = 'form-select';
        scopeSelect.id = scopeId;
        scopeSelect.dataset.role = 'scope-select';

        for (let i = 0; i < SCOPE_IDS.length; i++) {
            const opt = document.createElement('option');
            opt.value = SCOPE_IDS[i];
            opt.textContent = SCOPE_LABELS[i];
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

        // Config row: task + scope side by side
        const configRow = document.createElement('div');
        configRow.className = 'row mb-3';
        configRow.dataset.role = 'config-row';

        const taskCol = document.createElement('div');
        taskCol.className = 'col-md-8';
        taskCol.appendChild(taskGroup);

        const scopeCol = document.createElement('div');
        scopeCol.className = 'col-md-4';
        scopeCol.appendChild(contextGroup);

        configRow.appendChild(taskCol);
        configRow.appendChild(scopeCol);
        container.appendChild(configRow);

        // Reference pages section
        const refGroup = this._createFormGroup('Reference pages (optional)');

        const refContainer = document.createElement('div');
        refContainer.dataset.role = 'reference-list';
        refGroup.appendChild(refContainer);

        const addRefBtn = document.createElement('button');
        addRefBtn.type = 'button';
        addRefBtn.className = 'btn btn-sm btn-outline-secondary mt-1';
        addRefBtn.dataset.role = 'add-reference';
        addRefBtn.innerHTML = '<typo3-backend-icon identifier="actions-plus" size="small"></typo3-backend-icon> Add reference page';
        addRefBtn.addEventListener('click', () => {
            refContainer.appendChild(this._createReferenceRow(referenceAbortControllers));
        });
        refGroup.appendChild(addRefBtn);

        container.appendChild(refGroup);

        // Relation presets datalist
        if (!document.getElementById('cowriter-relation-presets')) {
            const datalist = document.createElement('datalist');
            datalist.id = 'cowriter-relation-presets';
            for (const preset of ['reference material', 'parent topic', 'style guide', 'similar content']) {
                const option = document.createElement('option');
                option.value = preset;
                datalist.appendChild(option);
            }
            container.appendChild(datalist);
        }

        // Instruction textarea (primary input)
        const instructionId = `${idPrefix}-instruction`;
        const instructionGroup = this._createFormGroup('Instruction', instructionId);
        const instructionInput = document.createElement('textarea');
        instructionInput.className = 'form-control';
        instructionInput.id = instructionId;
        instructionInput.dataset.role = 'instruction';
        instructionInput.rows = 10;
        instructionInput.placeholder = 'Describe what the AI should do\u2026';

        // Prefill with resolved template from the initially selected task
        const initialTemplate = selectedOption?.dataset.promptTemplate || '';
        if (initialTemplate) {
            instructionInput.value = this._resolveTemplate(initialTemplate);
        }
        instructionGroup.appendChild(instructionInput);

        // Task change handler: prefill instruction with resolved template
        taskSelect.addEventListener('change', () => {
            const selected = taskSelect.options[taskSelect.selectedIndex];
            taskDesc.textContent = selected?.dataset.description || '';
            const template = selected?.dataset.promptTemplate || '';
            instructionInput.value = template
                ? this._resolveTemplate(template)
                : '';
        });

        // Result preview
        const resultGroup = this._createFormGroup('Result');
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
     * @returns {HTMLElement}
     * @private
     */
    _createReferenceRow(referenceAbortControllers) {
        const row = document.createElement('div');
        row.className = 'd-flex gap-2 mb-1 align-items-center';
        row.dataset.role = 'reference-row';

        // Wrapper for search input + dropdown
        const searchWrapper = document.createElement('div');
        searchWrapper.className = 'position-relative';
        searchWrapper.style.minWidth = '250px';

        const dropdownId = 'cowriter-ref-dropdown-' + Math.random().toString(36).slice(2, 9);

        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.className = 'form-control form-control-sm';
        searchInput.dataset.role = 'ref-search';
        searchInput.placeholder = 'Search pages by title or ID...';
        searchInput.setAttribute('aria-label', 'Search pages by title or ID');
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
        dropdown.style.zIndex = '1050';
        dropdown.style.maxHeight = '200px';
        dropdown.style.overflowY = 'auto';
        dropdown.style.display = 'none';
        dropdown.dataset.role = 'ref-dropdown';
        searchWrapper.appendChild(dropdown);

        // AbortController for cleanup of event listeners and in-flight requests
        const controller = new AbortController();
        referenceAbortControllers?.add(controller);

        // Debounced search with stale-response guard
        let debounceTimer;
        let currentSearchId = 0;
        searchInput.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            hiddenPid.value = '';  // Clear selection on new input
            const query = searchInput.value.trim();
            if (query.length < 2) {
                dropdown.style.display = 'none';
                searchInput.setAttribute('aria-expanded', 'false');
                return;
            }
            const searchId = ++currentSearchId;
            debounceTimer = setTimeout(async () => {
                try {
                    const result = await this._service.searchPages(query);
                    if (searchId !== currentSearchId) return; // discard stale response
                    this._renderPageDropdown(dropdown, result.pages, searchInput, hiddenPid);
                } catch (e) {
                    if (searchId === currentSearchId) {
                        dropdown.style.display = 'none';
                        searchInput.setAttribute('aria-expanded', 'false');
                    }
                }
            }, 300);
        }, { signal: controller.signal });

        // Keyboard navigation for dropdown
        searchInput.addEventListener('keydown', (e) => {
            const items = dropdown.querySelectorAll('[role="option"]');
            if (!items.length || dropdown.style.display === 'none') {
                if (e.key === 'Escape') {
                    dropdown.style.display = 'none';
                    searchInput.setAttribute('aria-expanded', 'false');
                }
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
            } else if (e.key === 'Escape') {
                dropdown.style.display = 'none';
                searchInput.setAttribute('aria-expanded', 'false');
                searchInput.removeAttribute('aria-activedescendant');
            }
        }, { signal: controller.signal });

        // Close dropdown on outside click (scoped to AbortController)
        document.addEventListener('click', (e) => {
            if (!searchWrapper.contains(e.target)) {
                dropdown.style.display = 'none';
                searchInput.setAttribute('aria-expanded', 'false');
            }
        }, { signal: controller.signal });

        row.appendChild(searchWrapper);

        const relationInput = document.createElement('input');
        relationInput.type = 'text';
        relationInput.className = 'form-control form-control-sm';
        relationInput.dataset.role = 'ref-relation';
        relationInput.placeholder = 'Relation (e.g., style guide)';
        relationInput.setAttribute('aria-label', 'Relation to reference page');
        relationInput.setAttribute('list', 'cowriter-relation-presets');
        row.appendChild(relationInput);

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-sm btn-outline-danger';
        removeBtn.dataset.role = 'remove-reference';
        removeBtn.innerHTML = '<typo3-backend-icon identifier="actions-delete" size="small"></typo3-backend-icon>';
        removeBtn.setAttribute('aria-label', 'Remove reference page');
        removeBtn.addEventListener('click', () => {
            clearTimeout(debounceTimer);
            controller.abort();
            referenceAbortControllers?.delete(controller);
            row.remove();
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
     * @private
     */
    _renderPageDropdown(dropdown, pages, searchInput, hiddenPid) {
        dropdown.replaceChildren();
        searchInput.removeAttribute('aria-activedescendant');
        if (!pages || pages.length === 0) {
            dropdown.setAttribute('role', 'listbox');
            const item = document.createElement('div');
            item.className = 'list-group-item list-group-item-light text-muted small';
            item.textContent = 'No pages found';
            item.setAttribute('role', 'status');
            dropdown.appendChild(item);
            dropdown.style.display = 'block';
            searchInput.setAttribute('aria-expanded', 'true');
            return;
        }
        dropdown.setAttribute('role', 'listbox');
        const prefix = dropdown.id || 'ref-opt';
        for (let i = 0; i < pages.length; i++) {
            const page = pages[i];
            const item = document.createElement('button');
            item.type = 'button';
            item.id = `${prefix}-${i}`;
            item.className = 'list-group-item list-group-item-action small';
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', 'false');
            item.style.overflow = 'hidden';
            item.style.textOverflow = 'ellipsis';
            item.style.whiteSpace = 'nowrap';
            item.textContent = `[${page.uid}] ${page.title}`;
            if (page.slug) {
                const slug = document.createElement('span');
                slug.className = 'text-muted ms-1';
                slug.textContent = page.slug;
                item.appendChild(slug);
            }
            item.addEventListener('click', () => {
                hiddenPid.value = String(page.uid);
                searchInput.value = `[${page.uid}] ${page.title}`;
                dropdown.style.display = 'none';
                searchInput.setAttribute('aria-expanded', 'false');
                searchInput.removeAttribute('aria-activedescendant');
            });
            dropdown.appendChild(item);
        }
        dropdown.style.display = 'block';
        searchInput.setAttribute('aria-expanded', 'true');
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
        textarea.innerHTML = escaped; // eslint-disable-line no-unsanitized/property -- textarea never executes scripts
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
     * Show or hide Reset and Insert buttons based on dialog state.
     *
     * @param {object} modal
     * @param {'idle'|'loading'|'result'} state
     * @private
     */
    _updateButtonVisibility(modal, state) {
        const reset = modal?.querySelector?.('[data-name="reset"]');
        const insert = modal?.querySelector?.('[data-name="insert"]');
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
        summary.textContent = 'Debug info';
        details.appendChild(summary);

        const content = document.createElement('div');
        content.className = 'mt-1';
        content.style.fontSize = '0.8rem';
        content.style.fontFamily = 'monospace';
        content.style.whiteSpace = 'pre-wrap';
        content.style.wordBreak = 'break-word';

        const lines = [];
        if (result.error) {
            lines.push(`Error: ${result.error}`);
        }
        if (result.debugError) {
            lines.push(`Provider error: ${result.debugError}`);
        }
        lines.push(`Model: ${result.model || 'unknown'}`);
        lines.push(`Finish reason: ${result.finishReason || 'unknown'}`);
        if (result.usage) {
            lines.push(`Tokens: ${result.usage.promptTokens || 0} prompt`
                + ` + ${result.usage.completionTokens || 0} completion`
                + ` = ${result.usage.totalTokens || 0} total`);
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
            lines.push('--- Input text ---');
            lines.push(truncatedInput);

            lines.push('');
            lines.push('--- Instruction sent ---');
            lines.push(instructionSent);
        }

        if (result.thinking) {
            lines.push('');
            lines.push('--- Thinking ---');
            lines.push(this._decodeEntities(result.thinking));
        }

        lines.push('');
        lines.push('--- Content returned ---');
        lines.push(this._decodeEntities(result.content || ''));

        content.textContent = lines.join('\n');
        details.appendChild(content);

        // Copy button inside the details
        const copyBtn = document.createElement('button');
        copyBtn.type = 'button';
        copyBtn.className = 'btn btn-sm btn-outline-secondary mt-1';
        copyBtn.dataset.role = 'debug-copy';
        copyBtn.textContent = 'Copy to clipboard';
        copyBtn.addEventListener('click', () => {
            const text = content.textContent;
            navigator.clipboard.writeText(text).then(() => {
                copyBtn.textContent = 'Copied!';
                setTimeout(() => { copyBtn.textContent = 'Copy to clipboard'; }, 2000);
            }).catch(() => {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                ta.remove();
                copyBtn.textContent = 'Copied!';
                setTimeout(() => { copyBtn.textContent = 'Copy to clipboard'; }, 2000);
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
            if (btn.dataset.name === 'cancel') return;
            btn.disabled = disabled;
        });
    }
}
