import {
    test,
    expect,
    navigateToContentElement,
    waitForCKEditor,
    getModuleFrame,
} from './fixtures/auth';

/**
 * E2E tests: Vision (alt text generation) button.
 *
 * Tests the image analysis feature: no-image warning,
 * and double-click guard.
 *
 * TYPO3 v14 architecture:
 * - CKEditor: inside module iframe
 * - Notifications: top-level page (#alert-container)
 *
 * Prerequisites:
 * - A running TYPO3 v14 backend (ddev)
 * - At least one tt_content record with UID=1 and a bodytext field
 */
test.describe('Vision Button', () => {
    test.beforeEach(async ({ authenticatedPage: page }) => {
        await navigateToContentElement(page);
        await waitForCKEditor(page);
    });

    test('should warn when no image is selected', async ({
        authenticatedPage: page,
    }) => {
        const frame = getModuleFrame(page);

        // Click vision button without selecting an image
        const button = frame.locator(
            '.ck-button[data-cke-tooltip-text*="Cowriter - Generate alt text"]',
        );
        await button.click();

        // Warning notification appears in the top-level page
        const notification = page.locator('#alert-container .alert-warning');
        await expect(notification.first()).toBeVisible({ timeout: 5000 });

        const text = await notification.first().textContent();
        expect(text).toContain('No image selected');
    });

    test('should show warning notification with correct message', async ({
        authenticatedPage: page,
    }) => {
        const frame = getModuleFrame(page);

        // Click vision button without an image
        const button = frame.locator(
            '.ck-button[data-cke-tooltip-text*="Cowriter - Generate alt text"]',
        );
        await button.click();

        // Wait for notification
        await page.waitForTimeout(500);

        // Verify the warning contains helpful guidance
        const warning = page.locator('#alert-container .alert-warning').first();
        await expect(warning).toBeVisible();

        const text = await warning.textContent();
        expect(text).toContain('Click on an image');
    });
});
