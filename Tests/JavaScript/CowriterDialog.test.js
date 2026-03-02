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
        { uid: 1, identifier: 'cowriter_improve', name: 'Improve Text', description: 'Enhance readability' },
        { uid: 2, identifier: 'cowriter_summarize', name: 'Summarize', description: 'Create a summary' },
        { uid: 3, identifier: 'cowriter_translate_en', name: 'Translate to English', description: 'Translate content' },
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

        it('should render task select with options', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="task-select"]')).not.toBeNull();
            });

            const select = document.querySelector('[data-role="task-select"]');
            expect(select.options.length).toBe(3);
            expect(select.options[0].textContent).toBe('Improve Text');
            expect(select.options[0].value).toBe('1');
            expect(select.options[1].textContent).toBe('Summarize');

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

        it('should pre-select "Selected text" when text is selected', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('some selected text', 'full content');

            await vi.waitFor(() => {
                expect(document.querySelector('input[value="selection"]')).not.toBeNull();
            });

            const selectionRadio = document.querySelector('input[value="selection"]');
            const contentRadio = document.querySelector('input[value="content_element"]');

            expect(selectionRadio.checked).toBe(true);
            expect(selectionRadio.disabled).toBe(false);
            expect(contentRadio.checked).toBe(false);

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should pre-select "Whole content" when no text is selected', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('', 'full content');

            await vi.waitFor(() => {
                expect(document.querySelector('input[value="content_element"]')).not.toBeNull();
            });

            const selectionRadio = document.querySelector('input[value="selection"]');
            const contentRadio = document.querySelector('input[value="content_element"]');

            expect(selectionRadio.checked).toBe(false);
            expect(selectionRadio.disabled).toBe(true);
            expect(contentRadio.checked).toBe(true);

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should have ad-hoc rules textarea', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="ad-hoc-rules"]')).not.toBeNull();
            });

            const textarea = document.querySelector('[data-role="ad-hoc-rules"]');
            expect(textarea.tagName).toBe('TEXTAREA');
            expect(textarea.placeholder).toContain('formal tone');

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should have hidden result preview initially', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="result-preview"]')).not.toBeNull();
            });

            const preview = document.querySelector('[data-role="result-preview"]');
            expect(preview.style.display).toBe('none');

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });
    });

    describe('execute flow', () => {
        it('should call executeTask with correct parameters', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('my selected text', 'full editor content');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-name="execute"]')).not.toBeNull();
            });

            // Click Execute
            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                expect(mockService.executeTask).toHaveBeenCalledWith(
                    1, 'my selected text', 'selection', '',
                );
            });

            // Now result is shown, click again to Insert
            document.querySelector('[data-name="execute"]').click();

            const result = await showPromise;
            expect(result.content).toBe('Improved text content');
        });

        it('should use full content when content_element is selected', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('', 'full editor content here');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-name="execute"]')).not.toBeNull();
            });

            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                expect(mockService.executeTask).toHaveBeenCalledWith(
                    1, 'full editor content here', 'content_element', '',
                );
            });

            document.querySelector('[data-name="execute"]').click();
            const result = await showPromise;
            expect(result.content).toBe('Improved text content');
        });

        it('should pass ad-hoc rules to executeTask', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="ad-hoc-rules"]')).not.toBeNull();
            });

            // Fill in rules
            const textarea = document.querySelector('[data-role="ad-hoc-rules"]');
            textarea.value = 'Write in formal tone';

            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                expect(mockService.executeTask).toHaveBeenCalledWith(
                    1, 'text', 'selection', 'Write in formal tone',
                );
            });

            document.querySelector('[data-name="execute"]').click();
            await showPromise;
        });

        it('should show result in preview area', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-name="execute"]')).not.toBeNull();
            });

            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                expect(preview.textContent).toBe('Improved text content');
                expect(preview.style.display).toBe('block');
            });

            // Model info should be shown
            const modelInfo = document.querySelector('[data-role="model-info"]');
            expect(modelInfo.textContent).toBe('Model: gpt-4o');
            expect(modelInfo.style.display).toBe('block');

            document.querySelector('[data-name="execute"]').click();
            await showPromise;
        });

        it('should show "Generating..." while loading', async () => {
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
            });

            // Resolve the task
            resolveTask({ success: true, content: 'Done', model: 'gpt-4o' });

            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                expect(preview.textContent).toBe('Done');
            });

            document.querySelector('[data-name="execute"]').click();
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

        it('should reset state when task selection changes after result', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('text', 'full');

            await vi.waitFor(() => {
                expect(document.querySelector('[data-name="execute"]')).not.toBeNull();
            });

            // Execute first
            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                const preview = document.querySelector('[data-role="result-preview"]');
                expect(preview.textContent).toBe('Improved text content');
            });

            // Change task — should reset to execute mode
            const select = document.querySelector('[data-role="task-select"]');
            select.value = '2';
            select.dispatchEvent(new Event('change'));

            // Now clicking execute should re-execute (not insert)
            mockService.executeTask.mockResolvedValue({
                success: true,
                content: 'Summarized content',
                model: 'gpt-4o',
            });

            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                expect(mockService.executeTask).toHaveBeenCalledTimes(2);
                expect(mockService.executeTask).toHaveBeenLastCalledWith(
                    2, 'text', 'selection', '',
                );
            });

            // Insert the new result
            document.querySelector('[data-name="execute"]').click();
            const result = await showPromise;
            expect(result.content).toBe('Summarized content');
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
});
