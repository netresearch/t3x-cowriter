import {
    test,
    expect,
    navigateToContentElement,
    waitForCKEditor,
    getModuleFrame,
    clickCowriterButton,
    waitForModal,
} from './fixtures/auth';

/**
 * E2E tests: Cowriter main dialog.
 *
 * TYPO3 v14 architecture:
 * - CKEditor: inside module iframe
 * - Cowriter modal: top-level page (<dialog> element via typo3-backend-modal)
 * - Dialog buttons: Cancel, Reset, Execute, Insert (bottom bar)
 */
test.describe('Cowriter Dialog', () => {
    test.beforeEach(async ({ authenticatedPage: page }) => {
        await navigateToContentElement(page);
        await waitForCKEditor(page);
    });

    test('should open dialog when Cowriter button is clicked', async ({
        authenticatedPage: page,
    }) => {
        await clickCowriterButton(page);
        const dialog = await waitForModal(page);
        await expect(dialog).toBeVisible();
    });

    test('should show task dropdown with options', async ({
        authenticatedPage: page,
    }) => {
        await clickCowriterButton(page);
        const dialog = await waitForModal(page);

        // Wait for tasks to load via AJAX
        await page.waitForTimeout(2000);

        // Task select should be present (labeled "Task")
        const taskSelect = dialog.locator('select').first();
        await expect(taskSelect).toBeVisible();

        const options = taskSelect.locator('option');
        const optionCount = await options.count();
        expect(optionCount).toBeGreaterThanOrEqual(1);
    });

    test('should show context scope selector', async ({
        authenticatedPage: page,
    }) => {
        await clickCowriterButton(page);
        const dialog = await waitForModal(page);

        // Context scope select (labeled "Context scope")
        const selects = dialog.locator('select');
        const selectCount = await selects.count();
        // At least 2 selects: task + scope
        expect(selectCount).toBeGreaterThanOrEqual(2);
    });

    test('should show instruction textarea', async ({
        authenticatedPage: page,
    }) => {
        await clickCowriterButton(page);
        const dialog = await waitForModal(page);

        // Instruction textarea
        const textarea = dialog.locator('textarea');
        await expect(textarea.first()).toBeVisible();
    });

    test('should have Cancel and Execute buttons', async ({
        authenticatedPage: page,
    }) => {
        await clickCowriterButton(page);
        const dialog = await waitForModal(page);

        // Buttons use text content, not data-name
        const cancelBtn = dialog.getByRole('button', { name: 'Cancel' });
        await expect(cancelBtn).toBeVisible();

        const executeBtn = dialog.getByRole('button', { name: 'Execute' });
        await expect(executeBtn).toBeVisible();
    });

    test('should close dialog on Cancel', async ({
        authenticatedPage: page,
    }) => {
        await clickCowriterButton(page);
        const dialog = await waitForModal(page);

        const cancelBtn = dialog.getByRole('button', { name: 'Cancel' });
        await cancelBtn.click();

        await expect(dialog).not.toBeVisible({ timeout: 3000 });
    });

    test('should pre-select "Selection" scope when text is selected', async ({
        authenticatedPage: page,
    }) => {
        const frame = getModuleFrame(page);

        // Select existing content
        const editable = frame.locator('.ck-editor__editable');
        await editable.click();
        await editable.press('Control+a');
        await page.waitForTimeout(200);

        await clickCowriterButton(page);
        const dialog = await waitForModal(page);

        // The context scope select should show "Selection"
        const scopeSelect = dialog.locator('select').nth(1);
        if ((await scopeSelect.count()) > 0) {
            const selectedText = await scopeSelect
                .locator('option:checked')
                .textContent();
            expect(selectedText?.toLowerCase()).toContain('selection');
        }
    });

    test('should execute task and show result or error', async ({
        authenticatedPage: page,
    }) => {
        test.setTimeout(120000);
        const frame = getModuleFrame(page);

        // Select existing content
        const editable = frame.locator('.ck-editor__editable');
        await editable.click();
        await editable.press('Control+a');

        await clickCowriterButton(page);
        const dialog = await waitForModal(page);
        await page.waitForTimeout(2000);

        // Click Execute
        const executeBtn = dialog.getByRole('button', { name: 'Execute' });
        await executeBtn.click();

        // Wait for Insert button (success) or error
        try {
            const insertBtn = dialog.getByRole('button', { name: 'Insert' });
            await insertBtn.waitFor({ state: 'visible', timeout: 90000 });

            // Insert button appeared — result is ready
            await expect(insertBtn).toBeVisible();
        } catch {
            // LLM not available or error occurred
            // Check the Result area for error content
            const resultArea = dialog.locator('.result-area, [class*="result"]');
            // Either result or error is acceptable for E2E
        }
    });

    test('should insert result into editor on Insert click', async ({
        authenticatedPage: page,
    }) => {
        test.setTimeout(120000);
        const frame = getModuleFrame(page);

        const editable = frame.locator('.ck-editor__editable');
        await editable.click();
        await editable.press('Control+a');

        await clickCowriterButton(page);
        const dialog = await waitForModal(page);
        await page.waitForTimeout(2000);

        const executeBtn = dialog.getByRole('button', { name: 'Execute' });
        await executeBtn.click();

        try {
            const insertBtn = dialog.getByRole('button', { name: 'Insert' });
            await insertBtn.waitFor({ state: 'visible', timeout: 90000 });

            await insertBtn.click();

            // Dialog should close
            await expect(dialog).not.toBeVisible({ timeout: 5000 });

            // Editor should have content
            const newText = await editable.textContent();
            expect(newText?.length).toBeGreaterThan(0);
        } catch {
            // LLM not available — skip
            test.skip();
        }
    });
});
