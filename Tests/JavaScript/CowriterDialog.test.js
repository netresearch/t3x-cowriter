/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

/**
 * Tests for CowriterDialog module.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// Setup TYPO3 global before importing module
let TYPO3Mock;

describe('CowriterDialog', () => {
    let CowriterDialog;
    let mockService;

    const sampleTasks = [
        {
            uid: 1, identifier: 'cowriter_improve', name: 'Improve Text',
            description: 'Enhance readability', promptTemplate: 'Improve: {{input}}',
        },
        {
            uid: 2, identifier: 'cowriter_summarize', name: 'Summarize',
            description: 'Create a summary', promptTemplate: 'Summarize: {{input}}',
        },
        {
            uid: 3, identifier: 'cowriter_translate_en', name: 'Translate to English',
            description: 'Translate content', promptTemplate: 'Translate to English: {{input}}',
        },
    ];

    beforeEach(async () => {
        // Reset DOM
        while (document.body.firstChild) {
            document.body.removeChild(document.body.firstChild);
        }

        TYPO3Mock = {
            settings: {
                ajaxUrls: {
                    tx_cowriter_tasks: '/typo3/ajax/tx_cowriter_tasks',
                    tx_cowriter_task_execute: '/typo3/ajax/tx_cowriter_task_execute',
                },
            },
        };
        globalThis.TYPO3 = TYPO3Mock;

        vi.resetModules();

        const module = await import(
            '../../Resources/Public/JavaScript/Ckeditor/CowriterDialog.js'
        );
        CowriterDialog = module.CowriterDialog;

        mockService = {
            getTasks: vi.fn().mockResolvedValue({ success: true, tasks: sampleTasks }),
            executeTask: vi.fn().mockResolvedValue({
                success: true,
                content: 'Improved text content',
                model: 'gpt-4o',
            }),
            searchPages: vi.fn().mockResolvedValue({ success: true, pages: [] }),
            getModuleUrl(key) { return this._routes?.[key] || null; },
        };
    });

    afterEach(() => {
        delete globalThis.TYPO3;
        vi.resetModules();
    });

    describe('labels', () => {
        function injectLabels(labels) {
            const element = document.createElement('script');
            element.type = 'application/json';
            element.id = 'cowriter-labels-data';
            element.textContent = JSON.stringify(labels);
            document.head.appendChild(element);
        }

        afterEach(() => {
            document.getElementById('cowriter-labels-data')?.remove();
        });

        it('uses the English texts when the backend injected no labels', async () => {
            mockService.getTasks.mockResolvedValue({ success: true, tasks: [] });
            const showPromise = new CowriterDialog(mockService).show('text', 'full');
            await vi.waitFor(() => expect(document.querySelector('.alert-info')).not.toBeNull());

            const bold = [...document.querySelectorAll('.alert-info strong')].map((el) => el.textContent);
            expect(bold).toEqual(['Admin Tools \u2192 LLM \u2192 Tasks', '"content"', 'active']);
            expect(document.querySelector('.alert-info p.mb-0').textContent).toContain('3. Make sure they are active');
            expect(document.querySelector('[name="close"]').textContent).toBe('Close');

            document.querySelector('[name="close"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('uses the injected labels for the setup guidance', async () => {
            injectLabels({
                'ckeditor.tasks.none.title': 'Keine Aufgaben konfiguriert',
                'ckeditor.dialog.noTasks.step1': '1. Öffnen Sie %s',
                'ckeditor.dialog.noTasks.step1.path': 'Admin-Werkzeuge \u2192 LLM \u2192 Aufgaben',
                'ckeditor.dialog.noTasks.step3': '3. Stellen Sie sicher, dass sie %s sind',
                'ckeditor.dialog.noTasks.step3.active': 'aktiv',
                'ckeditor.dialog.button.close': 'Schließen',
            });
            mockService.getTasks.mockResolvedValue({ success: true, tasks: [] });
            const showPromise = new CowriterDialog(mockService).show('text', 'full');
            await vi.waitFor(() => expect(document.querySelector('.alert-info')).not.toBeNull());

            const alert = document.querySelector('.alert-info');
            expect(alert.querySelector('h4').textContent).toBe('Keine Aufgaben konfiguriert');
            const bold = [...alert.querySelectorAll('strong')].map((el) => el.textContent);
            expect(bold).toEqual(['Admin-Werkzeuge \u2192 LLM \u2192 Aufgaben', '"content"', 'aktiv']);
            expect(alert.querySelector('p.mb-0').textContent).toContain('3. Stellen Sie sicher, dass sie aktiv sind');
            expect(document.querySelector('[name="close"]').textContent).toBe('Schließen');

            document.querySelector('[name="close"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('uses the injected labels for the task dialog', async () => {
            injectLabels({
                'ckeditor.dialog.button.cancel': 'Abbrechen',
                'ckeditor.dialog.button.execute': 'Ausführen',
                'ckeditor.dialog.task': 'Aufgabe',
                'ckeditor.dialog.customInstruction': 'Eigene Anweisung',
                'ckeditor.dialog.scope.selection': 'Auswahl',
                'ckeditor.dialog.addReference': 'Referenzseite hinzufügen',
                'ckeditor.dialog.instruction.placeholder': 'Beschreiben Sie, was die KI tun soll\u2026',
            });
            const showPromise = new CowriterDialog(mockService).show('selected', 'full');
            await vi.waitFor(() => expect(document.querySelector('[name="cancel"]')).not.toBeNull());

            expect(document.querySelector('[name="cancel"]').textContent).toBe('Abbrechen');
            expect(document.querySelector('[name="execute"]').textContent).toBe('Ausführen');
            // Not injected: the English text stays.
            expect(document.querySelector('[name="insert"]').textContent).toBe('Insert');
            expect(document.querySelector('label[for$="-task"]').textContent).toBe('Aufgabe');
            expect(document.querySelector('[data-role="task-select"] option[value="0"]').textContent).toBe('Eigene Anweisung');
            expect(document.querySelector('[data-role="scope-select"] option[value="selection"]').textContent).toBe('Auswahl');
            expect(document.querySelector('[data-role="add-reference"]').textContent.trim()).toBe('Referenzseite hinzufügen');
            expect(document.querySelector('[data-role="add-reference"] typo3-backend-icon')).not.toBeNull();
            expect(document.querySelector('[data-role="instruction"]').placeholder).toBe('Beschreiben Sie, was die KI tun soll\u2026');

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('fills the placeholder of an injected error label', async () => {
            injectLabels({ 'ckeditor.tasks.loadFailedWithReason': 'Aufgaben konnten nicht geladen werden: %s' });
            mockService.getTasks.mockRejectedValue(new Error('Netzwerkfehler'));

            await expect(new CowriterDialog(mockService).show('text', 'full'))
                .rejects.toThrow('Aufgaben konnten nicht geladen werden: Netzwerkfehler');
        });
    });

    describe('constructor', () => {
        it('should store service reference', () => {
            const dialog = new CowriterDialog(mockService);
            expect(dialog._service).toBe(mockService);
        });
    });

    describe('show()', () => {
        it('should fetch tasks on show', async () => {
            const dialog = new CowriterDialog(mockService);
            // We won't await the full promise since it requires user interaction.
            // Instead verify tasks are fetched and modal opens.
            const showPromise = dialog.show('selected text', 'full content');

            // Wait for tasks to be fetched
            await vi.waitFor(() => {
                expect(mockService.getTasks).toHaveBeenCalledOnce();
            });

            // Find and click cancel to resolve the promise
            const cancelBtn = document.querySelector('[name="cancel"]');
            cancelBtn.click();

            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should throw when tasks fail to load', async () => {
            mockService.getTasks.mockRejectedValue(new Error('Network error'));
            const dialog = new CowriterDialog(mockService);

            await expect(dialog.show('text', 'full')).rejects.toThrow('Failed to load tasks');
        });

        it('should show guidance modal when no tasks available', async () => {
            mockService.getTasks.mockResolvedValue({ success: true, tasks: [] });
            const dialog = new CowriterDialog(mockService);

            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('.alert-info')).not.toBeNull();
            });

            // Should show setup instructions
            const alert = document.querySelector('.alert-info');
            expect(alert.textContent).toContain('No tasks configured');
            expect(alert.textContent).toContain('Admin Tools');
            expect(alert.textContent).toContain('LLM');
            expect(alert.textContent).toContain('Tasks');
            expect(alert.textContent).toContain('content');

            // Should have only a Close button, no Execute
            expect(document.querySelector('[name="close"]')).not.toBeNull();
            expect(document.querySelector('[name="execute"]')).toBeNull();

            // Clicking Close should reject with User cancelled
            document.querySelector('[name="close"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should render task select with Custom option and task options', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="task-select"]')).not.toBeNull();
            });

            const select = document.querySelector('[data-role="task-select"]');
            // 1 custom + 3 tasks = 4 options
            expect(select.options.length).toBe(4);
            expect(select.options[0].textContent).toBe('Custom instruction');
            expect(select.options[0].value).toBe('0');
            expect(select.options[1].textContent).toBe('Improve Text');
            expect(select.options[1].value).toBe('1');
            expect(select.options[2].textContent).toBe('Summarize');

            // First real task should be selected by default
            expect(select.value).toBe('1');

            // Cleanup
            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should show first task description by default', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected', 'full');

            await vi.waitFor(() => {
                const desc = document.querySelector('[data-role="task-description"]');
                expect(desc).not.toBeNull();
                expect(desc.textContent).toBe('Enhance readability');
            });

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should pre-select task when preSelectedTaskUid matches', async () => {
            const dialog = new CowriterDialog(mockService);
            // Task uid 2 is "Summarize" in the mock data
            const showPromise = dialog.show('selected', 'full', '', null, 2);

            await vi.waitFor(() => {
                const select = document.querySelector('[data-role="task-select"]');
                expect(select).not.toBeNull();
                expect(select.value).toBe('2');
            });

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should fall back to first task when preSelectedTaskUid does not match', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected', 'full', '', null, 999);

            await vi.waitFor(() => {
                const select = document.querySelector('[data-role="task-select"]');
                expect(select).not.toBeNull();
                // Falls back to first task (uid 1)
                expect(select.value).toBe('1');
            });

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should show "Edit tasks" link when tasksModule route is available', async () => {
            mockService._routes = { tasksModule: '/typo3/module/nrllm/tasks' };
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected', 'full');

            await vi.waitFor(() => {
                const link = document.querySelector('a[target="_blank"]');
                expect(link).not.toBeNull();
                expect(link.textContent).toContain('Edit tasks');
                expect(link.href).toContain('/typo3/module/nrllm/tasks');
            });

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should not show "Edit tasks" link when tasksModule route is absent', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="task-description"]')).not.toBeNull();
            });

            const links = document.querySelectorAll('a[target="_blank"]');
            const editTasksLink = Array.from(links).find(l => l.textContent.includes('Edit tasks'));
            expect(editTasksLink).toBeUndefined();

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should update description when task changes', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="task-select"]')).not.toBeNull();
            });

            const select = document.querySelector('[data-role="task-select"]');
            select.value = '2';
            select.dispatchEvent(new Event('change'));

            const desc = document.querySelector('[data-role="task-description"]');
            expect(desc.textContent).toBe('Create a summary');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should have instruction textarea', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="instruction"]')).not.toBeNull();
            });

            const textarea = document.querySelector('[data-role="instruction"]');
            expect(textarea.tagName).toBe('TEXTAREA');
            expect(textarea.rows).toBe(10);
            expect(textarea.placeholder).toContain('Describe what the AI should do');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should prefill instruction with resolved template from selected task', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('my text', 'full content');

            await vi.waitFor(() => {
                const textarea = document.querySelector('[data-role="instruction"]');
                expect(textarea).not.toBeNull();
                // First real task is selected, template is "Improve: {{input}}" → {{input}} stripped
                expect(textarea.value).toBe('Improve:');
            });

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should update instruction when task changes', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('my text', 'full content');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="task-select"]')).not.toBeNull();
            });

            const select = document.querySelector('[data-role="task-select"]');
            select.value = '2';
            select.dispatchEvent(new Event('change'));

            const textarea = document.querySelector('[data-role="instruction"]');
            expect(textarea.value).toBe('Summarize:');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should clear instruction when Custom is selected', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('my text', 'full content');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="task-select"]')).not.toBeNull();
            });

            const select = document.querySelector('[data-role="task-select"]');
            select.value = '0'; // Custom
            select.dispatchEvent(new Event('change'));

            const textarea = document.querySelector('[data-role="instruction"]');
            expect(textarea.value).toBe('');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should use template as-is when no {{input}} placeholder', async () => {
            const tasksWithNoPlaceholder = [
                { uid: 10, identifier: 'plain_task', name: 'Plain Task',
                  description: 'No placeholder', promptTemplate: 'Just summarize the text' },
            ];
            mockService.getTasks.mockResolvedValue({ success: true, tasks: tasksWithNoPlaceholder });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const instruction = document.querySelector('[data-role="instruction"]');
            expect(instruction.value).toBe('Just summarize the text');

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should show original input text in result area in idle state', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('my selected text', 'full content');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const result = document.querySelector('[data-role="result-preview"]');
            expect(result).toBeTruthy();
            expect(result.style.display).not.toBe('none');
            expect(result.textContent).toContain('my selected text');
            expect(result.classList.contains('cowriter-result--empty')).toBe(true);

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should show full content in result area when no selection', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('', 'full content here');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const result = document.querySelector('[data-role="result-preview"]');
            expect(result.textContent).toContain('full content here');
            expect(result.classList.contains('cowriter-result--empty')).toBe(true);

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should remove muted class from result area after successful execute', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });

            const result = document.querySelector('[data-role="result-preview"]');
            expect(result.classList.contains('cowriter-result--empty')).toBe(false);

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should render config row with task and scope side by side', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const configRow = document.querySelector('[data-role="config-row"]');
            expect(configRow).toBeTruthy();
            expect(configRow.classList.contains('row')).toBe(true);

            // The service offers no configurations, so the picker column stays hidden.
            const cols = configRow.querySelectorAll(':scope > [class*="col-md-"]:not([hidden])');
            expect(cols.length).toBe(2);
            expect(cols[0].classList.contains('col-md-8')).toBe(true);
            expect(cols[1].classList.contains('col-md-4')).toBe(true);

            expect(cols[0].querySelector('[data-role="task-select"]')).toBeTruthy();
            expect(cols[1].querySelector('[data-role="scope-select"]')).toBeTruthy();

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should render work area with instruction and result side by side', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const workRow = document.querySelector('[data-role="work-row"]');
            expect(workRow).toBeTruthy();
            expect(workRow.classList.contains('row')).toBe(true);

            const cols = workRow.querySelectorAll(':scope > [class*="col-md-"]');
            expect(cols.length).toBe(2);
            expect(cols[0].classList.contains('col-md-6')).toBe(true);
            expect(cols[1].classList.contains('col-md-6')).toBe(true);

            expect(cols[0].querySelector('[data-role="instruction"]')).toBeTruthy();
            expect(cols[1].querySelector('[data-role="result-preview"]')).toBeTruthy();

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });
    });

    describe('configuration picker', () => {
        const configurations = [
            { identifier: 'editorial', name: 'Editorial', isDefault: true },
            { identifier: 'creative', name: 'Creative', isDefault: false },
        ];
        const tasksWithConfiguration = [
            {
                uid: 1, identifier: 'improve', name: 'Improve Text', description: 'd', promptTemplate: 'Improve: {{input}}',
                configuration: { identifier: 'editorial', name: 'Editorial' },
            },
            { uid: 2, identifier: 'summarize', name: 'Summarize', description: 'd', promptTemplate: 'Summarize: {{input}}' },
        ];

        function pickerColumn() {
            return document.querySelector('[data-role="configuration-col"]');
        }

        it('should offer the configurations once they arrive', async () => {
            mockService.getTasks.mockResolvedValue({ success: true, tasks: tasksWithConfiguration });
            mockService.getConfigurations = vi.fn().mockResolvedValue({ success: true, configurations });
            const showPromise = new CowriterDialog(mockService).show('text', 'full');

            await vi.waitFor(() => expect(pickerColumn()?.hidden).toBe(false));

            const options = [...document.querySelectorAll('[data-role="configuration-select"] option')];
            expect(options.map((o) => [o.value, o.textContent])).toEqual([
                ['', 'Task setting (Editorial)'],
                ['editorial', 'Editorial (default)'],
                ['creative', 'Creative'],
            ]);
            const cols = document.querySelectorAll('[data-role="config-row"] > [class*="col-md-"]:not([hidden])');
            expect([...cols].map((c) => c.className)).toEqual(['col-md-5', 'col-md-4', 'col-md-3']);

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should name the default configuration for a task without one of its own', async () => {
            mockService.getTasks.mockResolvedValue({ success: true, tasks: tasksWithConfiguration });
            mockService.getConfigurations = vi.fn().mockResolvedValue({ success: true, configurations });
            const showPromise = new CowriterDialog(mockService).show('text', 'full');
            await vi.waitFor(() => expect(pickerColumn()?.hidden).toBe(false));

            const taskSelect = document.querySelector('[data-role="task-select"]');
            taskSelect.value = '2';
            taskSelect.dispatchEvent(new Event('change'));

            expect(document.querySelector('[data-role="configuration-task-setting"]').textContent)
                .toBe('Default configuration');

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should send the chosen configuration with the task', async () => {
            mockService.getConfigurations = vi.fn().mockResolvedValue({ success: true, configurations });
            const showPromise = new CowriterDialog(mockService).show('my selected text', 'full');
            await vi.waitFor(() => expect(pickerColumn()?.hidden).toBe(false));

            document.querySelector('[data-role="configuration-select"]').value = 'creative';
            document.querySelector('[name="execute"]').click();

            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            expect(mockService.executeTask.mock.calls[0][9]).toBe('creative');

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should keep the picker hidden when the configurations cannot be loaded', async () => {
            mockService.getConfigurations = vi.fn().mockRejectedValue(new Error('403'));
            const showPromise = new CowriterDialog(mockService).show('text', 'full');
            await vi.waitFor(() => expect(mockService.getConfigurations).toHaveBeenCalled());
            await vi.waitFor(() => expect(document.querySelector('[name="cancel"]')).not.toBeNull());
            await Promise.resolve();

            expect(pickerColumn().hidden).toBe(true);
            expect(document.querySelector('[data-role="configuration-select"]').value).toBe('');

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });
    });

    describe('tools', () => {
        it('should ask for tools without streaming, as one version, and show the tool steps', async () => {
            mockService._routes = { taskStream: '/typo3/ajax/tx_cowriter_task_stream' };
            mockService.executeTaskStream = vi.fn();
            mockService.executeTask.mockResolvedValue({
                success: true, content: '<p>Two pages.</p>', model: 'gpt-test', toolIterations: 3,
            });
            const showPromise = new CowriterDialog(mockService).show('my selected text', 'full');
            await vi.waitFor(() => expect(document.querySelector('[data-role="tools-toggle"]')).not.toBeNull());

            const variants = document.querySelector('[data-role="variants-select"]');
            variants.value = '3';
            const toggle = document.querySelector('[data-role="tools-toggle"]');
            expect(toggle.checked).toBe(false);
            toggle.checked = true;
            toggle.dispatchEvent(new Event('change'));
            expect(variants.value).toBe('1');
            expect(variants.disabled).toBe(true);

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            expect(mockService.executeTaskStream).not.toHaveBeenCalled();
            expect(mockService.executeTask.mock.calls[0][10]).toMatchObject({ useTools: true, variants: 1 });
            await vi.waitFor(() => expect(document.querySelector('[data-role="model-info"]').textContent).toContain('Tool steps: 3'));

            toggle.checked = false;
            toggle.dispatchEvent(new Event('change'));
            expect(variants.disabled).toBe(false);

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });
    });

    describe('saved prompts', () => {
        const prompts = [
            { uid: 12, title: 'Teaser', instruction: 'Write a teaser.', own: true, shared: true, awaitingApproval: true },
            { uid: 23, title: 'House style', instruction: 'Use the house style.', own: false, shared: true, awaitingApproval: false },
        ];

        function taskSelect() {
            return document.querySelector('[data-role="task-select"]');
        }

        it('should offer own and shared prompts and run the chosen one as a custom instruction', async () => {
            mockService.getSavedPrompts = vi.fn().mockResolvedValue({ success: true, prompts });
            const showPromise = new CowriterDialog(mockService).show('my selected text', 'full');
            await vi.waitFor(() => expect(document.querySelectorAll('[data-role="task-select"] optgroup')).toHaveLength(2));

            const groups = [...taskSelect().querySelectorAll('optgroup')].map((group) => [
                group.label, [...group.querySelectorAll('option')].map((o) => [o.value, o.textContent]),
            ]);
            expect(groups).toEqual([
                ['My prompts', [['prompt-12', 'Teaser (awaiting approval)']]],
                ['Shared prompts', [['prompt-23', 'House style']]],
            ]);

            taskSelect().value = 'prompt-23';
            taskSelect().dispatchEvent(new Event('change'));
            expect(document.querySelector('[data-role="instruction"]').value).toBe('Use the house style.');
            expect(document.querySelector('[data-role="prompt-delete"]').hidden).toBe(true);

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            expect(mockService.executeTask.mock.calls[0][0]).toBe(0);
            expect(mockService.executeTask.mock.calls[0][3]).toBe('Use the house style.');

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should save the instruction as a prompt and select it', async () => {
            mockService.getSavedPrompts = vi.fn().mockResolvedValue({ success: true, prompts: [] });
            mockService.savePrompt = vi.fn().mockResolvedValue({
                success: true,
                prompt: { uid: 31, title: 'Short intro', instruction: 'Two sentences.', own: true, shared: true, awaitingApproval: true },
            });
            const showPromise = new CowriterDialog(mockService).show('my selected text', 'full');
            await vi.waitFor(() => expect(document.querySelector('[data-role="prompt-save"]')).not.toBeNull());

            document.querySelector('[data-role="instruction"]').value = '  Two sentences.  ';
            document.querySelector('[data-role="prompt-title"]').value = 'Short intro';
            document.querySelector('[data-role="prompt-share"]').checked = true;
            document.querySelector('[data-role="prompt-save"]').click();

            await vi.waitFor(() => expect(taskSelect().value).toBe('prompt-31'));
            expect(mockService.savePrompt).toHaveBeenCalledWith({ title: 'Short intro', instruction: 'Two sentences.', shared: true });
            expect(document.querySelector('[data-role="prompt-status"]').textContent)
                .toBe('Prompt saved. Other editors see it once an administrator approves it.');
            expect(document.querySelector('[data-role="prompt-delete"]').hidden).toBe(false);
            expect(document.querySelector('[data-role="prompt-title"]').value).toBe('');

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should not save a prompt without a title', async () => {
            mockService.savePrompt = vi.fn();
            const showPromise = new CowriterDialog(mockService).show('my selected text', 'full');
            await vi.waitFor(() => expect(document.querySelector('[data-role="prompt-save"]')).not.toBeNull());

            document.querySelector('[data-role="instruction"]').value = 'Two sentences.';
            document.querySelector('[data-role="prompt-save"]').click();

            expect(mockService.savePrompt).not.toHaveBeenCalled();
            expect(document.querySelector('[data-role="prompt-status"]').textContent).not.toBe('');

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should delete an own prompt and return to the custom instruction', async () => {
            mockService.getSavedPrompts = vi.fn().mockResolvedValue({ success: true, prompts: [prompts[0]] });
            mockService.deletePrompt = vi.fn().mockResolvedValue({ success: true });
            const showPromise = new CowriterDialog(mockService).show('my selected text', 'full');
            await vi.waitFor(() => expect(taskSelect().querySelector('option[value="prompt-12"]')).not.toBeNull());

            taskSelect().value = 'prompt-12';
            taskSelect().dispatchEvent(new Event('change'));
            document.querySelector('[data-role="prompt-delete"]').click();

            await vi.waitFor(() => expect(taskSelect().querySelector('optgroup')).toBeNull());
            expect(mockService.deletePrompt).toHaveBeenCalledWith(12);
            expect(taskSelect().value).toBe('0');
            expect(document.querySelector('[data-role="prompt-delete"]').hidden).toBe(true);

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should send one save request while the first is running', async () => {
            let finish;
            mockService.getSavedPrompts = vi.fn().mockResolvedValue({ success: true, prompts: [] });
            mockService.savePrompt = vi.fn(() => new Promise((resolve) => { finish = resolve; }));
            const showPromise = new CowriterDialog(mockService).show('my selected text', 'full');
            await vi.waitFor(() => expect(document.querySelector('[data-role="prompt-save"]')).not.toBeNull());

            const save = document.querySelector('[data-role="prompt-save"]');
            document.querySelector('[data-role="instruction"]').value = 'Two sentences.';
            document.querySelector('[data-role="prompt-title"]').value = 'Short intro';
            save.click();
            save.click();

            expect(mockService.savePrompt).toHaveBeenCalledTimes(1);
            expect(save.getAttribute('aria-disabled')).toBe('true');

            finish({ success: true, prompt: { uid: 31, title: 'Short intro', instruction: 'Two sentences.', own: true, shared: false, awaitingApproval: false } });
            await vi.waitFor(() => expect(save.hasAttribute('aria-disabled')).toBe(false));

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should send one delete request while the first is running', async () => {
            let finish;
            mockService.getSavedPrompts = vi.fn().mockResolvedValue({ success: true, prompts: [prompts[0]] });
            mockService.deletePrompt = vi.fn(() => new Promise((resolve) => { finish = resolve; }));
            const showPromise = new CowriterDialog(mockService).show('my selected text', 'full');
            await vi.waitFor(() => expect(taskSelect().querySelector('option[value="prompt-12"]')).not.toBeNull());

            taskSelect().value = 'prompt-12';
            taskSelect().dispatchEvent(new Event('change'));
            const remove = document.querySelector('[data-role="prompt-delete"]');
            remove.click();
            remove.click();

            expect(mockService.deletePrompt).toHaveBeenCalledTimes(1);
            expect(remove.getAttribute('aria-disabled')).toBe('true');

            finish({ success: true });
            await vi.waitFor(() => expect(remove.hasAttribute('aria-disabled')).toBe(false));

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should hide the save form when the service cannot store prompts', async () => {
            const showPromise = new CowriterDialog(mockService).show('my selected text', 'full');
            await vi.waitFor(() => expect(document.querySelector('[data-role="prompt-saver"]')).not.toBeNull());

            expect(document.querySelector('[data-role="prompt-saver"]').hidden).toBe(true);
            expect(taskSelect().querySelector('optgroup')).toBeNull();

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });
    });

    describe('streamed answer', () => {
        it('should show the answer while it is written and the final HTML afterwards', async () => {
            let finish;
            mockService._routes = { taskStream: '/typo3/ajax/tx_cowriter_task_stream' };
            mockService.executeTaskStream = vi.fn((request, onChunk) => {
                onChunk('<p>Partial');
                return new Promise((resolve) => { finish = resolve; });
            });
            const showPromise = new CowriterDialog(mockService).show('my selected text', 'full');
            await vi.waitFor(() => expect(document.querySelector('[name="execute"]')).not.toBeNull());

            document.querySelector('[name="execute"]').click();
            const preview = document.querySelector('[data-role="result-preview"]');
            await vi.waitFor(() => expect(preview.textContent).toBe('Partial'));

            expect(preview.getAttribute('aria-busy')).toBe('true');
            expect(mockService.executeTask).not.toHaveBeenCalled();
            expect(mockService.executeTaskStream.mock.calls[0][0]).toMatchObject({
                taskUid: 1, context: 'my selected text', contextType: 'selection', configuration: '',
            });

            finish({ done: true, model: 'gpt-test', content: '<p>Final <strong>text</strong></p>' });
            await vi.waitFor(() => expect(preview.innerHTML).toBe('<p>Final <strong>text</strong></p>'));
            expect(preview.hasAttribute('aria-busy')).toBe(false);

            document.querySelector('[name="insert"]').click();
            await expect(showPromise).resolves.toEqual({ content: '<p>Final <strong>text</strong></p>' });
        });

        it('should show the error and clear the busy state when the stream fails', async () => {
            mockService._routes = { taskStream: '/typo3/ajax/tx_cowriter_task_stream' };
            mockService.executeTaskStream = vi.fn().mockRejectedValue(new Error('LLM provider error occurred.'));
            const showPromise = new CowriterDialog(mockService).show('my selected text', 'full');
            await vi.waitFor(() => expect(document.querySelector('[name="execute"]')).not.toBeNull());

            document.querySelector('[name="execute"]').click();
            const preview = document.querySelector('[data-role="result-preview"]');
            await vi.waitFor(() => expect(preview.textContent).toContain('LLM provider error occurred.'));
            expect(preview.hasAttribute('aria-busy')).toBe(false);

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });
    });

    describe('style controls', () => {
        const styleOptions = {
            success: true,
            audiences: [{ uid: 3, name: 'Experts' }],
            tones: [],
        };

        it('should offer the audience snippets, hide an empty tone list and always offer the length', async () => {
            mockService.getStyleOptions = vi.fn().mockResolvedValue(styleOptions);
            const showPromise = new CowriterDialog(mockService).show('text', 'full');
            await vi.waitFor(() => expect(document.querySelector('[data-role="audience-col"]')?.hidden).toBe(false));

            expect([...document.querySelectorAll('[data-role="audience-select"] option')].map((o) => [o.value, o.textContent]))
                .toEqual([['0', 'No preference'], ['3', 'Experts']]);
            expect(document.querySelector('[data-role="tone-col"]').hidden).toBe(true);
            expect([...document.querySelectorAll('[data-role="length-select"] option')].map((o) => o.value))
                .toEqual(['-2', '-1', '0', '1', '2']);
            expect(document.querySelector('[data-role="length-select"]').value).toBe('0');

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should send the chosen style and show the word count against the target', async () => {
            mockService.getStyleOptions = vi.fn().mockResolvedValue(styleOptions);
            mockService.executeTask.mockResolvedValue({
                success: true, content: '<p>one two three</p>', model: 'gpt-test', targetWords: 150,
            });
            const showPromise = new CowriterDialog(mockService).show('my selected text', 'full');
            await vi.waitFor(() => expect(document.querySelector('[data-role="audience-col"]')?.hidden).toBe(false));

            document.querySelector('[data-role="audience-select"]').value = '3';
            document.querySelector('[data-role="length-select"]').value = '-1';
            document.querySelector('[name="execute"]').click();

            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            expect(mockService.executeTask.mock.calls[0][10]).toEqual({ audience: 3, tone: 0, length: -1, variants: 1, useTools: false });
            await vi.waitFor(() => expect(document.querySelector('[data-role="model-info"]').textContent)
                .toBe('Model: gpt-test | About 3 words (target 150)'));

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });
    });

    describe('versions', () => {
        it('should ask for two versions without streaming and insert the one the editor picks', async () => {
            mockService._routes = { taskStream: '/typo3/ajax/tx_cowriter_task_stream' };
            mockService.executeTaskStream = vi.fn();
            mockService.executeTask.mockResolvedValue({
                success: true, content: '<p>First</p>', variants: ['<p>First</p>', '<p>Second</p>'], model: 'gpt-test',
            });
            const showPromise = new CowriterDialog(mockService).show('my selected text', 'full');
            await vi.waitFor(() => expect(document.querySelector('[name="execute"]')).not.toBeNull());

            document.querySelector('[data-role="variants-select"]').value = '2';
            document.querySelector('[name="execute"]').click();

            await vi.waitFor(() => expect(document.querySelectorAll('[data-role="variant-picker"] input[type="radio"]')).toHaveLength(2));
            expect(mockService.executeTaskStream).not.toHaveBeenCalled();
            expect(mockService.executeTask.mock.calls[0][10].variants).toBe(2);
            const preview = document.querySelector('[data-role="result-preview"]');
            expect(preview.innerHTML).toBe('<p>First</p>');

            const second = document.querySelectorAll('[data-role="variant-picker"] input[type="radio"]')[1];
            second.checked = true;
            second.dispatchEvent(new Event('change'));
            expect(preview.innerHTML).toBe('<p>Second</p>');

            document.querySelector('[name="insert"]').click();
            await expect(showPromise).resolves.toEqual({ content: '<p>Second</p>' });
        });

        it('should show no picker for a single answer', async () => {
            const showPromise = new CowriterDialog(mockService).show('my selected text', 'full');
            await vi.waitFor(() => expect(document.querySelector('[name="execute"]')).not.toBeNull());

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => expect(document.querySelector('[data-role="result-preview"]').textContent).toContain('Improved text content'));

            expect(document.querySelector('[data-role="variant-picker"]')).toBeNull();

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });
    });

    describe('execute flow', () => {
        it('should call executeTask with instruction from textarea', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('my selected text', 'full editor content');

            await vi.waitFor(() => {
                expect(document.querySelector('[name="execute"]')).not.toBeNull();
            });

            // The instruction textarea should have been prefilled with the resolved template ({{input}} stripped)
            const textarea = document.querySelector('[data-role="instruction"]');
            expect(textarea.value).toBe('Improve:');

            // Click Execute
            document.querySelector('[name="execute"]').click();

            await vi.waitFor(() => {
                expect(mockService.executeTask).toHaveBeenCalledWith(
                    1, 'my selected text', 'selection', 'Improve:', '',
                    'selection', null, [], expect.any(AbortSignal), '', { audience: 0, tone: 0, length: 0, variants: 1, useTools: false },
                );
            });

            // Now result is shown, click Insert to accept
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });
            document.querySelector('[name="insert"]').click();

            const result = await showPromise;
            expect(result.content).toBe('Improved text content');
        });

        it('should use full content when content_element is selected', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('', 'full editor content here');

            await vi.waitFor(() => {
                expect(document.querySelector('[name="execute"]')).not.toBeNull();
            });

            // Template resolves with {{input}} stripped
            const textarea = document.querySelector('[data-role="instruction"]');
            expect(textarea.value).toBe('Improve:');

            document.querySelector('[name="execute"]').click();

            await vi.waitFor(() => {
                expect(mockService.executeTask).toHaveBeenCalledWith(
                    1, 'full editor content here', 'content_element',
                    'Improve:', '',
                    'text', null, [], expect.any(AbortSignal), '', { audience: 0, tone: 0, length: 0, variants: 1, useTools: false },
                );
            });

            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });
            document.querySelector('[name="insert"]').click();
            const result = await showPromise;
            expect(result.content).toBe('Improved text content');
        });

        it('should send taskUid=0 and custom instruction for Custom mode', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="task-select"]')).not.toBeNull();
            });

            // Switch to Custom
            const select = document.querySelector('[data-role="task-select"]');
            select.value = '0';
            select.dispatchEvent(new Event('change'));

            // Write custom instruction
            const textarea = document.querySelector('[data-role="instruction"]');
            textarea.value = 'Make it more formal';

            document.querySelector('[name="execute"]').click();

            await vi.waitFor(() => {
                expect(mockService.executeTask).toHaveBeenCalledWith(
                    0, 'text', 'selection', 'Make it more formal', '',
                    'selection', null, [], expect.any(AbortSignal), '', { audience: 0, tone: 0, length: 0, variants: 1, useTools: false },
                );
            });

            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });
            document.querySelector('[name="insert"]').click();
            await showPromise;
        });

        it('should show result in preview area as rich HTML', async () => {
            mockService.executeTask.mockResolvedValue({
                success: true,
                content: '<p>Improved <strong>text</strong> content</p>',
                model: 'gpt-4o',
            });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[name="execute"]')).not.toBeNull();
            });

            document.querySelector('[name="execute"]').click();

            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                // HTML is rendered as DOM, not as literal text
                expect(preview.querySelector('strong')).not.toBeNull();
                expect(preview.textContent).toContain('Improved');
                expect(preview.textContent).toContain('text');
                // Muted class should be removed after successful execution
                expect(preview.classList.contains('cowriter-result--empty')).toBe(false);
            });

            // Model info should be shown
            const modelInfo = document.querySelector('[data-role="model-info"]');
            expect(modelInfo.textContent).toBe('Model: gpt-4o');
            expect(modelInfo.style.display).toBe('block');

            document.querySelector('[name="insert"]').click();
            await showPromise;
        });

        it('should show spinner and "Generating..." while loading', async () => {
            // Make executeTask hang for a bit
            let resolveTask;
            mockService.executeTask.mockReturnValue(
                new Promise((resolve) => { resolveTask = resolve; }),
            );

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[name="execute"]')).not.toBeNull();
            });

            document.querySelector('[name="execute"]').click();

            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                expect(preview.textContent).toContain('Generating');
                // Should have a spinner element
                expect(preview.querySelector('.spinner-border')).not.toBeNull();
            });

            // Cancel button should remain enabled during loading
            const cancelBtn = document.querySelector('[name="cancel"]');
            expect(cancelBtn.disabled).toBe(false);

            // Resolve the task
            resolveTask({ success: true, content: 'Done', model: 'gpt-4o' });

            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                expect(preview.textContent).toBe('Done');
            });

            document.querySelector('[name="insert"]').click();
            await showPromise;
        });

        it('should show error in preview when task fails', async () => {
            mockService.executeTask.mockRejectedValue(new Error('API timeout'));

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[name="execute"]')).not.toBeNull();
            });

            document.querySelector('[name="execute"]').click();

            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                expect(preview.textContent).toContain('Error: API timeout');
            });

            // Cancel
            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should show error when result has no content', async () => {
            mockService.executeTask.mockResolvedValue({ success: false, error: 'Task failed' });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[name="execute"]')).not.toBeNull();
            });

            document.querySelector('[name="execute"]').click();

            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                expect(preview.textContent).toBe('Task failed');
            });

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should show collapsible debug details after successful execution', async () => {
            mockService.executeTask.mockResolvedValue({
                success: true,
                content: '<p>Result</p>',
                model: 'test-model',
                finishReason: 'stop',
                usage: { promptTokens: 100, completionTokens: 50, totalTokens: 150 },
                thinking: 'I reasoned about this.',
            });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('input text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[name="execute"]')).not.toBeNull();
            });

            document.querySelector('[name="execute"]').click();

            await vi.waitFor(() => {
                const details = document.querySelector('[data-role="debug-details"]');
                expect(details).not.toBeNull();
                expect(details.tagName).toBe('DETAILS');

                // Summary should say "Debug info"
                const summary = details.querySelector('summary');
                expect(summary.textContent).toBe('Debug info');

                // Content should include all debug sections
                const content = details.textContent;
                expect(content).toContain('Model: test-model');
                expect(content).toContain('Finish reason: stop');
                expect(content).toContain('Tokens:');
                expect(content).toContain('Input text');
                expect(content).toContain('input text');
                expect(content).toContain('Instruction sent');
                expect(content).toContain('Thinking');
                expect(content).toContain('I reasoned about this.');
                expect(content).toContain('Content returned');
            });

            // Copy button should be inside details
            const copyBtn = document.querySelector('[data-role="debug-copy"]');
            expect(copyBtn).not.toBeNull();
            expect(copyBtn.textContent).toBe('Copy to clipboard');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should show debug details on error', async () => {
            mockService.executeTask.mockResolvedValue({ success: false, error: 'Failed' });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[name="execute"]')).not.toBeNull();
            });

            document.querySelector('[name="execute"]').click();

            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                expect(preview.textContent).toBe('Failed');
            });

            const debugDetails = document.querySelector('[data-role="debug-details"]');
            expect(debugDetails).not.toBeNull();
            expect(debugDetails.textContent).toContain('Error: Failed');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should show debug details without usage or thinking fields', async () => {
            mockService.executeTask.mockResolvedValue({
                success: true,
                content: 'Result without extras',
                model: 'gpt-4o',
                finishReason: 'stop',
                // no usage, no thinking
            });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('input', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                return document.querySelector('[data-role="debug-details"]');
            });

            const debugContent = document.querySelector('[data-role="debug-details"]').textContent;
            expect(debugContent).toContain('Model: gpt-4o');
            expect(debugContent).toContain('Finish reason: stop');
            expect(debugContent).not.toContain('Tokens:');
            expect(debugContent).not.toContain('--- Thinking ---');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should show token count in model info when usage is present', async () => {
            mockService.executeTask.mockResolvedValue({
                success: true,
                content: 'Result with tokens',
                model: 'gpt-4o',
                usage: { promptTokens: 50, completionTokens: 100, totalTokens: 150 },
            });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('input', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                const info = document.querySelector('[data-role="model-info"]');
                return info.style.display !== 'none';
            });

            const modelInfo = document.querySelector('[data-role="model-info"]');
            expect(modelInfo.textContent).toBe('Model: gpt-4o | 150 tokens');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

    });

    describe('cancel', () => {
        it('should reject promise when cancel is clicked', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[name="cancel"]')).not.toBeNull();
            });

            document.querySelector('[name="cancel"]').click();

            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should remove modal from DOM when cancelled', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('.modal')).not.toBeNull();
            });

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});

            expect(document.querySelector('.modal')).toBeNull();
        });
    });

    describe('context scope dropdown', () => {
        it('should render scope as dropdown with 6 options', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const scopeSelect = document.querySelector('[data-role="scope-select"]');
            expect(scopeSelect).toBeTruthy();
            expect(scopeSelect.tagName).toBe('SELECT');
            expect(scopeSelect.options).toHaveLength(6);
            expect(scopeSelect.options[0].textContent).toBe('Selection');
            expect(scopeSelect.options[1].textContent).toBe('Full content');
            expect(scopeSelect.options[2].textContent).toBe('Content element');
            expect(scopeSelect.options[3].textContent).toBe('Page content');
            expect(scopeSelect.options[4].textContent).toBe('Parent page');
            expect(scopeSelect.options[5].textContent).toBe('Grandparent page');

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should default scope to Selection when text is selected', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const scopeSelect = document.querySelector('[data-role="scope-select"]');
            expect(scopeSelect.value).toBe('selection');

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should default scope to Text when no selection', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('', 'full content');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const scopeSelect = document.querySelector('[data-role="scope-select"]');
            expect(scopeSelect.value).toBe('text');

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should disable Selection option when no text selected', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('', 'full content');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const scopeSelect = document.querySelector('[data-role="scope-select"]');
            expect(scopeSelect.options[0].disabled).toBe(true);

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should send scope ID from dropdown in executeTask', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const scopeSelect = document.querySelector('[data-role="scope-select"]');
            scopeSelect.value = 'page';
            scopeSelect.dispatchEvent(new Event('change'));

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());

            expect(mockService.executeTask.mock.calls[0][5]).toBe('page');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should pass contextScope and recordContext to executeTask', async () => {
            const dialog = new CowriterDialog(mockService);
            const recordContext = { table: 'tt_content', uid: 42, field: 'bodytext' };
            const showPromise = dialog.show('text', 'full', '', recordContext);

            await vi.waitFor(() => {
                expect(document.querySelector('[name="execute"]')).not.toBeNull();
            });

            const scopeSelect = document.querySelector('[data-role="scope-select"]');
            scopeSelect.value = 'page';
            scopeSelect.dispatchEvent(new Event('change'));

            document.querySelector('[name="execute"]').click();

            await vi.waitFor(() => {
                expect(mockService.executeTask).toHaveBeenCalledWith(
                    1, 'text', 'selection', 'Improve:', '',
                    'page', recordContext, [], expect.any(AbortSignal), '', { audience: 0, tone: 0, length: 0, variants: 1, useTools: false },
                );
            });

            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });
            document.querySelector('[name="insert"]').click();
            await showPromise;
        });

        it('should enable element/page/ancestor scope options when recordContext is provided', async () => {
            const dialog = new CowriterDialog(mockService);
            const recordContext = { table: 'tt_content', uid: 42, field: 'bodytext' };
            const showPromise = dialog.show('selected', 'full', '', recordContext);
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const scopeSelect = document.querySelector('[data-role="scope-select"]');
            expect(scopeSelect.options[2].disabled).toBe(false);  // Content element
            expect(scopeSelect.options[3].disabled).toBe(false);  // Page content
            expect(scopeSelect.options[4].disabled).toBe(false);  // Parent page
            expect(scopeSelect.options[5].disabled).toBe(false);  // Grandparent page

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should disable element/page/ancestor options when no recordContext', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected', 'full', '', null);
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const scopeSelect = document.querySelector('[data-role="scope-select"]');
            // Options 0 (selection) and 1 (text) should be enabled
            expect(scopeSelect.options[0].disabled).toBe(false);
            expect(scopeSelect.options[1].disabled).toBe(false);
            // Options 2-5 (content element, page content, parent/grandparent) should be disabled without recordContext
            expect(scopeSelect.options[2].disabled).toBe(true);
            expect(scopeSelect.options[3].disabled).toBe(true);
            expect(scopeSelect.options[4].disabled).toBe(true);
            expect(scopeSelect.options[5].disabled).toBe(true);

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });
    });

    describe('reference page picker', () => {
        it('should render "Add reference page" button', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);

            await vi.waitFor(() => {
                const btn = document.querySelector('[data-role="add-reference"]');
                expect(btn).not.toBeNull();
                expect(btn.textContent).toContain('Add reference page');
            });

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should add reference page row when button clicked', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull();
            });

            document.querySelector('[data-role="add-reference"]').click();

            const rows = document.querySelectorAll('[data-role="reference-row"]');
            expect(rows.length).toBe(1);

            expect(rows[0].querySelector('[data-role="ref-search"]')).not.toBeNull();
            expect(rows[0].querySelector('[data-role="ref-pid"]')).not.toBeNull();
            expect(rows[0].querySelector('[data-role="ref-pid"]').type).toBe('hidden');
            expect(rows[0].querySelector('[data-role="ref-relation"]')).not.toBeNull();

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should remove reference page row when remove button clicked', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull();
            });

            document.querySelector('[data-role="add-reference"]').click();
            document.querySelector('[data-role="add-reference"]').click();
            expect(document.querySelectorAll('[data-role="reference-row"]').length).toBe(2);

            document.querySelector('[data-role="remove-reference"]').click();
            expect(document.querySelectorAll('[data-role="reference-row"]').length).toBe(1);

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should include reference pages in executeTask call', async () => {
            const dialog = new CowriterDialog(mockService);
            const rc = { table: 'tt_content', uid: 1, field: 'bodytext' };
            const showPromise = dialog.show('text', 'full', '', rc);

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull();
            });

            document.querySelector('[data-role="add-reference"]').click();
            const row = document.querySelector('[data-role="reference-row"]');
            row.querySelector('[data-role="ref-pid"]').value = '5';
            row.querySelector('[data-role="ref-relation"]').value = 'style guide';

            document.querySelector('[name="execute"]').click();

            await vi.waitFor(() => {
                expect(mockService.executeTask).toHaveBeenCalledWith(
                    1, 'text', 'selection', 'Improve:', '',
                    'selection', rc,
                    [{ pid: 5, relation: 'style guide' }], expect.any(AbortSignal), '', { audience: 0, tone: 0, length: 0, variants: 1, useTools: false },
                );
            });

            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });
            document.querySelector('[name="insert"]').click();
            await showPromise;
        });

        it('should use hidden input for page ID and search input for typeahead', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull();
            });

            document.querySelector('[data-role="add-reference"]').click();
            const pidInput = document.querySelector('[data-role="ref-pid"]');
            const searchInput = document.querySelector('[data-role="ref-search"]');
            expect(pidInput.type).toBe('hidden');
            expect(searchInput.type).toBe('text');
            expect(searchInput.placeholder).toContain('Search pages');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should have aria-label on remove button', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull();
            });

            document.querySelector('[data-role="add-reference"]').click();
            const removeBtn = document.querySelector('[data-role="remove-reference"]');
            expect(removeBtn.getAttribute('aria-label')).toBe('Remove reference page');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should skip reference pages with empty or zero page ID', async () => {
            const dialog = new CowriterDialog(mockService);
            const rc = { table: 'tt_content', uid: 1, field: 'bodytext' };
            const showPromise = dialog.show('text', 'full', '', rc);

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull();
            });

            // Add two rows, one with valid PID, one without
            document.querySelector('[data-role="add-reference"]').click();
            document.querySelector('[data-role="add-reference"]').click();
            const rows = document.querySelectorAll('[data-role="reference-row"]');
            rows[0].querySelector('[data-role="ref-pid"]').value = '5';
            rows[0].querySelector('[data-role="ref-relation"]').value = 'ref';
            // rows[1] left empty (PID = '' which parses to NaN, so pid > 0 is false)

            document.querySelector('[name="execute"]').click();

            await vi.waitFor(() => {
                expect(mockService.executeTask).toHaveBeenCalledWith(
                    1, 'text', 'selection', 'Improve:', '',
                    'selection', rc,
                    [{ pid: 5, relation: 'ref' }], expect.any(AbortSignal), '', { audience: 0, tone: 0, length: 0, variants: 1, useTools: false },
                );
            });

            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });
            document.querySelector('[name="insert"]').click();
            await showPromise;
        });
    });

    describe('accessibility', () => {
        it('should associate labels with form controls via for attribute', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected', 'full', '', null);

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="task-select"]')).not.toBeNull();
            });

            const taskSelect = document.querySelector('[data-role="task-select"]');
            const scopeSelect = document.querySelector('[data-role="scope-select"]');

            // Each control should have an id, and a label with matching for attribute
            expect(taskSelect.id).toBeTruthy();
            expect(scopeSelect.id).toBeTruthy();

            const taskLabel = document.querySelector(`label[for="${taskSelect.id}"]`);
            const scopeLabel = document.querySelector(`label[for="${scopeSelect.id}"]`);
            expect(taskLabel).not.toBeNull();
            expect(scopeLabel).not.toBeNull();

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should have aria-live on result preview', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const preview = document.querySelector('[data-role="result-preview"]');
            expect(preview.getAttribute('role')).toBe('status');
            expect(preview.getAttribute('aria-live')).toBe('polite');

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });
    });

    describe('sanitizer', () => {
        it('should strip dangerous elements from HTML preview', async () => {
            mockService.executeTask.mockResolvedValue({
                success: true,
                content: '<p>Safe</p><script>alert(1)</script><base href="evil"><meta http-equiv="refresh"><link rel="stylesheet">',
                model: 'gpt-4o',
            });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[name="execute"]')).not.toBeNull();
            });

            document.querySelector('[name="execute"]').click();

            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                expect(preview.textContent).toContain('Safe');
                expect(preview.querySelector('script')).toBeNull();
                expect(preview.querySelector('base')).toBeNull();
                expect(preview.querySelector('meta')).toBeNull();
                expect(preview.querySelector('link')).toBeNull();
            });

            document.querySelector('[name="insert"]').click();
            await showPromise;
        });

        it('should strip data: URI and vbscript: from attributes', async () => {
            mockService.executeTask.mockResolvedValue({
                success: true,
                content: '<p>Text</p><a href="data:text/html,evil">Link</a><a href="vbscript:evil">Link2</a>',
                model: 'gpt-4o',
            });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[name="execute"]')).not.toBeNull();
            });

            document.querySelector('[name="execute"]').click();

            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                const links = preview.querySelectorAll('a');
                for (const link of links) {
                    expect(link.hasAttribute('href')).toBe(false);
                }
            });

            document.querySelector('[name="insert"]').click();
            await showPromise;
        });
    });

    describe('sanitizer allowlist', () => {
        it('should remove SVG elements', async () => {
            mockService.executeTask.mockResolvedValue({
                success: true,
                content: '<p>Safe</p><svg onload="alert(1)"></svg>',
                model: 'gpt-4o',
            });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Safe');
            });

            const preview = document.querySelector('[data-role="result-preview"]');
            expect(preview.querySelector('svg')).toBeNull();
            expect(preview.querySelector('p')).toBeTruthy();
            expect(preview.textContent).toContain('Safe');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should strip on* event handler attributes', async () => {
            mockService.executeTask.mockResolvedValue({
                success: true,
                content: '<img src="x.png" onerror="alert(1)">',
                model: 'gpt-4o',
            });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                return document.querySelector('[data-role="result-preview"] img');
            });

            const img = document.querySelector('[data-role="result-preview"] img');
            expect(img).toBeTruthy();
            expect(img.getAttribute('onerror')).toBeNull();
            expect(img.getAttribute('src')).toBe('x.png');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should remove javascript: URIs from href', async () => {
            mockService.executeTask.mockResolvedValue({
                success: true,
                content: '<a href="javascript:alert(1)">click</a>',
                model: 'gpt-4o',
            });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                return document.querySelector('[data-role="result-preview"] a');
            });

            const link = document.querySelector('[data-role="result-preview"] a');
            expect(link).toBeTruthy();
            expect(link.getAttribute('href')).toBeNull();

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should block non-image data: URIs but allow data:image/png', async () => {
            mockService.executeTask.mockResolvedValue({
                success: true,
                content: '<a href="data:text/html,<script>alert(1)</script>">bad</a><img src="data:image/png;base64,abc">',
                model: 'gpt-4o',
            });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                return document.querySelector('[data-role="result-preview"] a');
            });

            const link = document.querySelector('[data-role="result-preview"] a');
            expect(link.getAttribute('href')).toBeNull();

            const img = document.querySelector('[data-role="result-preview"] img');
            expect(img.getAttribute('src')).toBe('data:image/png;base64,abc');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });
    });

    describe('button state machine', () => {
        it('should show Cancel and Execute in idle state', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const cancel = document.querySelector('[name="cancel"]');
            const reset = document.querySelector('[name="reset"]');
            const execute = document.querySelector('[name="execute"]');
            const insert = document.querySelector('[name="insert"]');

            expect(cancel).toBeTruthy();
            expect(execute).toBeTruthy();
            expect(reset).toBeTruthy();
            expect(insert).toBeTruthy();

            expect(reset.style.display).toBe('none');
            expect(insert.style.display).toBe('none');
            expect(cancel.style.display).not.toBe('none');
            expect(execute.style.display).not.toBe('none');

            cancel.click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should hide Reset and Insert once the modal has rendered its buttons', async () => {
            // The core modal renders its footer after Modal.advanced() has returned.
            const { default: Modal } = await import('@typo3/backend/modal.js');
            Modal.renderAsync = true;
            try {
                const showPromise = new CowriterDialog(mockService).show('text', 'full');
                await vi.waitFor(() => expect(document.querySelector('[name="reset"]')).not.toBeNull());
                const modal = document.querySelector('.modal');
                await modal.updateComplete;
                await Promise.resolve();

                expect(document.querySelector('[name="reset"]').style.display).toBe('none');
                expect(document.querySelector('[name="insert"]').style.display).toBe('none');
                expect(document.querySelector('[name="execute"]').style.display).not.toBe('none');

                document.querySelector('[name="cancel"]').click();
                await expect(showPromise).rejects.toThrow('User cancelled');
            } finally {
                Modal.renderAsync = false;
            }
        });

        it('should show all four buttons in result state', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });

            const reset = document.querySelector('[name="reset"]');
            const insert = document.querySelector('[name="insert"]');
            expect(reset.style.display).not.toBe('none');
            expect(insert.style.display).not.toBe('none');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should insert result content when Insert button clicked', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });

            document.querySelector('[name="insert"]').click();
            const result = await showPromise;
            expect(result.content).toBe('Improved text content');
        });

        it('should chain execute: use previous result as context', async () => {
            mockService.executeTask
                .mockResolvedValueOnce({
                    success: true,
                    content: 'First result',
                    model: 'gpt-4o',
                })
                .mockResolvedValueOnce({
                    success: true,
                    content: 'Chained result',
                    model: 'gpt-4o',
                });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('original text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            // First execute
            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalledTimes(1));
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('First result');
            });

            // Second execute (chained)
            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalledTimes(2));

            // The context (arg index 1) of the second call should be the decoded first result
            expect(mockService.executeTask.mock.calls[1][1]).toBe('First result');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should restore original input on Reset', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('original text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            // Execute to enter result state
            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });

            // Click Reset
            document.querySelector('[name="reset"]').click();

            // Result area should show original input again (muted)
            const result = document.querySelector('[data-role="result-preview"]');
            expect(result.textContent).toContain('original text');
            expect(result.classList.contains('cowriter-result--empty')).toBe(true);

            // Reset and Insert should be hidden again
            expect(document.querySelector('[name="reset"]').style.display).toBe('none');
            expect(document.querySelector('[name="insert"]').style.display).toBe('none');

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should use original context after Reset then re-execute', async () => {
            mockService.executeTask
                .mockResolvedValueOnce({
                    success: true, content: 'First result', model: 'gpt-4o',
                })
                .mockResolvedValueOnce({
                    success: true, content: 'After reset result', model: 'gpt-4o',
                });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('original text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            // First execute
            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalledTimes(1));
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('First result');
            });

            // Reset
            document.querySelector('[name="reset"]').click();

            // Execute again — should use original context, NOT "First result"
            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalledTimes(2));

            expect(mockService.executeTask.mock.calls[1][1]).toBe('original text');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should disable Execute button during loading', async () => {
            // Make executeTask hang indefinitely
            mockService.executeTask.mockReturnValue(new Promise(() => {}));

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[name="execute"]').click();

            await vi.waitFor(() => {
                const execute = document.querySelector('[name="execute"]');
                expect(execute.disabled).toBe(true);
            });

            // Cancel should remain enabled
            expect(document.querySelector('[name="cancel"]').disabled).toBeFalsy();

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should re-enable buttons after error', async () => {
            mockService.executeTask.mockRejectedValue(new Error('API timeout'));

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());

            // Wait for error to render
            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                return preview.textContent.includes('Error:');
            });

            // Execute should be re-enabled
            expect(document.querySelector('[name="execute"]').disabled).toBeFalsy();
            // Reset and Insert should be hidden (idle state)
            expect(document.querySelector('[name="reset"]').style.display).toBe('none');
            expect(document.querySelector('[name="insert"]').style.display).toBe('none');

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should reject promise when cancelled during loading', async () => {
            mockService.executeTask.mockReturnValue(new Promise(() => {}));

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should preserve chained context when re-executing after error', async () => {
            mockService.executeTask
                .mockResolvedValueOnce({ success: true, content: 'First result', model: 'gpt-4o' })
                .mockRejectedValueOnce(new Error('API timeout'))
                .mockResolvedValueOnce({ success: true, content: 'Retry result', model: 'gpt-4o' });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('original text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            // First execute succeeds
            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalledTimes(1));
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('First result');
            });

            // Second execute fails
            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalledTimes(2));
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Error:');
            });

            // Third execute (retry) — context should be sanitized "First result" (from chaining)
            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalledTimes(3));

            // The context arg should NOT be 'original text'
            const thirdCallContext = mockService.executeTask.mock.calls[2][1];
            expect(thirdCallContext).not.toBe('original text');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should not resolve when Insert clicked without result', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            // Force Insert button to be visible by manipulating DOM
            const insert = document.querySelector('[name="insert"]');
            insert.style.display = '';
            insert.click();

            // Promise should NOT have resolved — cancel to verify
            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });
    });

    describe('_renderPageDropdown', () => {
        it('should render "No pages found" for empty pages array', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');
            const searchInput = document.querySelector('[data-role="ref-search"]');
            const hiddenPid = document.querySelector('[data-role="ref-pid"]');

            dialog._renderPageDropdown(dropdown, [], searchInput, hiddenPid);

            // A listbox may only hold options (axe aria-required-children): the
            // list stays closed and empty, the status outside it says so.
            expect(dropdown.style.display).toBe('none');
            expect(dropdown.children.length).toBe(0);
            expect(searchInput.getAttribute('aria-expanded')).toBe('false');
            const status = document.querySelector('[data-role="ref-status"]');
            expect(dropdown.contains(status)).toBe(false);
            expect(status.getAttribute('role')).toBe('status');
            expect(status.getAttribute('aria-live')).toBe('polite');
            expect(status.textContent).toBe('No pages found');
            expect(status.classList.contains('visually-hidden')).toBe(false);

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should render "No pages found" when pages is null', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');
            const searchInput = document.querySelector('[data-role="ref-search"]');
            const hiddenPid = document.querySelector('[data-role="ref-pid"]');

            dialog._renderPageDropdown(dropdown, null, searchInput, hiddenPid);

            expect(document.querySelector('[data-role="ref-status"]').textContent).toBe('No pages found');
            expect(dropdown.textContent).toBe('');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should render page items with slug span', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');
            const searchInput = document.querySelector('[data-role="ref-search"]');
            const hiddenPid = document.querySelector('[data-role="ref-pid"]');

            const pages = [{ uid: 42, title: 'About Us', slug: '/about-us' }];
            dialog._renderPageDropdown(dropdown, pages, searchInput, hiddenPid);

            expect(dropdown.style.display).toBe('block');
            const item = dropdown.querySelector('[role="option"]');
            expect(item).not.toBeNull();
            expect(item.textContent).toContain('[42] About Us');
            expect(item.textContent).toContain('/about-us');
            expect(item.querySelector('span.text-muted')).not.toBeNull();
            expect(searchInput.getAttribute('aria-expanded')).toBe('true');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should render page items without slug span when slug is empty', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');
            const searchInput = document.querySelector('[data-role="ref-search"]');
            const hiddenPid = document.querySelector('[data-role="ref-pid"]');

            const pages = [{ uid: 1, title: 'Home', slug: '' }];
            dialog._renderPageDropdown(dropdown, pages, searchInput, hiddenPid);

            const item = dropdown.querySelector('[role="option"]');
            expect(item.textContent).toBe('[1] Home');
            expect(item.querySelector('span.text-muted')).toBeNull();

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should set hidden PID and display text when page item clicked', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');
            const searchInput = document.querySelector('[data-role="ref-search"]');
            const hiddenPid = document.querySelector('[data-role="ref-pid"]');

            const pages = [{ uid: 42, title: 'About Us', slug: '/about' }];
            dialog._renderPageDropdown(dropdown, pages, searchInput, hiddenPid);

            dropdown.querySelector('[role="option"]').click();

            expect(hiddenPid.value).toBe('42');
            expect(searchInput.value).toBe('[42] About Us');
            expect(dropdown.style.display).toBe('none');
            expect(searchInput.getAttribute('aria-expanded')).toBe('false');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should render multiple page items', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');
            const searchInput = document.querySelector('[data-role="ref-search"]');
            const hiddenPid = document.querySelector('[data-role="ref-pid"]');

            const pages = [
                { uid: 1, title: 'Home', slug: '/' },
                { uid: 2, title: 'About', slug: '/about' },
                { uid: 3, title: 'Contact', slug: '/contact' },
            ];
            dialog._renderPageDropdown(dropdown, pages, searchInput, hiddenPid);

            const items = dropdown.querySelectorAll('[role="option"]');
            expect(items.length).toBe(3);
            // Click second item
            items[1].click();
            expect(hiddenPid.value).toBe('2');
            expect(searchInput.value).toBe('[2] About');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });
    });

    describe('ARIA combobox attributes', () => {
        it('should have correct ARIA attributes on search input', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const searchInput = document.querySelector('[data-role="ref-search"]');
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');

            expect(searchInput.getAttribute('role')).toBe('combobox');
            expect(searchInput.getAttribute('aria-expanded')).toBe('false');
            expect(searchInput.getAttribute('aria-autocomplete')).toBe('list');
            expect(searchInput.getAttribute('aria-controls')).toBe(dropdown.id);
            expect(searchInput.getAttribute('aria-label')).toBe('Search pages by title or ID');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should have listbox role on dropdown', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');

            expect(dropdown.getAttribute('role')).toBe('listbox');
            expect(dropdown.id).toBeTruthy();

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });
    });

    describe('keyboard and focus', () => {
        const openWithReferenceRow = async (dialog) => {
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());
            document.querySelector('[data-role="add-reference"]').click();
            // Wrapped: awaiting the helper must not wait for the dialog to settle.
            return { showPromise };
        };

        const renderPages = (dialog, pages, row = document) => {
            const dropdown = row.querySelector('[data-role="ref-dropdown"]');
            const searchInput = row.querySelector('[data-role="ref-search"]');
            dialog._renderPageDropdown(dropdown, pages, searchInput, row.querySelector('[data-role="ref-pid"]'));
            return { dropdown, searchInput, status: row.querySelector('[data-role="ref-status"]') };
        };
        const arrowDown = (input) => input.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
        const escapeOn = (el) => {
            const e = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true });
            el.dispatchEvent(e);
            return e;
        };
        const PAGES = [{ uid: 1, title: 'Home', slug: '/' }, { uid: 2, title: 'About', slug: '/about' }];

        const typeQuery = (input, value) => {
            input.value = value;
            input.dispatchEvent(new Event('input'));
        };
        const afterDebounce = () => new Promise((r) => setTimeout(r, 400));

        describe('a search in flight does not reopen a closed list', () => {
            it('drops the pending search when focus leaves during the debounce', async () => {
                const dialog = new CowriterDialog(mockService);
                const { showPromise } = await openWithReferenceRow(dialog);
                mockService.searchPages.mockResolvedValue({ success: true, pages: PAGES });
                const searchInput = document.querySelector('[data-role="ref-search"]');
                const dropdown = document.querySelector('[data-role="ref-dropdown"]');

                typeQuery(searchInput, 'pa');
                searchInput.dispatchEvent(new FocusEvent('focusout', {
                    bubbles: true, relatedTarget: document.querySelector('[data-role="ref-relation"]'),
                }));
                await afterDebounce();

                expect(dropdown.style.display).toBe('none');
                expect(searchInput.getAttribute('aria-expanded')).toBe('false');
                // Dropped before it was sent, not only its answer ignored.
                expect(mockService.searchPages).not.toHaveBeenCalled();

                document.querySelector('[name="cancel"]').click();
                await showPromise.catch(() => {});
            });

            it('ignores the answer of a search already sent when Escape closed the list', async () => {
                const dialog = new CowriterDialog(mockService);
                const { showPromise } = await openWithReferenceRow(dialog);
                let answer;
                mockService.searchPages.mockReturnValue(new Promise((r) => { answer = r; }));
                const { dropdown, searchInput } = renderPages(dialog, PAGES);

                typeQuery(searchInput, 'pag');
                await vi.waitFor(() => expect(mockService.searchPages).toHaveBeenCalled());
                escapeOn(searchInput);
                answer({ success: true, pages: PAGES });
                await afterDebounce();

                expect(dropdown.style.display).toBe('none');
                expect(searchInput.getAttribute('aria-expanded')).toBe('false');

                document.querySelector('[name="cancel"]').click();
                await showPromise.catch(() => {});
            });

            it('drops the pending search when an option of the previous list is clicked', async () => {
                const dialog = new CowriterDialog(mockService);
                const { showPromise } = await openWithReferenceRow(dialog);
                mockService.searchPages.mockResolvedValue({ success: true, pages: PAGES });
                const searchInput = document.querySelector('[data-role="ref-search"]');
                const dropdown = document.querySelector('[data-role="ref-dropdown"]');
                typeQuery(searchInput, 'pa');
                await vi.waitFor(() => expect(dropdown.style.display).toBe('block'));

                typeQuery(searchInput, 'pag');
                dropdown.querySelectorAll('[role="option"]')[1].click();
                await afterDebounce();

                expect(document.querySelector('[data-role="ref-pid"]').value).toBe('2');
                expect(dropdown.style.display).toBe('none');
                expect(searchInput.getAttribute('aria-expanded')).toBe('false');

                document.querySelector('[name="cancel"]').click();
                await showPromise.catch(() => {});
            });
        });

        it('keeps focus in the field when the list is pressed', async () => {
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            const { dropdown } = renderPages(dialog, PAGES);

            for (const target of [dropdown, dropdown.querySelector('[role="option"]')]) {
                const press = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
                target.dispatchEvent(press);
                expect(press.defaultPrevented).toBe(true);
            }

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('closes the list when focus leaves to nowhere (relatedTarget null)', async () => {
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            const { dropdown, searchInput } = renderPages(dialog, PAGES);

            searchInput.dispatchEvent(new FocusEvent('focusout', { bubbles: true, relatedTarget: null }));
            expect(dropdown.style.display).toBe('none');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('sets aria-expanded to false when an open list gets an empty result', async () => {
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            const { searchInput } = renderPages(dialog, PAGES);
            expect(searchInput.getAttribute('aria-expanded')).toBe('true');

            renderPages(dialog, []);
            expect(searchInput.getAttribute('aria-expanded')).toBe('false');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('writes the live region only when its text changes', async () => {
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            const status = document.querySelector('[data-role="ref-status"]');
            const observer = new MutationObserver(() => {});
            observer.observe(status, { childList: true, characterData: true, subtree: true });

            for (const pages of [PAGES, []]) {
                renderPages(dialog, pages);
                expect(observer.takeRecords().length).toBeGreaterThan(0);
                renderPages(dialog, pages);
                expect(observer.takeRecords()).toHaveLength(0);
            }
            observer.disconnect();

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('wraps long option texts instead of cutting them off', async () => {
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            const { dropdown } = renderPages(dialog, PAGES);
            const cs = getComputedStyle(dropdown.querySelector('[role="option"]'));

            expect(cs.whiteSpace).not.toBe('nowrap');
            expect(cs.textOverflow).not.toBe('ellipsis');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('marks the active option in forced colors', async () => {
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            const { dropdown, searchInput } = renderPages(dialog, PAGES);
            arrowDown(searchInput);
            const active = dropdown.querySelector('[role="option"].active');
            const other = dropdown.querySelector('[role="option"]:not(.active)');

            const sheet = document.getElementById('cowriter-dialog-styles').sheet;
            const forced = [...sheet.cssRules].filter((r) => r.media && r.media.mediaText.includes('forced-colors: active'));
            const rules = forced.flatMap((m) => [...m.cssRules]);
            const marking = rules.filter((r) => active.matches(r.selectorText)
                && r.style.getPropertyValue('background').toLowerCase().includes('highlight'));
            expect(marking).toHaveLength(1);
            expect(marking[0].style.getPropertyValue('forced-color-adjust')).toBe('none');
            expect(other.matches(marking[0].selectorText)).toBe(false);

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('keeps the controls of a row top-aligned, so that the status does not move them', async () => {
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            const row = document.querySelector('[data-role="reference-row"]');
            expect(row.classList.contains('align-items-start')).toBe(true);
            expect(row.classList.contains('align-items-center')).toBe(false);

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it.each(['task dialog', 'no-tasks notice'])('settles and cleans up when returnFocus throws (%s)', async (which) => {
            if (which === 'no-tasks notice') {
                mockService.getTasks.mockResolvedValue({ success: true, tasks: [] });
            }
            const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
            const returnFocus = vi.fn(() => { throw new Error('editor gone'); });
            const dialog = new CowriterDialog(mockService, { returnFocus });
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(document.querySelector('.modal')).not.toBeNull());
            let dropdown = null;
            if (which === 'task dialog') {
                document.querySelector('[data-role="add-reference"]').click();
                ({ dropdown } = renderPages(dialog, PAGES));
            }

            // Escape or the X button: the modal hides itself.
            document.querySelector('.modal').hideModal();

            await expect(showPromise).rejects.toThrow('User cancelled');
            expect(returnFocus).toHaveBeenCalledOnce();
            expect(consoleError).toHaveBeenCalled();
            if (dropdown) {
                // The row's listeners were removed: nothing closes the list.
                document.body.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true }));
                expect(dropdown.style.display).toBe('block');
            }
            consoleError.mockRestore();
        });

        it('closes the list when focus leaves the page search, not when it moves inside', async () => {
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            document.querySelector('[data-role="add-reference"]').click();
            const [row1, row2] = document.querySelectorAll('[data-role="reference-row"]');
            const { dropdown, searchInput } = renderPages(dialog, PAGES, row1);
            arrowDown(searchInput);

            const option = dropdown.querySelector('[role="option"]');
            searchInput.dispatchEvent(new FocusEvent('focusout', { bubbles: true, relatedTarget: option }));
            expect(dropdown.style.display).toBe('block');

            searchInput.dispatchEvent(new FocusEvent('focusout', {
                bubbles: true, relatedTarget: row2.querySelector('[data-role="ref-search"]'),
            }));
            expect(dropdown.style.display).toBe('none');
            expect(searchInput.getAttribute('aria-expanded')).toBe('false');
            expect(searchInput.hasAttribute('aria-activedescendant')).toBe(false);

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('closes the list on a pointer press outside it, not inside', async () => {
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            const { dropdown, searchInput } = renderPages(dialog, PAGES);
            arrowDown(searchInput);

            dropdown.querySelector('[role="option"]').dispatchEvent(new MouseEvent('pointerdown', { bubbles: true }));
            expect(dropdown.style.display).toBe('block');

            document.querySelector('[data-role="instruction"]').dispatchEvent(new MouseEvent('pointerdown', { bubbles: true }));
            expect(dropdown.style.display).toBe('none');
            expect(searchInput.hasAttribute('aria-activedescendant')).toBe(false);

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('keeps the options out of the Tab order and handles Escape on a focused option', async () => {
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            const { dropdown, searchInput } = renderPages(dialog, PAGES);
            const options = [...dropdown.querySelectorAll('[role="option"]')];
            expect(options.map((o) => o.tabIndex)).toEqual([-1, -1]);
            // Chromium makes a scrolling box without Tab stops inside one itself.
            expect(dropdown.getAttribute('tabindex')).toBe('-1');

            const modalKeydown = vi.fn();
            document.addEventListener('keydown', modalKeydown);
            try {
                options[0].focus(); // a mouse press focuses it
                const e = escapeOn(options[0]);
                expect(e.defaultPrevented).toBe(true);
                expect(modalKeydown).not.toHaveBeenCalled();
                expect(dropdown.style.display).toBe('none');
                expect(document.activeElement).toBe(searchInput);
            } finally {
                document.removeEventListener('keydown', modalKeydown);
            }

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('closes the "No pages found" status on Escape, and a second Escape reaches the modal', async () => {
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            const { searchInput, status } = renderPages(dialog, []);
            expect(status.textContent).toBe('No pages found');

            const first = escapeOn(searchInput);
            expect(first.defaultPrevented).toBe(true);
            expect(status.textContent).toBe('');
            const second = escapeOn(searchInput);
            expect(second.defaultPrevented).toBe(false);

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('clears aria-activedescendant when a short query closes the list', async () => {
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            const { dropdown, searchInput } = renderPages(dialog, PAGES);
            arrowDown(searchInput);
            expect(searchInput.hasAttribute('aria-activedescendant')).toBe(true);

            searchInput.value = 'a';
            searchInput.dispatchEvent(new Event('input'));
            expect(dropdown.style.display).toBe('none');
            expect(searchInput.hasAttribute('aria-activedescendant')).toBe(false);

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('clears aria-activedescendant when a failed search closes the list', async () => {
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            const { dropdown, searchInput } = renderPages(dialog, PAGES);
            arrowDown(searchInput);
            mockService.searchPages.mockRejectedValue(new Error('offline'));

            searchInput.value = 'Home';
            searchInput.dispatchEvent(new Event('input'));
            await vi.waitFor(() => expect(mockService.searchPages).toHaveBeenCalled());
            await vi.waitFor(() => expect(dropdown.style.display).toBe('none'));
            expect(searchInput.hasAttribute('aria-activedescendant')).toBe(false);

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('names the list, separates title and slug, and announces the number of results', async () => {
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            const { dropdown, status } = renderPages(dialog, PAGES);

            expect(dropdown.getAttribute('aria-label')).toBe('Matching pages');
            const option = dropdown.querySelector('[role="option"]');
            expect(option.textContent).toBe('[1] Home – /');
            expect(option.title).toBe('[1] Home – /');
            expect(dropdown.contains(status)).toBe(false);
            expect(status.textContent).toBe('2 pages found');

            renderPages(dialog, [PAGES[1]]);
            expect(status.textContent).toBe('1 page found');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('lets the page search take the free width of the row', async () => {
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            const wrapper = document.querySelector('[data-role="ref-search"]').parentElement;
            expect(getComputedStyle(wrapper).flexGrow).toBe('1');
            // Shared with the relation field, which would otherwise take it all.
            expect(getComputedStyle(document.querySelector('[data-role="ref-relation"]')).flexGrow).toBe('1');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('uses button classes the backend styles, with a visible border and focus ring', async () => {
            // The TYPO3 14.3 backend CSS has no btn-outline-* rules.
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            for (const role of ['add-reference', 'remove-reference']) {
                const btn = document.querySelector(`[data-role="${role}"]`);
                expect(btn.className).not.toMatch(/btn-outline-/);
                expect(btn.classList.contains('btn-default')).toBe(true);
            }

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('marks the task select as the element to focus first', async () => {
            const showPromise = new CowriterDialog(mockService).show('text', 'full');
            await vi.waitFor(() => expect(document.querySelector('[data-role="task-select"]')).not.toBeNull());
            expect(document.querySelector('[data-role="task-select"]').hasAttribute('autofocus')).toBe(true);
            expect(document.querySelectorAll('.cowriter-dialog [autofocus]')).toHaveLength(1);

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('hands focus to returnFocus instead of the opener when one is given', async () => {
            const opener = document.createElement('button');
            document.body.appendChild(opener);
            opener.focus();
            const returnFocus = vi.fn();

            const showPromise = new CowriterDialog(mockService, { returnFocus }).show('text', 'full');
            await vi.waitFor(() => expect(document.querySelector('[name="cancel"]')).not.toBeNull());
            document.querySelector('[name="cancel"]').focus();
            document.querySelector('[name="cancel"]').click();

            await expect(showPromise).rejects.toThrow('User cancelled');
            expect(returnFocus).toHaveBeenCalledOnce();
            expect(document.activeElement).not.toBe(opener);
        });

        it('closes only the page list on Escape and lets a second Escape reach the modal', async () => {
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');
            const searchInput = document.querySelector('[data-role="ref-search"]');
            dialog._renderPageDropdown(dropdown, [{ uid: 1, title: 'Home', slug: '/' }], searchInput,
                document.querySelector('[data-role="ref-pid"]'));
            // Stands in for the modal: the 13.4 modal listens for Escape on the
            // document, the 14.3 one (native <dialog>) closes unless the keydown
            // is default-prevented.
            const modalKeydown = vi.fn();
            document.addEventListener('keydown', modalKeydown);
            try {
                const first = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true });
                searchInput.dispatchEvent(first);
                expect(dropdown.style.display).toBe('none');
                expect(first.defaultPrevented).toBe(true);
                expect(modalKeydown).not.toHaveBeenCalled();

                const second = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true });
                searchInput.dispatchEvent(second);
                expect(second.defaultPrevented).toBe(false);
                expect(modalKeydown).toHaveBeenCalledTimes(1);
            } finally {
                document.removeEventListener('keydown', modalKeydown);
            }

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('keeps the list out of the layout without the Bootstrap position utilities', async () => {
            // The TYPO3 14.3 backend CSS has no .position-absolute/.position-relative
            // rules; jsdom loads no CSS either, so only inline positioning counts.
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');

            expect(getComputedStyle(dropdown).position).toBe('absolute');
            expect(getComputedStyle(dropdown.parentElement).position).toBe('relative');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('keeps focus in the page search after an option is picked with the mouse', async () => {
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');
            const searchInput = document.querySelector('[data-role="ref-search"]');
            dialog._renderPageDropdown(dropdown, [{ uid: 7, title: 'Home', slug: '/' }], searchInput,
                document.querySelector('[data-role="ref-pid"]'));

            const option = dropdown.querySelector('[role="option"]');
            option.focus(); // a mouse press focuses the button
            option.click();

            expect(dropdown.style.display).toBe('none');
            expect(document.activeElement).toBe(searchInput);

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('moves focus to the next row, then to the add button, when a row is removed', async () => {
            const dialog = new CowriterDialog(mockService);
            const { showPromise } = await openWithReferenceRow(dialog);
            document.querySelector('[data-role="add-reference"]').click();
            const [firstRow, secondRow] = document.querySelectorAll('[data-role="reference-row"]');

            const firstRemove = firstRow.querySelector('[data-role="remove-reference"]');
            firstRemove.focus();
            firstRemove.click();
            expect(document.activeElement).toBe(secondRow.querySelector('[data-role="ref-search"]'));

            const lastRemove = secondRow.querySelector('[data-role="remove-reference"]');
            lastRemove.focus();
            lastRemove.click();
            expect(document.querySelectorAll('[data-role="reference-row"]')).toHaveLength(0);
            expect(document.activeElement).toBe(document.querySelector('[data-role="add-reference"]'));

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it.each(['cancel', 'hidden'])('gives focus back to the opener once the modal is hidden (%s)', async (how) => {
            const opener = document.createElement('button');
            document.body.appendChild(opener);
            opener.focus();

            const showPromise = new CowriterDialog(mockService).show('text', 'full');
            await vi.waitFor(() => expect(document.querySelector('[name="cancel"]')).not.toBeNull());
            // The core modal moves focus into the dialog.
            document.querySelector('[name="cancel"]').focus();
            expect(document.activeElement).not.toBe(opener);

            if (how === 'cancel') {
                document.querySelector('[name="cancel"]').click();
            } else {
                // Escape or the X button: the modal hides itself.
                document.querySelector('.modal').hideModal();
            }
            await expect(showPromise).rejects.toThrow('User cancelled');
            expect(document.activeElement).toBe(opener);
        });

        it('gives focus back to the opener after Insert', async () => {
            const opener = document.createElement('button');
            document.body.appendChild(opener);
            opener.focus();

            const showPromise = new CowriterDialog(mockService).show('text', 'full');
            await vi.waitFor(() => expect(document.querySelector('[name="execute"]')).not.toBeNull());
            document.querySelector('[name="execute"]').click();
            const preview = document.querySelector('[data-role="result-preview"]');
            await vi.waitFor(() => expect(preview.textContent).toBe('Improved text content'));
            document.querySelector('[name="insert"]').focus();
            expect(document.activeElement).not.toBe(opener);
            document.querySelector('[name="insert"]').click();

            await expect(showPromise).resolves.toEqual({ content: 'Improved text content' });
            expect(document.activeElement).toBe(opener);
        });

        it('gives focus back to the opener when the no-tasks notice is closed', async () => {
            mockService.getTasks.mockResolvedValue({ success: true, tasks: [] });
            const opener = document.createElement('button');
            document.body.appendChild(opener);
            opener.focus();

            const showPromise = new CowriterDialog(mockService).show('text', 'full');
            await vi.waitFor(() => expect(document.querySelector('[name="close"]')).not.toBeNull());
            document.querySelector('[name="close"]').focus();
            document.querySelector('[name="close"]').click();

            await expect(showPromise).rejects.toThrow('User cancelled');
            expect(document.activeElement).toBe(opener);
        });
    });

    describe('keyboard navigation', () => {
        it('should close dropdown on Escape key', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');
            const searchInput = document.querySelector('[data-role="ref-search"]');
            const hiddenPid = document.querySelector('[data-role="ref-pid"]');

            // Render some items to make dropdown visible
            dialog._renderPageDropdown(dropdown, [{ uid: 1, title: 'Home', slug: '/' }], searchInput, hiddenPid);
            expect(dropdown.style.display).toBe('block');

            // Press Escape
            searchInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
            expect(dropdown.style.display).toBe('none');
            expect(searchInput.getAttribute('aria-expanded')).toBe('false');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should navigate items with arrow keys', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');
            const searchInput = document.querySelector('[data-role="ref-search"]');
            const hiddenPid = document.querySelector('[data-role="ref-pid"]');

            dialog._renderPageDropdown(dropdown, [
                { uid: 1, title: 'Home', slug: '/' },
                { uid: 2, title: 'About', slug: '/about' },
            ], searchInput, hiddenPid);

            // Arrow down once
            searchInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
            const items = dropdown.querySelectorAll('[role="option"]');
            expect(items[0].classList.contains('active')).toBe(true);
            expect(searchInput.getAttribute('aria-activedescendant')).toBe(items[0].id);

            // Arrow down again
            searchInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
            expect(items[0].classList.contains('active')).toBe(false);
            expect(items[1].classList.contains('active')).toBe(true);

            // Arrow up wraps to last item (stays at items[0] since we go up from 1)
            searchInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true }));
            expect(items[0].classList.contains('active')).toBe(true);
            expect(items[1].classList.contains('active')).toBe(false);

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should select active item on Enter key', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');
            const searchInput = document.querySelector('[data-role="ref-search"]');
            const hiddenPid = document.querySelector('[data-role="ref-pid"]');

            dialog._renderPageDropdown(dropdown, [
                { uid: 7, title: 'Contact', slug: '/contact' },
            ], searchInput, hiddenPid);

            // Navigate to first item
            searchInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
            // Press Enter
            searchInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));

            expect(hiddenPid.value).toBe('7');
            expect(searchInput.value).toBe('[7] Contact');
            expect(dropdown.style.display).toBe('none');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });
    });

    describe('modal in another document (dialog opened from the FormEngine iframe)', () => {
        // The core modal opens in the parent window when the dialog is called
        // from the FormEngine iframe. Both windows define typo3-backend-icon,
        // each with its own class, as TYPO3 does. An icon upgraded by the
        // wrong window adopts that window's stylesheets in the modal's
        // document, which the browser refuses (issue 197). Like the core, the
        // modal double renders its content only after Modal.advanced() has
        // returned (renderContentAsync).
        let Modal;
        let modalWindow;

        const defineIcon = (win) => {
            if (!win.customElements.get('typo3-backend-icon')) {
                win.customElements.define('typo3-backend-icon', class extends win.HTMLElement {});
            }
        };

        const expectIconsFromModalWindow = (root) => {
            const icons = [...root.querySelectorAll('typo3-backend-icon')];
            expect(icons.length).toBeGreaterThan(0);
            const modalIconClass = modalWindow.customElements.get('typo3-backend-icon');
            for (const icon of icons) {
                expect(icon.constructor).toBe(modalIconClass);
            }
        };

        beforeEach(async () => {
            const frame = document.createElement('iframe');
            document.body.appendChild(frame);
            modalWindow = frame.contentWindow;
            defineIcon(window);
            defineIcon(modalWindow);
            expect(modalWindow.customElements.get('typo3-backend-icon'))
                .not.toBe(window.customElements.get('typo3-backend-icon'));
            ({ default: Modal } = await import('@typo3/backend/modal.js'));
            Modal.targetDocument = modalWindow.document;
            Modal.renderContentAsync = true;
        });

        afterEach(() => {
            Modal.targetDocument = null;
            Modal.renderContentAsync = false;
        });

        it('creates the add-reference icon with the modal window\'s element class', async () => {
            const showPromise = new CowriterDialog(mockService).show('text', 'full');
            const modalDocument = modalWindow.document;
            await vi.waitFor(() => expect(modalDocument.querySelector('[data-role="add-reference"]')).not.toBeNull());

            expectIconsFromModalWindow(modalDocument.querySelector('[data-role="add-reference"]'));

            modalDocument.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('creates the remove-reference icon with the modal window\'s element class', async () => {
            const showPromise = new CowriterDialog(mockService).show('text', 'full');
            const modalDocument = modalWindow.document;
            await vi.waitFor(() => expect(modalDocument.querySelector('[data-role="add-reference"]')).not.toBeNull());

            modalDocument.querySelector('[data-role="add-reference"]').click();
            expectIconsFromModalWindow(modalDocument.querySelector('[data-role="remove-reference"]'));

            modalDocument.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('puts the dialog styles into the modal\'s document', async () => {
            const showPromise = new CowriterDialog(mockService).show('text', 'full');
            const modalDocument = modalWindow.document;
            await vi.waitFor(() => expect(modalDocument.querySelector('[name="cancel"]')).not.toBeNull());

            const styles = [...modalDocument.head.querySelectorAll('style')].map((s) => s.textContent);
            expect(styles.some((css) => css.includes('.cowriter-result {'))).toBe(true);

            modalDocument.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('replaces a style element left by an older version', async () => {
            const modalDocument = modalWindow.document;
            const stale = modalDocument.createElement('style');
            stale.id = 'cowriter-dialog-styles';
            stale.textContent = '.cowriter-result { min-height: 1px; }';
            modalDocument.head.appendChild(stale);

            const showPromise = new CowriterDialog(mockService).show('text', 'full');
            await vi.waitFor(() => expect(modalDocument.querySelector('[name="cancel"]')).not.toBeNull());

            const styles = modalDocument.head.querySelectorAll('#cowriter-dialog-styles');
            expect(styles).toHaveLength(1);
            expect(styles[0].textContent).toContain('min-height: 200px');
            expect(styles[0].textContent).not.toContain('min-height: 1px');

            modalDocument.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        describe('debug copy button', () => {
            const setClipboard = (win, writeText) => {
                Object.defineProperty(win.navigator, 'clipboard', {
                    value: { writeText }, writable: true, configurable: true,
                });
            };

            const openDebugDetails = async () => {
                mockService.executeTask.mockResolvedValue({
                    success: true, content: 'Result', model: 'test-model', finishReason: 'stop',
                });
                const showPromise = new CowriterDialog(mockService).show('input', 'full');
                const modalDocument = modalWindow.document;
                await vi.waitFor(() => expect(modalDocument.querySelector('[name="execute"]')).not.toBeNull());
                modalDocument.querySelector('[name="execute"]').click();
                await vi.waitFor(() => expect(modalDocument.querySelector('[data-role="debug-copy"]')).not.toBeNull());
                return { showPromise, modalDocument };
            };

            afterEach(() => {
                delete navigator.clipboard;
                delete modalWindow.navigator.clipboard;
                delete document.execCommand;
                delete modalWindow.document.execCommand;
            });

            it('writes through the clipboard of the modal\'s window', async () => {
                // In the browser the script's own document is not focused while
                // the modal is open, and its clipboard rejects the write.
                const scriptWrite = vi.fn().mockRejectedValue(new Error('Document is not focused.'));
                const modalWrite = vi.fn().mockResolvedValue(undefined);
                setClipboard(window, scriptWrite);
                setClipboard(modalWindow, modalWrite);

                const { showPromise, modalDocument } = await openDebugDetails();
                const copyBtn = modalDocument.querySelector('[data-role="debug-copy"]');
                copyBtn.click();

                await vi.waitFor(() => expect(copyBtn.textContent).toBe('Copied!'));
                expect(modalWrite).toHaveBeenCalledWith(expect.stringContaining('Model: test-model'));
                expect(scriptWrite).not.toHaveBeenCalled();

                modalDocument.querySelector('[name="cancel"]').click();
                await showPromise.catch(() => {});
            });

            it('falls back to execCommand in the modal\'s document', async () => {
                setClipboard(window, vi.fn().mockRejectedValue(new Error('Not allowed')));
                setClipboard(modalWindow, vi.fn().mockRejectedValue(new Error('Not allowed')));
                const scriptExec = vi.fn().mockReturnValue(true);
                document.execCommand = scriptExec;
                let selectedIn = null;
                const modalExec = vi.fn(() => {
                    const ta = [...modalWindow.document.querySelectorAll('textarea')].find((el) => el.style.opacity === '0');
                    selectedIn = ta ? (ta.closest('.modal') ? 'modal' : 'elsewhere') : 'none';
                    return true;
                });
                modalWindow.document.execCommand = modalExec;

                const { showPromise, modalDocument } = await openDebugDetails();
                const copyBtn = modalDocument.querySelector('[data-role="debug-copy"]');
                copyBtn.click();

                await vi.waitFor(() => expect(modalExec).toHaveBeenCalledWith('copy'));
                expect(scriptExec).not.toHaveBeenCalled();
                expect(selectedIn).toBe('modal');
                await vi.waitFor(() => expect(copyBtn.textContent).toBe('Copied!'));
                expect(modalDocument.querySelector('textarea[style*="opacity"]')).toBeNull();
                // The textarea took the focus for the copy; the button gets it back.
                expect(modalDocument.activeElement).toBe(copyBtn);

                modalDocument.querySelector('[name="cancel"]').click();
                await showPromise.catch(() => {});
            });

            it('falls back when there is no clipboard API (no secure context)', async () => {
                expect(navigator.clipboard).toBeUndefined();
                expect(modalWindow.navigator.clipboard).toBeUndefined();
                const modalExec = vi.fn().mockReturnValue(true);
                modalWindow.document.execCommand = modalExec;

                const { showPromise, modalDocument } = await openDebugDetails();
                const copyBtn = modalDocument.querySelector('[data-role="debug-copy"]');
                copyBtn.click();

                await vi.waitFor(() => expect(modalExec).toHaveBeenCalledWith('copy'));
                await vi.waitFor(() => expect(copyBtn.textContent).toBe('Copied!'));

                modalDocument.querySelector('[name="cancel"]').click();
                await showPromise.catch(() => {});
            });

            it('removes the fallback textarea and keeps the label when execCommand throws', async () => {
                setClipboard(modalWindow, vi.fn().mockRejectedValue(new Error('Not allowed')));
                const modalExec = vi.fn(() => { throw new Error('execCommand unsupported'); });
                modalWindow.document.execCommand = modalExec;
                const unhandled = vi.fn();
                process.on('unhandledRejection', unhandled);
                try {
                    const { showPromise, modalDocument } = await openDebugDetails();
                    const copyBtn = modalDocument.querySelector('[data-role="debug-copy"]');
                    const label = copyBtn.textContent;
                    copyBtn.click();

                    await vi.waitFor(() => expect(modalExec).toHaveBeenCalled());
                    await new Promise((r) => setTimeout(r, 0));
                    expect(modalDocument.querySelector('textarea[style*="opacity"]')).toBeNull();
                    expect(modalDocument.activeElement).toBe(copyBtn);
                    expect(copyBtn.textContent).toBe(label);
                    expect(unhandled).not.toHaveBeenCalled();

                    modalDocument.querySelector('[name="cancel"]').click();
                    await showPromise.catch(() => {});
                } finally {
                    process.off('unhandledRejection', unhandled);
                }
            });

            it('removes the fallback textarea and keeps the label when execCommand returns false', async () => {
                // Current browsers report a refused copy this way instead of throwing.
                setClipboard(modalWindow, vi.fn().mockRejectedValue(new Error('Not allowed')));
                const modalExec = vi.fn().mockReturnValue(false);
                modalWindow.document.execCommand = modalExec;

                const { showPromise, modalDocument } = await openDebugDetails();
                const copyBtn = modalDocument.querySelector('[data-role="debug-copy"]');
                const label = copyBtn.textContent;
                copyBtn.click();

                await vi.waitFor(() => expect(modalExec).toHaveBeenCalledWith('copy'));
                await new Promise((r) => setTimeout(r, 0));
                expect(copyBtn.textContent).toBe(label);
                expect(modalDocument.querySelector('textarea[style*="opacity"]')).toBeNull();
                expect(modalDocument.activeElement).toBe(copyBtn);

                modalDocument.querySelector('[name="cancel"]').click();
                await showPromise.catch(() => {});
            });
        });

        it('adds the dialog styles to the modal\'s document once across module loads', async () => {
            // The module loads again with every FormEngine iframe, while the
            // parent document that shows the modal stays.
            const modalDocument = modalWindow.document;
            const countStyles = () => [...modalDocument.head.querySelectorAll('style')]
                .filter((s) => s.textContent.includes('.cowriter-result {')).length;

            for (let load = 1; load <= 2; load++) {
                if (load > 1) {
                    vi.resetModules();
                    ({ CowriterDialog } = await import('../../Resources/Public/JavaScript/Ckeditor/CowriterDialog.js'));
                    ({ default: Modal } = await import('@typo3/backend/modal.js'));
                    Modal.targetDocument = modalDocument;
                    Modal.renderContentAsync = true;
                }
                const showPromise = new CowriterDialog(mockService).show('text', 'full');
                await vi.waitFor(() => expect(modalDocument.querySelector('[name="cancel"]')).not.toBeNull());
                modalDocument.querySelector('[name="cancel"]').click();
                await expect(showPromise).rejects.toThrow('User cancelled');
            }

            expect(countStyles()).toBe(1);
        });

        it('closes the page dropdown on a pointer press elsewhere in the modal\'s document', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            const modalDocument = modalWindow.document;
            await vi.waitFor(() => expect(modalDocument.querySelector('[data-role="add-reference"]')).not.toBeNull());

            modalDocument.querySelector('[data-role="add-reference"]').click();
            const dropdown = modalDocument.querySelector('[data-role="ref-dropdown"]');
            dialog._renderPageDropdown(
                dropdown, [{ uid: 1, title: 'Page', slug: '' }],
                modalDocument.querySelector('[data-role="ref-search"]'),
                modalDocument.querySelector('[data-role="ref-pid"]'),
            );
            expect(dropdown.style.display).toBe('block');

            modalDocument.querySelector('label.form-label')
                .dispatchEvent(new modalWindow.MouseEvent('pointerdown', { bubbles: true }));
            expect(dropdown.style.display).toBe('none');

            modalDocument.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });
    });

    describe('event listener cleanup', () => {
        it('should remove document click listener when reference row is removed', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');
            const searchInput = document.querySelector('[data-role="ref-search"]');
            const hiddenPid = document.querySelector('[data-role="ref-pid"]');

            // Show the dropdown
            dialog._renderPageDropdown(dropdown, [{ uid: 1, title: 'Page', slug: '' }], searchInput, hiddenPid);
            expect(dropdown.style.display).toBe('block');

            // Outside click should close it
            document.body.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true }));
            expect(dropdown.style.display).toBe('none');

            // Now remove the row
            document.querySelector('[data-role="remove-reference"]').click();
            expect(document.querySelector('[data-role="reference-row"]')).toBeNull();

            // Re-render a dropdown on the same element — it's detached, so this
            // verifies the listener was cleaned up (no error from orphaned handler)
            dropdown.style.display = 'block';
            document.body.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true }));
            // Dropdown is detached and the listener removed: nothing closes it.
            expect(dropdown.style.display).toBe('block');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });
    });

    describe('_resolveTemplate', () => {
        it('should return empty string when template is only {{input}}', async () => {
            const tasksWithInputOnly = [
                { uid: 10, identifier: 'input_only', name: 'Input Only',
                  description: 'Only placeholder', promptTemplate: '{{input}}' },
            ];
            mockService.getTasks.mockResolvedValue({ success: true, tasks: tasksWithInputOnly });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const instruction = document.querySelector('[data-role="instruction"]');
            expect(instruction.value).toBe('');

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });
    });

    describe('_appendStatusLink: href comes from the local route, never the payload', () => {
        const ROUTE = '/typo3/module/cowriter/status';
        let dialog;
        let container;

        beforeEach(() => {
            dialog = new CowriterDialog(mockService);
            mockService._routes = { statusModule: ROUTE };
            container = document.createElement('div');
            document.body.appendChild(container);
        });

        it('should link the locally injected route when the backend signals a status endpoint', () => {
            dialog._appendStatusLink(container, ROUTE);

            const link = container.querySelector('a');
            expect(link).not.toBeNull();
            expect(link.getAttribute('href')).toBe(ROUTE);
            expect(link.getAttribute('target')).toBe('_blank');
            expect(link.getAttribute('rel')).toBe('noopener noreferrer');
        });

        // The whole point of the redesign: whatever the payload carries, it is a
        // signal only. A hostile value must not reach href — and must not
        // suppress the link either, because the href never came from it.
        it.each([
            ['javascript:alert(1)'],
            ['data:text/html,<script>alert(1)</script>'],
            ['https://elsewhere.example/status'],
            ['//elsewhere.example/status'],
        ])('should ignore the payload value %s and still link the local route', (payload) => {
            dialog._appendStatusLink(container, payload);

            expect(container.querySelector('a')?.getAttribute('href')).toBe(ROUTE);
        });

        it('should append nothing when the backend signalled no status endpoint', () => {
            dialog._appendStatusLink(container, undefined);

            expect(container.querySelector('a')).toBeNull();
        });

        it('should append nothing when the route is not configured', () => {
            mockService._routes = {};

            dialog._appendStatusLink(container, ROUTE);

            expect(container.querySelector('a')).toBeNull();
        });

        it.each([
            ['javascript:alert(1)'],
            ['https://elsewhere.example/status'],
        ])('should reject a route of %s, so a mis-injected route cannot become a link', (badRoute) => {
            mockService._routes = { statusModule: badRoute };

            dialog._appendStatusLink(container, 'truthy-signal');

            expect(container.querySelector('a')).toBeNull();
        });
    });

    describe('modal hidden event (Escape/X close)', () => {
        it('should reject promise and abort requests when typo3-modal-hidden fires', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const modal = document.querySelector('.modal');
            expect(modal).not.toBeNull();

            // Dispatch the typo3-modal-hidden event (simulates Escape or X button)
            modal.dispatchEvent(new Event('typo3-modal-hidden'));

            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should abort in-flight request when modal is dismissed via hidden event', async () => {
            // Make executeTask hang
            mockService.executeTask.mockReturnValue(new Promise(() => {}));

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            // Start an execution
            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());

            // Dismiss modal via hidden event
            const modal = document.querySelector('.modal');
            modal.dispatchEvent(new Event('typo3-modal-hidden'));

            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should abort reference AbortControllers when modal is dismissed', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            // Add a reference row (creates an AbortController)
            document.querySelector('[data-role="add-reference"]').click();
            expect(document.querySelector('[data-role="reference-row"]')).not.toBeNull();

            // Dismiss modal
            const modal = document.querySelector('.modal');
            modal.dispatchEvent(new Event('typo3-modal-hidden'));

            await expect(showPromise).rejects.toThrow('User cancelled');
        });
    });

    describe('debounced search input', () => {
        it('should call searchPages after debounce and render dropdown', async () => {
            mockService.searchPages.mockResolvedValue({
                success: true,
                pages: [{ uid: 10, title: 'Test Page', slug: '/test' }],
            });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const searchInput = document.querySelector('[data-role="ref-search"]');
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');

            // Type a query (>= 2 chars to trigger search)
            searchInput.value = 'Test';
            searchInput.dispatchEvent(new Event('input'));

            // Wait for debounce (300ms) + async search
            await vi.waitFor(() => {
                expect(mockService.searchPages).toHaveBeenCalledWith('Test');
            }, { timeout: 1000 });

            // Dropdown should be populated
            await vi.waitFor(() => {
                const items = dropdown.querySelectorAll('[role="option"]');
                expect(items.length).toBe(1);
                expect(items[0].textContent).toContain('Test Page');
            });

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should clear hidden PID on new input', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const searchInput = document.querySelector('[data-role="ref-search"]');
            const hiddenPid = document.querySelector('[data-role="ref-pid"]');

            // Set a PID value first
            hiddenPid.value = '42';

            // Type something
            searchInput.value = 'Te';
            searchInput.dispatchEvent(new Event('input'));

            // PID should be cleared immediately
            expect(hiddenPid.value).toBe('');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should hide dropdown when query is too short', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const searchInput = document.querySelector('[data-role="ref-search"]');
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');

            // Make dropdown visible first
            dropdown.style.display = 'block';
            searchInput.setAttribute('aria-expanded', 'true');

            // Type only 1 char (< 2 minimum)
            searchInput.value = 'T';
            searchInput.dispatchEvent(new Event('input'));

            expect(dropdown.style.display).toBe('none');
            expect(searchInput.getAttribute('aria-expanded')).toBe('false');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should hide dropdown and set aria-expanded false when search fails', async () => {
            mockService.searchPages.mockRejectedValue(new Error('Network error'));

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const searchInput = document.querySelector('[data-role="ref-search"]');
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');

            // Type a query to trigger search
            searchInput.value = 'Fail';
            searchInput.dispatchEvent(new Event('input'));

            // Wait for debounce + error handling
            await vi.waitFor(() => {
                expect(mockService.searchPages).toHaveBeenCalledWith('Fail');
            }, { timeout: 1000 });

            // Wait for the error handler to hide the dropdown
            await vi.waitFor(() => {
                expect(dropdown.style.display).toBe('none');
            });
            expect(searchInput.getAttribute('aria-expanded')).toBe('false');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });
    });

    describe('keyboard navigation edge cases', () => {
        it('should handle Escape when dropdown is hidden and has no items', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const searchInput = document.querySelector('[data-role="ref-search"]');
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');

            // Dropdown is empty and hidden — press Escape
            dropdown.style.display = 'none';
            searchInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

            // Should still be hidden and aria-expanded false
            expect(dropdown.style.display).toBe('none');
            expect(searchInput.getAttribute('aria-expanded')).toBe('false');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should handle ArrowDown/ArrowUp when dropdown has no items', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const searchInput = document.querySelector('[data-role="ref-search"]');

            // No items in dropdown, pressing arrows should not throw
            searchInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
            searchInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true }));
            searchInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should wrap ArrowUp from first item to last item', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');
            const searchInput = document.querySelector('[data-role="ref-search"]');
            const hiddenPid = document.querySelector('[data-role="ref-pid"]');

            dialog._renderPageDropdown(dropdown, [
                { uid: 1, title: 'First', slug: '/first' },
                { uid: 2, title: 'Second', slug: '/second' },
                { uid: 3, title: 'Third', slug: '/third' },
            ], searchInput, hiddenPid);

            const items = dropdown.querySelectorAll('[role="option"]');

            // Navigate to first item
            searchInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
            expect(items[0].classList.contains('active')).toBe(true);

            // ArrowUp from first should wrap to last
            searchInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true }));
            expect(items[2].classList.contains('active')).toBe(true);
            expect(items[0].classList.contains('active')).toBe(false);

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should remove aria-activedescendant on Escape with items visible', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full', '', null);
            await vi.waitFor(() => expect(document.querySelector('[data-role="add-reference"]')).not.toBeNull());

            document.querySelector('[data-role="add-reference"]').click();
            const dropdown = document.querySelector('[data-role="ref-dropdown"]');
            const searchInput = document.querySelector('[data-role="ref-search"]');
            const hiddenPid = document.querySelector('[data-role="ref-pid"]');

            dialog._renderPageDropdown(dropdown, [
                { uid: 1, title: 'Page', slug: '/' },
            ], searchInput, hiddenPid);

            // Navigate to item
            searchInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
            expect(searchInput.getAttribute('aria-activedescendant')).toBeTruthy();

            // Press Escape — should remove aria-activedescendant
            searchInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
            expect(searchInput.hasAttribute('aria-activedescendant')).toBe(false);
            expect(dropdown.style.display).toBe('none');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });
    });

    describe('sanitizer target=_blank rel enforcement', () => {
        it('should add rel="noopener noreferrer" to target=_blank links', async () => {
            mockService.executeTask.mockResolvedValue({
                success: true,
                content: '<a href="https://example.com" target="_blank">Link</a>',
                model: 'gpt-4o',
            });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                return document.querySelector('[data-role="result-preview"] a');
            });

            const link = document.querySelector('[data-role="result-preview"] a');
            expect(link.getAttribute('target')).toBe('_blank');
            expect(link.getAttribute('rel')).toBe('noopener noreferrer');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should not add rel to links without target=_blank', async () => {
            mockService.executeTask.mockResolvedValue({
                success: true,
                content: '<a href="https://example.com">Normal Link</a>',
                model: 'gpt-4o',
            });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                return document.querySelector('[data-role="result-preview"] a');
            });

            const link = document.querySelector('[data-role="result-preview"] a');
            expect(link.getAttribute('rel')).toBeNull();

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });
    });

    describe('_showDebugDetails with debugMessages', () => {
        it('should display debugMessages when present in result', async () => {
            mockService.executeTask.mockResolvedValue({
                success: true,
                content: '<p>Result</p>',
                model: 'test-model',
                finishReason: 'stop',
                debugMessages: [
                    { role: 'system', content: 'You are a helpful assistant.' },
                    { role: 'user', content: 'Improve this text.' },
                ],
            });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('input text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                return document.querySelector('[data-role="debug-details"]');
            });

            const debugContent = document.querySelector('[data-role="debug-details"]').textContent;
            expect(debugContent).toContain('--- [system] ---');
            expect(debugContent).toContain('You are a helpful assistant.');
            expect(debugContent).toContain('--- [user] ---');
            expect(debugContent).toContain('Improve this text.');
            // Should NOT have the fallback sections
            expect(debugContent).not.toContain('--- Input text ---');
            expect(debugContent).not.toContain('--- Instruction sent ---');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should show debug details without thinking section when thinking is absent', async () => {
            mockService.executeTask.mockResolvedValue({
                success: true,
                content: 'Plain result',
                model: 'gpt-4o',
                finishReason: 'stop',
                // no thinking, no usage, no debugMessages
            });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('input', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                return document.querySelector('[data-role="debug-details"]');
            });

            const debugContent = document.querySelector('[data-role="debug-details"]').textContent;
            expect(debugContent).not.toContain('--- Thinking ---');
            // Should have fallback sections since no debugMessages
            expect(debugContent).toContain('--- Input text ---');
            expect(debugContent).toContain('--- Instruction sent ---');

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });
    });

    describe('_updateButtonVisibility edge cases', () => {
        it('should handle null modal gracefully', () => {
            const dialog = new CowriterDialog(mockService);
            // Should not throw
            expect(() => dialog._updateButtonVisibility(null, 'idle')).not.toThrow();
            expect(() => dialog._updateButtonVisibility(null, 'result')).not.toThrow();
        });

        it('should handle modal without buttons gracefully', () => {
            const dialog = new CowriterDialog(mockService);
            const emptyDiv = document.createElement('div');
            // Should not throw when buttons are not found
            expect(() => dialog._updateButtonVisibility(emptyDiv, 'idle')).not.toThrow();
            expect(() => dialog._updateButtonVisibility(emptyDiv, 'result')).not.toThrow();
        });

        it('should show Reset and Insert buttons in loading state as hidden', async () => {
            // Make executeTask hang
            mockService.executeTask.mockReturnValue(new Promise(() => {}));

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[name="execute"]').click();

            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                expect(preview.textContent).toContain('Generating');
            });

            // In loading state, Reset and Insert should be hidden
            expect(document.querySelector('[name="reset"]').style.display).toBe('none');
            expect(document.querySelector('[name="insert"]').style.display).toBe('none');

            document.querySelector('[name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });
    });

    describe('debug copy button', () => {
        it('should copy to clipboard when copy button is clicked', async () => {
            // Mock clipboard API
            const writeTextMock = vi.fn().mockResolvedValue(undefined);
            Object.defineProperty(navigator, 'clipboard', {
                value: { writeText: writeTextMock },
                writable: true,
                configurable: true,
            });

            mockService.executeTask.mockResolvedValue({
                success: true,
                content: 'Result',
                model: 'test-model',
                finishReason: 'stop',
            });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('input', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                return document.querySelector('[data-role="debug-copy"]');
            });

            const copyBtn = document.querySelector('[data-role="debug-copy"]');
            copyBtn.click();

            await vi.waitFor(() => {
                expect(writeTextMock).toHaveBeenCalled();
            });

            // Button text should change to "Copied!"
            await vi.waitFor(() => {
                expect(copyBtn.textContent).toBe('Copied!');
            });

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should fall back to execCommand when clipboard API fails', async () => {
            // Mock clipboard API to fail
            Object.defineProperty(navigator, 'clipboard', {
                value: { writeText: vi.fn().mockRejectedValue(new Error('Not allowed')) },
                writable: true,
                configurable: true,
            });

            // Define and mock execCommand (not available in jsdom by default)
            document.execCommand = vi.fn().mockReturnValue(true);
            const execCommandSpy = document.execCommand;

            mockService.executeTask.mockResolvedValue({
                success: true,
                content: 'Result',
                model: 'test-model',
                finishReason: 'stop',
            });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('input', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                return document.querySelector('[data-role="debug-copy"]');
            });

            const copyBtn = document.querySelector('[data-role="debug-copy"]');
            copyBtn.click();

            // Wait for the fallback to be invoked
            await vi.waitFor(() => {
                expect(execCommandSpy).toHaveBeenCalledWith('copy');
            });

            // Button text should still show "Copied!"
            await vi.waitFor(() => {
                expect(copyBtn.textContent).toBe('Copied!');
            });

            delete document.execCommand;

            document.querySelector('[name="cancel"]').click();
            await showPromise.catch(() => {});
        });
    });
});
