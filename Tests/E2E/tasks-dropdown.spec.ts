import {
    test,
    expect,
    navigateToContentElement,
    waitForCKEditor,
    getModuleFrame,
    waitForModal,
} from './fixtures/auth';

/**
 * E2E tests: Tasks dropdown button.
 *
 * Tests the tasks shortcut dropdown: lazy-loading of tasks,
 * opening the dialog with a pre-selected task, and
 * handling of missing task configuration.
 *
 * TYPO3 v14 architecture:
 * - CKEditor + dropdown: inside module iframe
 * - Cowriter modal dialog: top-level page (<dialog> element)
 * - Notifications: top-level page (#alert-container)
 *
 * Prerequisites:
 * - A running TYPO3 v14 backend (ddev)
 * - At least one tt_content record with UID=1 and a bodytext field
 * - At least one active LLM task with category "content" (for full tests)
 */
test.describe('Tasks Dropdown', () => {
    test.beforeEach(async ({ authenticatedPage: page }) => {
        await navigateToContentElement(page);
        await waitForCKEditor(page);
    });

    test('should open dropdown and load tasks from backend', async ({
        authenticatedPage: page,
    }) => {
        const frame = getModuleFrame(page);

        // Click the tasks dropdown button
        const dropdownBtn = frame.locator(
            '.ck-dropdown button[data-cke-tooltip-text*="Cowriter - Tasks"]',
        );
        await dropdownBtn.click();

        // Wait for tasks to load (async AJAX call)
        await page.waitForTimeout(3000);

        const panel = frame.locator('.ck-dropdown__panel');
        const items = panel.locator('.ck-list__item');
        const itemCount = await items.count();

        if (itemCount > 0) {
            // Tasks loaded — at least one should be visible
            await expect(items.first()).toBeVisible();
        } else {
            // No tasks configured — should show info or error notification
            const infoCount = await page
                .locator('#alert-container .alert-info')
                .count();
            const errorCount = await page
                .locator('#alert-container .alert-danger')
                .count();
            expect(infoCount + errorCount).toBeGreaterThan(0);
        }
    });

    test('should load tasks only once (caching)', async ({
        authenticatedPage: page,
    }) => {
        const frame = getModuleFrame(page);

        // Intercept the tasks AJAX call to count requests
        let taskRequestCount = 0;
        await page.route('**/ajax/cowriter/tasks**', async (route) => {
            taskRequestCount++;
            await route.continue();
        });

        const dropdownBtn = frame.locator(
            '.ck-dropdown button[data-cke-tooltip-text*="Cowriter - Tasks"]',
        );

        // Open dropdown first time
        await dropdownBtn.click();
        await page.waitForTimeout(3000);

        // Close dropdown with Escape key
        await page.keyboard.press('Escape');
        await page.waitForTimeout(500);

        // Open dropdown second time
        await dropdownBtn.click();
        await page.waitForTimeout(1000);

        // Should have made only 1 request (cached second time)
        expect(taskRequestCount).toBeLessThanOrEqual(1);
    });

    test('should open Cowriter dialog when task is clicked', async ({
        authenticatedPage: page,
    }) => {
        test.setTimeout(30000);
        const frame = getModuleFrame(page);

        // Type some text first
        const editable = frame.locator('.ck-editor__editable');
        await editable.click();
        await editable.pressSequentially('Test content for the cowriter.', {
            delay: 20,
        });

        // Open tasks dropdown
        const dropdownBtn = frame.locator(
            '.ck-dropdown button[data-cke-tooltip-text*="Cowriter - Tasks"]',
        );
        await dropdownBtn.click();
        await page.waitForTimeout(3000);

        const panel = frame.locator('.ck-dropdown__panel');
        const items = panel.locator('.ck-list__item');
        const itemCount = await items.count();

        if (itemCount === 0) {
            test.skip();
            return;
        }

        // Click the first task
        await items.first().click();

        // The Cowriter modal dialog opens in the top-level page
        const dialog = await waitForModal(page, 10000);
        await expect(dialog).toBeVisible();

        // The task select should have a pre-selected value
        const taskSelect = dialog.locator('[data-role="task-select"]');
        if ((await taskSelect.count()) > 0) {
            const selectedValue = await taskSelect.inputValue();
            expect(selectedValue).not.toBe('');
        }
    });

    test('should open dialog with selected text scope when text is selected', async ({
        authenticatedPage: page,
    }) => {
        test.setTimeout(30000);
        const frame = getModuleFrame(page);

        // Type text and select it
        const editable = frame.locator('.ck-editor__editable');
        await editable.click();
        await editable.pressSequentially('Selected text for processing', {
            delay: 20,
        });
        await editable.press('Control+a');

        // Open tasks dropdown
        const dropdownBtn = frame.locator(
            '.ck-dropdown button[data-cke-tooltip-text*="Cowriter - Tasks"]',
        );
        await dropdownBtn.click();
        await page.waitForTimeout(3000);

        const panel = frame.locator('.ck-dropdown__panel');
        const items = panel.locator('.ck-list__item');
        const itemCount = await items.count();

        if (itemCount === 0) {
            test.skip();
            return;
        }

        // Click the first task
        await items.first().click();

        // Dialog should open in top-level page
        const dialog = await waitForModal(page, 10000);
        await expect(dialog).toBeVisible();

        // The scope selector should indicate "Selection" is chosen
        const scopeSelect = dialog.locator('[data-role="scope-select"]');
        if ((await scopeSelect.count()) > 0) {
            const selectedText = await scopeSelect
                .locator('option:checked')
                .textContent();
            expect(selectedText?.toLowerCase()).toContain('selection');
        }
    });
});
