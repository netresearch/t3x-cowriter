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

        it('should start slider at stop 0 (selection) when text is selected', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('some selected text', 'full content');

            await vi.waitFor(() => {
                const slider = document.querySelector('[data-role="context-slider"]');
                expect(slider).not.toBeNull();
                expect(slider.min).toBe('0');
                expect(slider.value).toBe('0');
            });

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should start slider at stop 1 (text) when no text is selected', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('', 'full content');

            await vi.waitFor(() => {
                const slider = document.querySelector('[data-role="context-slider"]');
                expect(slider).not.toBeNull();
                expect(slider.min).toBe('1');
                expect(slider.value).toBe('1');
            });

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
                    1, 'my selected text', 'selection', '', '',
                    'selection', null, [],
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
                    1, 'full editor content here', 'content_element', '', '',
                    'text', null, [],
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
                    1, 'text', 'selection', 'Write in formal tone', '',
                    'selection', null, [],
                );
            });

            document.querySelector('[data-name="execute"]').click();
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
                expect(preview.style.display).toBe('block');
                // HTML is rendered as DOM, not as literal text
                expect(preview.querySelector('strong')).not.toBeNull();
                expect(preview.textContent).toContain('Improved');
                expect(preview.textContent).toContain('text');
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
                    2, 'text', 'selection', '', '',
                    'selection', null, [],
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

    describe('context zoom slider', () => {
        it('should render range slider instead of radio buttons', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected', 'full', '', null);

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="context-slider"]')).not.toBeNull();
            });

            const slider = document.querySelector('[data-role="context-slider"]');
            expect(slider.tagName).toBe('INPUT');
            expect(slider.type).toBe('range');
            expect(slider.min).toBe('0');
            expect(slider.max).toBe('5');

            // Radio buttons should NOT exist
            expect(document.querySelector('input[name="cowriter-context"]')).toBeNull();

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should disable stop 0 (selection) when no text selected', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('', 'full', '', null);

            await vi.waitFor(() => {
                const slider = document.querySelector('[data-role="context-slider"]');
                expect(slider).not.toBeNull();
                expect(slider.min).toBe('1');
                expect(slider.value).toBe('1');
            });

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should start at stop 0 (selection) when text is selected', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected text', 'full', '', null);

            await vi.waitFor(() => {
                const slider = document.querySelector('[data-role="context-slider"]');
                expect(slider).not.toBeNull();
                expect(slider.min).toBe('0');
                expect(slider.value).toBe('0');
            });

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should show scope label below slider', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected', 'full', '', null);

            await vi.waitFor(() => {
                const label = document.querySelector('[data-role="scope-label"]');
                expect(label).not.toBeNull();
                expect(label.textContent).toContain('Selection');
            });

            document.querySelector('[data-name="cancel"]').click();
            await showPromise.catch(() => {});
        });

        it('should update scope label when slider changes', async () => {
            const dialog = new CowriterDialog(mockService);
            const showPromise = dialog.show('selected', 'full', '',
                { table: 'tt_content', uid: 42, field: 'bodytext' });

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="context-slider"]')).not.toBeNull();
            });

            mockService.getContext = vi.fn().mockResolvedValue({
                success: true,
                summary: '3 elements, ~42 words',
                wordCount: 42,
            });

            const slider = document.querySelector('[data-role="context-slider"]');
            slider.value = '3'; // page
            slider.dispatchEvent(new Event('input'));

            await vi.waitFor(() => {
                const label = document.querySelector('[data-role="scope-label"]');
                expect(label.textContent).toContain('Page');
            });

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

            const slider = document.querySelector('[data-role="context-slider"]');
            slider.value = '3';
            slider.dispatchEvent(new Event('input'));

            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                // Slider at 3 (page) means contextType='content_element', context='full'
                expect(mockService.executeTask).toHaveBeenCalledWith(
                    1, 'full', 'content_element', '', '',
                    'page', recordContext, [],
                );
            });

            document.querySelector('[data-name="execute"]').click();
            await showPromise;
        });

        it('should map slider stops to correct scope values', async () => {
            const dialog = new CowriterDialog(mockService);
            const rc = { table: 'tt_content', uid: 1, field: 'bodytext' };
            const showPromise = dialog.show('text', 'full', '', rc);

            await vi.waitFor(() => {
                expect(document.querySelector('[data-role="context-slider"]')).not.toBeNull();
            });

            const slider = document.querySelector('[data-role="context-slider"]');

            for (let i = 0; i <= 5; i++) {
                slider.value = String(i);
                slider.dispatchEvent(new Event('input'));

                const label = document.querySelector('[data-role="scope-label"]');
                expect(label).not.toBeNull();
            }

            // Execute at stop 4 (ancestors_1) — scope > 0 means content_element
            slider.value = '4';
            slider.dispatchEvent(new Event('input'));
            document.querySelector('[data-name="execute"]').click();

            await vi.waitFor(() => {
                expect(mockService.executeTask).toHaveBeenCalledWith(
                    1, 'full', 'content_element', '', '',
                    'ancestors_1', rc, [],
                );
            });

            document.querySelector('[data-name="execute"]').click();
            await showPromise;
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

            expect(rows[0].querySelector('[data-role="ref-pid"]')).not.toBeNull();
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
                    1, 'text', 'selection', '', '',
                    'selection', rc,
                    [{ pid: 5, relation: 'style guide' }],
                );
            });

            document.querySelector('[data-name="execute"]').click();
            await showPromise;
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
                    1, 'text', 'selection', '', '',
                    'selection', rc,
                    [{ pid: 5, relation: 'ref' }],
                );
            });

            document.querySelector('[data-name="execute"]').click();
            await showPromise;
        });
    });
});
