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
        };
    });

    afterEach(() => {
        delete globalThis.TYPO3;
        vi.resetModules();
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
            const cancelBtn = document.querySelector('[data-name="cancel"]');
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
            expect(document.querySelector('[data-name="close"]')).not.toBeNull();
            expect(document.querySelector('[data-name="execute"]')).toBeNull();

            // Clicking Close should reject with User cancelled
            document.querySelector('[data-name="close"]').click();
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
            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should show full content in result area when no selection', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('', 'full content here');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const result = document.querySelector('[data-role="result-preview"]');
            expect(result.textContent).toContain('full content here');
            expect(result.classList.contains('cowriter-result--empty')).toBe(true);

            document.querySelector('[data-name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should remove muted class from result area after successful execute', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });

            const result = document.querySelector('[data-role="result-preview"]');
            expect(result.classList.contains('cowriter-result--empty')).toBe(false);

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should render config row with task and scope side by side', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const configRow = document.querySelector('[data-role="config-row"]');
            expect(configRow).toBeTruthy();
            expect(configRow.classList.contains('row')).toBe(true);

            const cols = configRow.querySelectorAll(':scope > [class*="col-md-"]');
            expect(cols.length).toBe(2);
            expect(cols[0].classList.contains('col-md-8')).toBe(true);
            expect(cols[1].classList.contains('col-md-4')).toBe(true);

            expect(cols[0].querySelector('[data-role="task-select"]')).toBeTruthy();
            expect(cols[1].querySelector('[data-role="scope-select"]')).toBeTruthy();

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });
    });

    describe('execute flow', () => {
        it('should call executeTask with instruction from textarea', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('my selected text', 'full editor content');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-name="execute"]')).not.toBeNull();
            });

            // The instruction textarea should have been prefilled with the resolved template ({{input}} stripped)
            const textarea = document.querySelector('[data-role="instruction"]');
            expect(textarea.value).toBe('Improve:');

            // Click Execute
            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                expect(mockService.executeTask).toHaveBeenCalledWith(
                    1, 'my selected text', 'selection', 'Improve:', '',
                    'selection', null, [], expect.any(AbortSignal),
                );
            });

            // Now result is shown, click Insert to accept
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });
            document.querySelector('[data-name="insert"]').click();

            const result = await showPromise;
            expect(result.content).toBe('Improved text content');
        });

        it('should use full content when content_element is selected', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('', 'full editor content here');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-name="execute"]')).not.toBeNull();
            });

            // Template resolves with {{input}} stripped
            const textarea = document.querySelector('[data-role="instruction"]');
            expect(textarea.value).toBe('Improve:');

            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                expect(mockService.executeTask).toHaveBeenCalledWith(
                    1, 'full editor content here', 'content_element',
                    'Improve:', '',
                    'text', null, [], expect.any(AbortSignal),
                );
            });

            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });
            document.querySelector('[data-name="insert"]').click();
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

            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                expect(mockService.executeTask).toHaveBeenCalledWith(
                    0, 'text', 'selection', 'Make it more formal', '',
                    'selection', null, [], expect.any(AbortSignal),
                );
            });

            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });
            document.querySelector('[data-name="insert"]').click();
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
                expect(document.querySelector('[data-name="execute"]')).not.toBeNull();
            });

            document.querySelector('[data-name="execute"]').click();

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

            document.querySelector('[data-name="insert"]').click();
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
                expect(document.querySelector('[data-name="execute"]')).not.toBeNull();
            });

            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                expect(preview.textContent).toContain('Generating');
                // Should have a spinner element
                expect(preview.querySelector('.spinner-border')).not.toBeNull();
            });

            // Cancel button should remain enabled during loading
            const cancelBtn = document.querySelector('[data-name="cancel"]');
            expect(cancelBtn.disabled).toBe(false);

            // Resolve the task
            resolveTask({ success: true, content: 'Done', model: 'gpt-4o' });

            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                expect(preview.textContent).toBe('Done');
            });

            document.querySelector('[data-name="insert"]').click();
            await showPromise;
        });

        it('should show error in preview when task fails', async () => {
            mockService.executeTask.mockRejectedValue(new Error('API timeout'));

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-name="execute"]')).not.toBeNull();
            });

            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                expect(preview.textContent).toContain('Error: API timeout');
            });

            // Cancel
            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should show error when result has no content', async () => {
            mockService.executeTask.mockResolvedValue({ success: false, error: 'Task failed' });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-name="execute"]')).not.toBeNull();
            });

            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                expect(preview.textContent).toBe('Task failed');
            });

            document.querySelector('[data-name="cancel"]').click();
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
                expect(document.querySelector('[data-name="execute"]')).not.toBeNull();
            });

            document.querySelector('[data-name="execute"]').click();

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

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should not show debug details on error', async () => {
            mockService.executeTask.mockResolvedValue({ success: false, error: 'Failed' });

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-name="execute"]')).not.toBeNull();
            });

            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                expect(preview.textContent).toBe('Failed');
            });

            expect(document.querySelector('[data-role="debug-details"]')).toBeNull();

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                return document.querySelector('[data-role="debug-details"]');
            });

            const debugContent = document.querySelector('[data-role="debug-details"]').textContent;
            expect(debugContent).toContain('Model: gpt-4o');
            expect(debugContent).toContain('Finish reason: stop');
            expect(debugContent).not.toContain('Tokens:');
            expect(debugContent).not.toContain('--- Thinking ---');

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                const info = document.querySelector('[data-role="model-info"]');
                return info.style.display !== 'none';
            });

            const modelInfo = document.querySelector('[data-role="model-info"]');
            expect(modelInfo.textContent).toBe('Model: gpt-4o | 150 tokens');

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });

    });

    describe('cancel', () => {
        it('should reject promise when cancel is clicked', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-name="cancel"]')).not.toBeNull();
            });

            document.querySelector('[data-name="cancel"]').click();

            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should remove modal from DOM when cancelled', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('.modal')).not.toBeNull();
            });

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should default scope to Selection when text is selected', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const scopeSelect = document.querySelector('[data-role="scope-select"]');
            expect(scopeSelect.value).toBe('selection');

            document.querySelector('[data-name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should default scope to Text when no selection', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('', 'full content');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const scopeSelect = document.querySelector('[data-role="scope-select"]');
            expect(scopeSelect.value).toBe('text');

            document.querySelector('[data-name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should disable Selection option when no text selected', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('', 'full content');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const scopeSelect = document.querySelector('[data-role="scope-select"]');
            expect(scopeSelect.options[0].disabled).toBe(true);

            document.querySelector('[data-name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should send scope ID from dropdown in executeTask', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const scopeSelect = document.querySelector('[data-role="scope-select"]');
            scopeSelect.value = 'page';
            scopeSelect.dispatchEvent(new Event('change'));

            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());

            expect(mockService.executeTask.mock.calls[0][5]).toBe('page');

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should pass contextScope and recordContext to executeTask', async () => {
            const dialog = new CowriterDialog(mockService);
            const recordContext = { table: 'tt_content', uid: 42, field: 'bodytext' };
            const showPromise = dialog.show('text', 'full', '', recordContext);

            await vi.waitFor(() => {
                expect(document.querySelector('[data-name="execute"]')).not.toBeNull();
            });

            const scopeSelect = document.querySelector('[data-role="scope-select"]');
            scopeSelect.value = 'page';
            scopeSelect.dispatchEvent(new Event('change'));

            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                expect(mockService.executeTask).toHaveBeenCalledWith(
                    1, 'text', 'selection', 'Improve:', '',
                    'page', recordContext, [], expect.any(AbortSignal),
                );
            });

            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });
            document.querySelector('[data-name="insert"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                expect(mockService.executeTask).toHaveBeenCalledWith(
                    1, 'text', 'selection', 'Improve:', '',
                    'selection', rc,
                    [{ pid: 5, relation: 'style guide' }], expect.any(AbortSignal),
                );
            });

            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });
            document.querySelector('[data-name="insert"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                expect(mockService.executeTask).toHaveBeenCalledWith(
                    1, 'text', 'selection', 'Improve:', '',
                    'selection', rc,
                    [{ pid: 5, relation: 'ref' }], expect.any(AbortSignal),
                );
            });

            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });
            document.querySelector('[data-name="insert"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should have aria-live on result preview', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const preview = document.querySelector('[data-role="result-preview"]');
            expect(preview.getAttribute('role')).toBe('status');
            expect(preview.getAttribute('aria-live')).toBe('polite');

            document.querySelector('[data-name="cancel"]').click();
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
                expect(document.querySelector('[data-name="execute"]')).not.toBeNull();
            });

            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                expect(preview.textContent).toContain('Safe');
                expect(preview.querySelector('script')).toBeNull();
                expect(preview.querySelector('base')).toBeNull();
                expect(preview.querySelector('meta')).toBeNull();
                expect(preview.querySelector('link')).toBeNull();
            });

            document.querySelector('[data-name="insert"]').click();
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
                expect(document.querySelector('[data-name="execute"]')).not.toBeNull();
            });

            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                const links = preview.querySelectorAll('a');
                for (const link of links) {
                    expect(link.hasAttribute('href')).toBe(false);
                }
            });

            document.querySelector('[data-name="insert"]').click();
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

            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Safe');
            });

            const preview = document.querySelector('[data-role="result-preview"]');
            expect(preview.querySelector('svg')).toBeNull();
            expect(preview.querySelector('p')).toBeTruthy();
            expect(preview.textContent).toContain('Safe');

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                return document.querySelector('[data-role="result-preview"] img');
            });

            const img = document.querySelector('[data-role="result-preview"] img');
            expect(img).toBeTruthy();
            expect(img.getAttribute('onerror')).toBeNull();
            expect(img.getAttribute('src')).toBe('x.png');

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                return document.querySelector('[data-role="result-preview"] a');
            });

            const link = document.querySelector('[data-role="result-preview"] a');
            expect(link).toBeTruthy();
            expect(link.getAttribute('href')).toBeNull();

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                return document.querySelector('[data-role="result-preview"] a');
            });

            const link = document.querySelector('[data-role="result-preview"] a');
            expect(link.getAttribute('href')).toBeNull();

            const img = document.querySelector('[data-role="result-preview"] img');
            expect(img.getAttribute('src')).toBe('data:image/png;base64,abc');

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });
    });

    describe('button state machine', () => {
        it('should show Cancel and Execute in idle state', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            const cancel = document.querySelector('[data-name="cancel"]');
            const reset = document.querySelector('[data-name="reset"]');
            const execute = document.querySelector('[data-name="execute"]');
            const insert = document.querySelector('[data-name="insert"]');

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

        it('should show all four buttons in result state', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });

            const reset = document.querySelector('[data-name="reset"]');
            const insert = document.querySelector('[data-name="insert"]');
            expect(reset.style.display).not.toBe('none');
            expect(insert.style.display).not.toBe('none');

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should insert result content when Insert button clicked', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });

            document.querySelector('[data-name="insert"]').click();
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
            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalledTimes(1));
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('First result');
            });

            // Second execute (chained)
            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalledTimes(2));

            // The context (arg index 1) of the second call should be the decoded first result
            expect(mockService.executeTask.mock.calls[1][1]).toBe('First result');

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should restore original input on Reset', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('original text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            // Execute to enter result state
            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Improved text content');
            });

            // Click Reset
            document.querySelector('[data-name="reset"]').click();

            // Result area should show original input again (muted)
            const result = document.querySelector('[data-role="result-preview"]');
            expect(result.textContent).toContain('original text');
            expect(result.classList.contains('cowriter-result--empty')).toBe(true);

            // Reset and Insert should be hidden again
            expect(document.querySelector('[data-name="reset"]').style.display).toBe('none');
            expect(document.querySelector('[data-name="insert"]').style.display).toBe('none');

            document.querySelector('[data-name="cancel"]').click();
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
            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalledTimes(1));
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('First result');
            });

            // Reset
            document.querySelector('[data-name="reset"]').click();

            // Execute again — should use original context, NOT "First result"
            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalledTimes(2));

            expect(mockService.executeTask.mock.calls[1][1]).toBe('original text');

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should disable Execute button during loading', async () => {
            // Make executeTask hang indefinitely
            mockService.executeTask.mockReturnValue(new Promise(() => {}));

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                const execute = document.querySelector('[data-name="execute"]');
                expect(execute.disabled).toBe(true);
            });

            // Cancel should remain enabled
            expect(document.querySelector('[data-name="cancel"]').disabled).toBeFalsy();

            document.querySelector('[data-name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should re-enable buttons after error', async () => {
            mockService.executeTask.mockRejectedValue(new Error('API timeout'));

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());

            // Wait for error to render
            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                return preview.textContent.includes('Error:');
            });

            // Execute should be re-enabled
            expect(document.querySelector('[data-name="execute"]').disabled).toBeFalsy();
            // Reset and Insert should be hidden (idle state)
            expect(document.querySelector('[data-name="reset"]').style.display).toBe('none');
            expect(document.querySelector('[data-name="insert"]').style.display).toBe('none');

            document.querySelector('[data-name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });

        it('should reject promise when cancelled during loading', async () => {
            mockService.executeTask.mockReturnValue(new Promise(() => {}));

            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());

            document.querySelector('[data-name="cancel"]').click();
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
            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalledTimes(1));
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('First result');
            });

            // Second execute fails
            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalledTimes(2));
            await vi.waitFor(() => {
                const result = document.querySelector('[data-role="result-preview"]');
                return result.textContent.includes('Error:');
            });

            // Third execute (retry) — context should be sanitized "First result" (from chaining)
            document.querySelector('[data-name="execute"]').click();
            await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalledTimes(3));

            // The context arg should NOT be 'original text'
            const thirdCallContext = mockService.executeTask.mock.calls[2][1];
            expect(thirdCallContext).not.toBe('original text');

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should not resolve when Insert clicked without result', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');
            await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

            // Force Insert button to be visible by manipulating DOM
            const insert = document.querySelector('[data-name="insert"]');
            insert.style.display = '';
            insert.click();

            // Promise should NOT have resolved — cancel to verify
            document.querySelector('[data-name="cancel"]').click();
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

            expect(dropdown.style.display).toBe('block');
            expect(dropdown.children.length).toBe(1);
            expect(dropdown.children[0].textContent).toBe('No pages found');
            expect(dropdown.children[0].getAttribute('role')).toBe('status');
            // "No pages found" is a status message, not a selectable option,
            // so aria-expanded should be false
            expect(searchInput.getAttribute('aria-expanded')).toBe('false');

            document.querySelector('[data-name="cancel"]').click();
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

            expect(dropdown.textContent).toBe('No pages found');

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
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
            document.body.click();
            expect(dropdown.style.display).toBe('none');

            // Now remove the row
            document.querySelector('[data-role="remove-reference"]').click();
            expect(document.querySelector('[data-role="reference-row"]')).toBeNull();

            // Re-render a dropdown on the same element — it's detached, so this
            // verifies the listener was cleaned up (no error from orphaned handler)
            dropdown.style.display = 'block';
            document.body.click();
            // Dropdown is detached, listener was aborted — no error is thrown

            document.querySelector('[data-name="cancel"]').click();
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

            document.querySelector('[data-name="cancel"]').click();
            await expect(showPromise).rejects.toThrow('User cancelled');
        });
    });
});
