import { test as base, expect, type Page, type FrameLocator, type Locator } from '@playwright/test';

/**
 * Extended test fixture with TYPO3 backend authentication.
 *
 * TYPO3 v14 architecture:
 * - Module content (CKEditor, forms) is inside an iframe
 * - Modals are rendered in the top-level page as <dialog> elements
 *   created by the <typo3-backend-modal> web component
 *
 * This fixture provides:
 * - authenticatedPage: The top-level page (for navigation and modal interactions)
 * - moduleFrame: FrameLocator for the module content iframe (for CKEditor interactions)
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
    const frame = authenticatedPage.frameLocator('iframe').first();
    await use(frame);
  },
});

export { expect };

/**
 * Get the module content frame locator.
 * TYPO3 v14 loads module content (edit forms, CKEditor) inside an iframe.
 */
export function getModuleFrame(page: Page): FrameLocator {
  return page.frameLocator('iframe').first();
}

/**
 * Navigate to the edit form for a content element's bodytext field.
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
 * Wait for CKEditor to fully initialize inside the module iframe.
 */
export async function waitForCKEditor(
  page: Page,
  timeout = 15000,
): Promise<void> {
  const frame = getModuleFrame(page);
  await frame.locator('.ck-editor__main').waitFor({ state: 'visible', timeout });
}

/**
 * Click the cowriter toolbar button inside the module iframe.
 */
export async function clickCowriterButton(page: Page): Promise<void> {
  const frame = getModuleFrame(page);
  const button = frame.locator(
    '.ck-button[data-cke-tooltip-text*="owriter"], ' +
      '.ck-button[aria-label*="owriter"]',
  );
  await button.first().click();
}

/**
 * Wait for the TYPO3 modal dialog to appear and return its locator.
 *
 * TYPO3 v14 renders modals as <dialog> elements in the top-level page
 * (created by <typo3-backend-modal> web component).
 *
 * @returns The dialog locator for further interaction
 */
export async function waitForModal(page: Page, timeout = 10000): Promise<Locator> {
  const dialog = page.getByRole('dialog', { name: 'Cowriter' });
  await dialog.waitFor({ state: 'visible', timeout });
  return dialog;
}
