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
 */

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
        const container = this._buildDialogContent(tasks, selectedText, fullContent, recordContext);
        /** @type {'idle'|'loading'|'result'} */
        let state = 'idle';
        let resultContent = '';

        return new Promise((resolve, reject) => {
            let modal;
            let resolved = false;

            const cancelTrigger = () => {
                modal.hideModal();
                if (!resolved) {
                    reject(new Error('User cancelled'));
                }
            };

            const executeTrigger = async () => {
                if (state === 'loading') return;

                // Insert mode — result is ready
                if (state === 'result' && resultContent) {
                    resolved = true;
                    modal.hideModal();
                    resolve({ content: resultContent });
                    return;
                }

                // Execute mode — run the task
                state = 'loading';
                this._setButtonsDisabled(modal, true);

                const preview = container.querySelector('[data-role="result-preview"]');
                const modelInfo = container.querySelector('[data-role="model-info"]');
                preview.style.display = 'block';
                preview.textContent = 'Generating\u2026';
                modelInfo.style.display = 'none';

                try {
                    const taskUid = parseInt(
                        container.querySelector('[data-role="task-select"]').value, 10,
                    );
                    const slider = container.querySelector('[data-role="context-slider"]');
                    const scopeIndex = parseInt(slider.value, 10);
                    const scopes = [
                        'selection', 'text', 'element', 'page', 'ancestors_1', 'ancestors_2',
                    ];
                    const contextScope = scopes[scopeIndex] || 'selection';
                    const contextType = scopeIndex === 0 ? 'selection' : 'content_element';
                    const context = contextType === 'selection'
                        ? selectedText
                        : fullContent;
                    const adHocRules = container.querySelector(
                        '[data-role="ad-hoc-rules"]',
                    ).value.trim();

                    // Collect reference pages
                    const refRows = container.querySelectorAll('[data-role="reference-row"]');
                    const referencePages = [];
                    for (const row of refRows) {
                        const pid = parseInt(row.querySelector('[data-role="ref-pid"]')?.value, 10);
                        const relation = row.querySelector('[data-role="ref-relation"]')?.value?.trim() || '';
                        if (pid > 0) {
                            referencePages.push({ pid, relation });
                        }
                    }

                    const result = await this._service.executeTask(
                        taskUid, context, contextType, adHocRules, editorCapabilities,
                        contextScope, recordContext, referencePages,
                    );

                    if (result.success && result.content) {
                        preview.replaceChildren();
                        const safeBody = this._sanitizeHtml(result.content);
                        while (safeBody.firstChild) {
                            preview.appendChild(safeBody.firstChild);
                        }
                        resultContent = result.content;
                        state = 'result';

                        if (result.model) {
                            modelInfo.textContent = `Model: ${result.model}`;
                            modelInfo.style.display = 'block';
                        }

                        this._updateExecuteButtonText(modal, 'Insert');
                    } else {
                        preview.textContent = result.error || 'No content returned';
                        resultContent = '';
                        state = 'idle';
                    }
                } catch (error) {
                    preview.textContent = `Error: ${error.message}`;
                    resultContent = '';
                    state = 'idle';
                }

                this._setButtonsDisabled(modal, false);
            };

            modal = Modal.advanced({
                title: 'Cowriter',
                content: container,
                size: Modal.sizes.medium,
                buttons: [
                    { text: 'Cancel', btnClass: 'btn-default', name: 'cancel', trigger: cancelTrigger },
                    { text: 'Execute', btnClass: 'btn-primary', name: 'execute', trigger: executeTrigger },
                ],
            });

            // Reset state when inputs change so the user can re-execute
            const resetResult = () => {
                if (state === 'result') {
                    state = 'idle';
                    resultContent = '';
                    this._updateExecuteButtonText(modal, 'Execute');
                }
            };
            container.querySelector('[data-role="task-select"]')?.addEventListener('change', resetResult);
            container.querySelector('[data-role="context-slider"]')?.addEventListener('input', resetResult);
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
    _buildDialogContent(tasks, selectedText, fullContent, recordContext) {
        const container = document.createElement('div');
        container.className = 'cowriter-dialog';

        // Task selector
        const taskGroup = this._createFormGroup('Task');
        const taskSelect = document.createElement('select');
        taskSelect.className = 'form-select';
        taskSelect.dataset.role = 'task-select';
        for (const task of tasks) {
            const option = document.createElement('option');
            option.value = String(task.uid);
            option.textContent = task.name;
            option.dataset.description = task.description || '';
            taskSelect.appendChild(option);
        }
        taskGroup.appendChild(taskSelect);

        const taskDesc = document.createElement('div');
        taskDesc.className = 'form-text text-body-secondary';
        taskDesc.dataset.role = 'task-description';
        taskDesc.textContent = tasks[0]?.description || '';
        taskGroup.appendChild(taskDesc);

        taskSelect.addEventListener('change', () => {
            const selected = taskSelect.options[taskSelect.selectedIndex];
            taskDesc.textContent = selected?.dataset.description || '';
        });
        container.appendChild(taskGroup);

        // Context zoom slider (replaces radio buttons)
        const contextGroup = this._createFormGroup('Context scope');
        const hasSelection = Boolean(selectedText && selectedText.trim().length > 0);

        const slider = document.createElement('input');
        slider.type = 'range';
        slider.className = 'form-range';
        slider.dataset.role = 'context-slider';
        slider.min = hasSelection ? '0' : '1';
        slider.max = '5';
        slider.value = hasSelection ? '0' : '1';
        slider.step = '1';
        contextGroup.appendChild(slider);

        // Tick labels
        const tickLabels = document.createElement('div');
        tickLabels.className = 'd-flex justify-content-between';
        tickLabels.style.fontSize = '0.75rem';
        const labels = ['Selection', 'Text', 'Element', 'Page', '+1 level', '+2 levels'];
        for (const label of labels) {
            const span = document.createElement('span');
            span.textContent = label;
            span.style.flex = '1';
            span.style.textAlign = 'center';
            tickLabels.appendChild(span);
        }
        contextGroup.appendChild(tickLabels);

        // Scope summary label
        const scopeLabel = document.createElement('div');
        scopeLabel.className = 'form-text text-body-secondary mt-1';
        scopeLabel.dataset.role = 'scope-label';
        scopeLabel.textContent = hasSelection ? 'Selection' : 'Text';
        contextGroup.appendChild(scopeLabel);

        // Slider change handler
        slider.addEventListener('input', () => {
            const index = parseInt(slider.value, 10);
            const scopeNames = [
                'Selection', 'Text', 'Element', 'Page',
                '+1 ancestor level', '+2 ancestor levels',
            ];
            scopeLabel.textContent = scopeNames[index] || '';

            // Fetch preview for scopes that need DB lookup
            const scopes = [
                'selection', 'text', 'element', 'page', 'ancestors_1', 'ancestors_2',
            ];
            if (index >= 2 && recordContext && this._service.getContext) {
                this._service.getContext(
                    recordContext.table, recordContext.uid, recordContext.field, scopes[index],
                ).then((result) => {
                    if (result.success) {
                        scopeLabel.textContent = `${scopeNames[index]} (${result.summary})`;
                    }
                }).catch(() => {
                    // Silently ignore preview errors — the scope label still shows
                });
            }
        });

        container.appendChild(contextGroup);

        // Reference pages section
        const refGroup = this._createFormGroup('Reference pages (optional)');

        const refContainer = document.createElement('div');
        refContainer.dataset.role = 'reference-list';
        refGroup.appendChild(refContainer);

        const addRefBtn = document.createElement('button');
        addRefBtn.type = 'button';
        addRefBtn.className = 'btn btn-sm btn-outline-secondary mt-1';
        addRefBtn.dataset.role = 'add-reference';
        addRefBtn.textContent = '+ Add reference page';
        addRefBtn.addEventListener('click', () => {
            refContainer.appendChild(this._createReferenceRow());
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

        // Ad-hoc rules
        const rulesGroup = this._createFormGroup('Additional instructions (optional)');
        const rulesInput = document.createElement('textarea');
        rulesInput.className = 'form-control';
        rulesInput.dataset.role = 'ad-hoc-rules';
        rulesInput.rows = 2;
        rulesInput.placeholder = 'e.g., Write in formal tone, keep it under 100 words\u2026';
        rulesGroup.appendChild(rulesInput);
        container.appendChild(rulesGroup);

        // Result preview
        const previewGroup = this._createFormGroup('Result');
        const preview = document.createElement('div');
        preview.className = 'cowriter-preview form-control';
        preview.dataset.role = 'result-preview';
        preview.style.minHeight = '80px';
        preview.style.display = 'none';
        previewGroup.appendChild(preview);

        const modelInfo = document.createElement('small');
        modelInfo.className = 'form-text text-body-secondary';
        modelInfo.dataset.role = 'model-info';
        modelInfo.style.display = 'none';
        previewGroup.appendChild(modelInfo);
        container.appendChild(previewGroup);

        return container;
    }

    /**
     * @param {string} label
     * @returns {HTMLElement}
     * @private
     */
    _createFormGroup(label) {
        const group = document.createElement('div');
        group.className = 'mb-3';
        const labelEl = document.createElement('label');
        labelEl.className = 'form-label fw-bold';
        labelEl.textContent = label;
        group.appendChild(labelEl);
        return group;
    }

    /**
     * Create a reference page row with page ID, relation, and remove button.
     * @returns {HTMLElement}
     * @private
     */
    _createReferenceRow() {
        const row = document.createElement('div');
        row.className = 'd-flex gap-2 mb-1 align-items-center';
        row.dataset.role = 'reference-row';

        const pidInput = document.createElement('input');
        pidInput.type = 'number';
        pidInput.className = 'form-control form-control-sm';
        pidInput.dataset.role = 'ref-pid';
        pidInput.placeholder = 'Page ID';
        pidInput.style.width = '100px';
        row.appendChild(pidInput);

        const relationInput = document.createElement('input');
        relationInput.type = 'text';
        relationInput.className = 'form-control form-control-sm';
        relationInput.dataset.role = 'ref-relation';
        relationInput.placeholder = 'Relation (e.g., style guide, reference)';
        relationInput.setAttribute('list', 'cowriter-relation-presets');
        row.appendChild(relationInput);

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-sm btn-outline-danger';
        removeBtn.dataset.role = 'remove-reference';
        removeBtn.textContent = '\u2715';
        removeBtn.addEventListener('click', () => row.remove());
        row.appendChild(removeBtn);

        return row;
    }

    /**
     * Parse HTML string safely via DOMParser and remove dangerous elements/attributes.
     *
     * DOMParser does NOT execute scripts — parsed content is inert.
     * We additionally strip script/style/iframe/object/embed/form elements
     * and all on* event handler attributes.
     *
     * @param {string} html
     * @returns {HTMLElement} The sanitized body element
     * @private
     */
    _sanitizeHtml(html) {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        doc.querySelectorAll('script, style, iframe, object, embed, form')
            .forEach((el) => el.remove());
        doc.querySelectorAll('*').forEach((el) => {
            for (const attr of [...el.attributes]) {
                if (attr.name.startsWith('on')
                    || attr.value.toLowerCase().includes('javascript:')) {
                    el.removeAttribute(attr.name);
                }
            }
        });
        return doc.body;
    }

    /**
     * Update the text of the Execute/Insert button.
     *
     * @param {object} modal
     * @param {string} text
     * @private
     */
    _updateExecuteButtonText(modal, text) {
        const el = modal?.querySelector?.('.btn-primary');
        if (el) {
            el.textContent = text;
        }
    }

    /**
     * Enable or disable all modal buttons during loading.
     *
     * @param {object} modal
     * @param {boolean} disabled
     * @private
     */
    _setButtonsDisabled(modal, disabled) {
        modal?.querySelectorAll?.('.btn')?.forEach((btn) => {
            btn.disabled = disabled;
        });
    }
}
