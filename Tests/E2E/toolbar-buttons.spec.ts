import {
    test,
    expect,
    navigateToContentElement,
    waitForCKEditor,
    getModuleFrame,
} from './fixtures/auth';

/**
 * E2E tests: CKEditor toolbar button presence.
 *
 * Verifies that all four Cowriter toolbar buttons are rendered
 * in the CKEditor toolbar when editing a content element.
 *
 * Prerequisites:
 * - A running TYPO3 v14 backend (ddev)
 * - At least one tt_content record with UID=1 and a bodytext field
 */
test.describe('Cowriter Toolbar Buttons', () => {
    test.beforeEach(async ({ authenticatedPage: page }) => {
        await navigateToContentElement(page);
        await waitForCKEditor(page);
    });

    test('should display the main Cowriter button', async ({
        authenticatedPage: page,
    }) => {
        const frame = getModuleFrame(page);
        const button = frame.locator(
            '.ck-button[data-cke-tooltip-text*="Cowriter - AI text completion"]',
        );
        await expect(button).toBeVisible();
    });

    test('should display the Vision button', async ({
        authenticatedPage: page,
    }) => {
        const frame = getModuleFrame(page);
        const button = frame.locator(
            '.ck-button[data-cke-tooltip-text*="Cowriter - Generate alt text"]',
        );
        await expect(button).toBeVisible();
    });

    test('should display the Translate dropdown', async ({
        authenticatedPage: page,
    }) => {
        const frame = getModuleFrame(page);
        const dropdown = frame.locator(
            '.ck-dropdown button[data-cke-tooltip-text*="Cowriter - Translate"]',
        );
        await expect(dropdown).toBeVisible();
    });

    test('should display the Tasks dropdown', async ({
        authenticatedPage: page,
    }) => {
        const frame = getModuleFrame(page);
        const dropdown = frame.locator(
            '.ck-dropdown button[data-cke-tooltip-text*="Cowriter - Tasks"]',
        );
        await expect(dropdown).toBeVisible();
    });
});
