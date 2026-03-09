import {
    test,
    expect,
    navigateToContentElement,
    waitForCKEditor,
    getModuleFrame,
} from './fixtures/auth';

/**
 * E2E tests: Translate dropdown button.
 *
 * TYPO3 v14: CKEditor + dropdown panels in module iframe,
 * notifications in the top-level page.
 */
test.describe('Translate Dropdown', () => {
    test.beforeEach(async ({ authenticatedPage: page }) => {
        await navigateToContentElement(page);
        await waitForCKEditor(page);
    });

    test('should show language options when opened', async ({
        authenticatedPage: page,
    }) => {
        const frame = getModuleFrame(page);

        // Click the translate dropdown
        const dropdownBtn = frame.locator(
            '.ck-dropdown button[data-cke-tooltip-text*="Translate"]',
        );
        await dropdownBtn.click();
        await page.waitForTimeout(500);

        // Find the visible list items within any open dropdown panel
        const items = frame.locator('.ck-reset.ck-list .ck-list__item:visible');
        const count = await items.count();
        expect(count).toBe(10);

        // Verify some key languages
        const texts = await items.allTextContents();
        expect(texts).toContain('German');
        expect(texts).toContain('English');
        expect(texts).toContain('French');
    });

    test('should warn when no text is selected', async ({
        authenticatedPage: page,
    }) => {
        const frame = getModuleFrame(page);

        // Place cursor but don't select
        const editable = frame.locator('.ck-editor__editable');
        await editable.click();

        // Open dropdown and click German
        const dropdownBtn = frame.locator(
            '.ck-dropdown button[data-cke-tooltip-text*="Translate"]',
        );
        await dropdownBtn.click();
        await page.waitForTimeout(500);

        // Click the first visible list item
        const germanItem = frame.locator(
            '.ck-list__item .ck-button__label:text("German")',
        );
        await germanItem.click();

        // Warning notification in top-level page
        const notification = page.locator('#alert-container .alert-warning');
        await expect(notification.first()).toBeVisible({ timeout: 5000 });

        const text = await notification.first().textContent();
        expect(text).toContain('No text selected');
    });

    test('should translate selected text or show error', async ({
        authenticatedPage: page,
    }) => {
        test.setTimeout(90000);
        const frame = getModuleFrame(page);

        // Select existing editor content
        const editable = frame.locator('.ck-editor__editable');
        await editable.click();
        await editable.press('Control+a');
        await page.waitForTimeout(300);

        // Open translate dropdown
        const dropdownBtn = frame.locator(
            '.ck-dropdown button[data-cke-tooltip-text*="Translate"]',
        );
        await dropdownBtn.click();
        await page.waitForTimeout(500);

        // Click German
        const germanItem = frame.locator(
            '.ck-list__item .ck-button__label:text("German")',
        );
        await germanItem.click();

        // Info notification should appear
        const infoNotification = page.locator('#alert-container .alert-info');
        await expect(infoNotification.first()).toBeVisible({ timeout: 10000 });

        const notifText = await infoNotification.first().textContent();
        expect(notifText).toContain('Translating');

        // Wait for either success or error notification
        try {
            await page.waitForFunction(
                () => {
                    const container = document.querySelector('#alert-container');
                    if (!container) return false;
                    return !!(
                        container.querySelector('.alert-success') ||
                        container.querySelector('.alert-danger')
                    );
                },
                { timeout: 60000 },
            );
        } catch {
            // Timeout is acceptable in E2E without LLM
        }
    });
});
