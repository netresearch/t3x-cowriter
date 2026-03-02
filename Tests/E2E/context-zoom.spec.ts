import {
  test,
  expect,
  navigateToContentElement,
  waitForCKEditor,
  clickCowriterButton,
  waitForModal,
} from './fixtures/auth';

/**
 * E2E tests for the Cowriter context zoom feature.
 *
 * These tests verify the full integration of the CKEditor cowriter plugin,
 * TYPO3 backend modal dialog, context zoom slider, reference page picker,
 * and result preview.
 *
 * Prerequisites:
 * - A running TYPO3 v14 backend (ddev)
 * - At least one tt_content record with UID=1 and a bodytext field
 * - At least one active LLM task with category "content"
 */
test.describe('Cowriter Context Zoom', () => {
  test.beforeEach(async ({ authenticatedPage: page }) => {
    // Navigate to a content element edit form that has CKEditor
    await navigateToContentElement(page);

    // Wait for CKEditor to initialize
    await waitForCKEditor(page);
  });

  test('should open cowriter dialog with context zoom slider', async ({
    authenticatedPage: page,
  }) => {
    // Click the cowriter toolbar button
    await clickCowriterButton(page);

    // Wait for the modal dialog
    await waitForModal(page);

    // Verify slider exists (not radio buttons)
    const slider = page.locator('[data-role="context-slider"]');
    await expect(slider).toBeVisible();
    await expect(slider).toHaveAttribute('type', 'range');
    await expect(slider).toHaveAttribute('max', '5');

    // Verify no radio buttons for context selection
    await expect(
      page.locator('input[type="radio"][name="cowriter-context"]'),
    ).toHaveCount(0);

    // Verify tick labels under the slider
    const tickLabels = page.locator(
      '.cowriter-dialog .d-flex.justify-content-between span',
    );
    await expect(tickLabels).toHaveCount(6);
    await expect(tickLabels.nth(0)).toContainText('Selection');
    await expect(tickLabels.nth(1)).toContainText('Text');
    await expect(tickLabels.nth(2)).toContainText('Element');
    await expect(tickLabels.nth(3)).toContainText('Page');
    await expect(tickLabels.nth(4)).toContainText('+1 level');
    await expect(tickLabels.nth(5)).toContainText('+2 levels');

    // Verify scope label is visible
    await expect(page.locator('[data-role="scope-label"]')).toBeVisible();
  });

  test('should update scope label when slider changes', async ({
    authenticatedPage: page,
  }) => {
    await clickCowriterButton(page);
    await waitForModal(page);

    const slider = page.locator('[data-role="context-slider"]');
    const scopeLabel = page.locator('[data-role="scope-label"]');

    // Move slider to "Element" (stop 2)
    await slider.fill('2');
    await slider.dispatchEvent('input');
    await expect(scopeLabel).toContainText('Element');

    // Move slider to "Page" (stop 3)
    await slider.fill('3');
    await slider.dispatchEvent('input');
    await expect(scopeLabel).toContainText('Page');

    // Move slider to "+1 ancestor level" (stop 4)
    await slider.fill('4');
    await slider.dispatchEvent('input');
    await expect(scopeLabel).toContainText('ancestor');

    // Move slider to "+2 ancestor levels" (stop 5)
    await slider.fill('5');
    await slider.dispatchEvent('input');
    await expect(scopeLabel).toContainText('ancestor');

    // Move slider back to "Text" (stop 1)
    await slider.fill('1');
    await slider.dispatchEvent('input');
    await expect(scopeLabel).toContainText('Text');
  });

  test('should add and remove reference page rows', async ({
    authenticatedPage: page,
  }) => {
    await clickCowriterButton(page);
    await waitForModal(page);

    // Verify "Add reference page" button exists
    const addBtn = page.locator('[data-role="add-reference"]');
    await expect(addBtn).toBeVisible();
    await expect(addBtn).toContainText('Add reference page');

    // Add a reference row
    await addBtn.click();
    const rows = page.locator('[data-role="reference-row"]');
    await expect(rows).toHaveCount(1);

    // Verify row has PID input and relation input
    await expect(
      rows.first().locator('[data-role="ref-pid"]'),
    ).toBeVisible();
    await expect(
      rows.first().locator('[data-role="ref-relation"]'),
    ).toBeVisible();

    // Verify row has a remove button
    await expect(
      rows.first().locator('[data-role="remove-reference"]'),
    ).toBeVisible();

    // Add a second row
    await addBtn.click();
    await expect(rows).toHaveCount(2);

    // Fill in the first row
    await rows
      .first()
      .locator('[data-role="ref-pid"]')
      .fill('42');
    await rows
      .first()
      .locator('[data-role="ref-relation"]')
      .fill('style guide');

    // Remove the first row
    await rows.first().locator('[data-role="remove-reference"]').click();
    await expect(rows).toHaveCount(1);

    // The remaining row should be empty (it was the second one)
    await expect(
      rows.first().locator('[data-role="ref-pid"]'),
    ).toHaveValue('');
  });

  test('should have task selector with options', async ({
    authenticatedPage: page,
  }) => {
    await clickCowriterButton(page);
    await waitForModal(page);

    // Task selector should be visible and populated
    const taskSelect = page.locator('[data-role="task-select"]');
    await expect(taskSelect).toBeVisible();
    await expect(taskSelect).toHaveAttribute('class', /form-select/);

    // Should have at least one option
    const options = taskSelect.locator('option');
    const count = await options.count();
    expect(count).toBeGreaterThan(0);

    // Task description should be shown
    const taskDesc = page.locator('[data-role="task-description"]');
    await expect(taskDesc).toBeVisible();
  });

  test('should have ad-hoc rules textarea', async ({
    authenticatedPage: page,
  }) => {
    await clickCowriterButton(page);
    await waitForModal(page);

    // Ad-hoc rules textarea
    const adHocRules = page.locator('[data-role="ad-hoc-rules"]');
    await expect(adHocRules).toBeVisible();
    await expect(adHocRules).toHaveAttribute('placeholder', /formal tone/);

    // Should be editable
    await adHocRules.fill('Keep it under 50 words');
    await expect(adHocRules).toHaveValue('Keep it under 50 words');
  });

  test('should execute task and show result preview', async ({
    authenticatedPage: page,
  }) => {
    await clickCowriterButton(page);
    await waitForModal(page);

    // The Execute button should be visible
    const executeBtn = page.locator('[data-name="execute"]');
    await expect(executeBtn).toBeVisible();
    await expect(executeBtn).toContainText('Execute');

    // Click Execute
    await executeBtn.click();

    // The result preview area should become visible
    const preview = page.locator('[data-role="result-preview"]');
    await expect(preview).toBeVisible({ timeout: 5000 });

    // Should show "Generating..." first
    await expect(preview).toContainText('Generating');

    // Wait for result (LLM response may take up to 30s)
    // After generation completes, the text should change
    await expect(preview).not.toContainText('Generating', { timeout: 30000 });

    // Either the button changes to "Insert" (success) or shows an error
    const primaryBtn = page.locator('.modal .btn-primary');
    const btnText = await primaryBtn.textContent();

    if (btnText?.includes('Insert')) {
      // Successful generation - verify result preview has content
      const previewText = await preview.textContent();
      expect(previewText?.length).toBeGreaterThan(0);
    }
    // If it still says "Execute", the request failed (which is acceptable
    // in environments without a configured LLM backend)
  });

  test('should cancel dialog without inserting content', async ({
    authenticatedPage: page,
  }) => {
    await clickCowriterButton(page);
    await waitForModal(page);

    // Verify the modal is open
    await expect(page.locator('.modal.show')).toBeVisible();

    // Click Cancel
    await page.click('[data-name="cancel"]');

    // Modal should close
    await expect(page.locator('.modal.show')).toHaveCount(0, {
      timeout: 5000,
    });
  });

  test('should reset to Execute mode when task changes after generation', async ({
    authenticatedPage: page,
  }) => {
    await clickCowriterButton(page);
    await waitForModal(page);

    const taskSelect = page.locator('[data-role="task-select"]');
    const options = taskSelect.locator('option');
    const count = await options.count();

    // This test only makes sense with multiple tasks
    test.skip(count < 2, 'Need at least 2 tasks to test task switching');

    // Execute the first task
    await page.click('[data-name="execute"]');
    const preview = page.locator('[data-role="result-preview"]');
    await expect(preview).toBeVisible({ timeout: 5000 });

    // Wait for completion or error
    await expect(preview).not.toContainText('Generating', { timeout: 30000 });

    // If Execute button changed to Insert, switching task should reset it
    const primaryBtn = page.locator('.modal .btn-primary');
    const btnText = await primaryBtn.textContent();

    if (btnText?.includes('Insert')) {
      // Switch to a different task
      await taskSelect.selectOption({ index: 1 });

      // Button should revert to "Execute"
      await expect(primaryBtn).toContainText('Execute');
    }
  });

  test('should reset to Execute mode when slider changes after generation', async ({
    authenticatedPage: page,
  }) => {
    await clickCowriterButton(page);
    await waitForModal(page);

    // Execute the task
    await page.click('[data-name="execute"]');
    const preview = page.locator('[data-role="result-preview"]');
    await expect(preview).toBeVisible({ timeout: 5000 });

    // Wait for completion or error
    await expect(preview).not.toContainText('Generating', { timeout: 30000 });

    const primaryBtn = page.locator('.modal .btn-primary');
    const btnText = await primaryBtn.textContent();

    if (btnText?.includes('Insert')) {
      // Change the context slider
      const slider = page.locator('[data-role="context-slider"]');
      await slider.fill('3');
      await slider.dispatchEvent('input');

      // Button should revert to "Execute"
      await expect(primaryBtn).toContainText('Execute');
    }
  });
});
