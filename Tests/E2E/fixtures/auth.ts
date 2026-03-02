import { test as base, expect, type Page, type FrameLocator } from '@playwright/test';

/**
 * Extended test fixture with TYPO3 backend authentication.
 *
 * TYPO3 v14 renders CKEditor inside the backend content area.
 * This fixture handles login and provides both the authenticated page
 * and a module frame locator for accessing iframe-based module content.
 */
export const test = base.extend<{
  authenticatedPage: Page;
  moduleFrame: FrameLocator;
}>({
  authenticatedPage: async ({ page }, use) => {
    // Login to TYPO3 backend
    await page.goto('/typo3/login');

    // Fill login form
    await page.fill(
      'input[name="username"]',
      process.env.TYPO3_ADMIN_USER || 'admin',
    );
    await page.fill(
      'input[type="password"]',
      process.env.TYPO3_ADMIN_PASSWORD || 'Joh316!!',
    );

    // Submit login
    await page.click('button[type="submit"]');

    // Wait for redirect to backend
    await page.waitForURL(/\/typo3\/(main|module)/, { timeout: 15000 });

    // Verify we're logged in
    await expect(page.locator('.scaffold')).toBeVisible();

    await use(page);
  },

  moduleFrame: async ({ authenticatedPage }, use) => {
    // TYPO3 v14 may use an iframe for module content
    const frame = authenticatedPage.frameLocator('iframe').first();
    await use(frame);
  },
});

export { expect };

/**
 * Navigate to the edit form for a content element's bodytext field.
 *
 * @param page - The authenticated Playwright page
 * @param uid - The tt_content UID to edit (default: 1)
 */
export async function navigateToContentElement(
  page: Page,
  uid = 1,
): Promise<void> {
  await page.goto(
    `/typo3/record/edit?edit[tt_content][${uid}]=edit&columnsOnly=bodytext`,
  );
}

/**
 * Wait for CKEditor to fully initialize in the page.
 *
 * @param page - The Playwright page
 * @param timeout - Maximum time to wait in ms (default: 15000)
 */
export async function waitForCKEditor(
  page: Page,
  timeout = 15000,
): Promise<void> {
  await page.waitForSelector('.ck-editor__main', { timeout });
}

/**
 * Click the cowriter toolbar button to open the dialog.
 *
 * The button is identified by its tooltip text which contains "owriter"
 * (matching both "Cowriter" and the full tooltip text).
 *
 * @param page - The Playwright page with CKEditor loaded
 */
export async function clickCowriterButton(page: Page): Promise<void> {
  // CKEditor toolbar buttons use aria-label or data-cke-tooltip-text
  // Try tooltip first, then fall back to aria-label
  const button = page.locator(
    '.ck-button[data-cke-tooltip-text*="owriter"], ' +
      '.ck-button[aria-label*="owriter"]',
  );
  await button.first().click();
}

/**
 * Wait for the TYPO3 modal dialog to appear.
 *
 * @param page - The Playwright page
 * @param timeout - Maximum time to wait in ms (default: 10000)
 */
export async function waitForModal(page: Page, timeout = 10000): Promise<void> {
  await page.waitForSelector('.modal.show', { timeout });
}
