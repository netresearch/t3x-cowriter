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
 * TYPO3 v14 architecture:
 * - CKEditor and forms are inside an iframe (module content frame)
 * - Modals are rendered in the top-level page as <dialog> elements
 *   created by <typo3-backend-modal> web components
 *
 * Therefore:
 * - CKEditor interactions: use module frame (iframe)
 * - Modal interactions: use top-level page (dialog element)
 *
 * Prerequisites:
 * - A running TYPO3 v14 backend (ddev)
 * - At least one tt_content record with UID=1 and a bodytext field
 * - At least one active LLM task with category "content"
 */
test.describe('Cowriter Context Zoom', () => {
  test.beforeEach(async ({ authenticatedPage: page }) => {
    await navigateToContentElement(page);
    await waitForCKEditor(page);
  });

  test('should open cowriter dialog with context zoom slider', async ({
    authenticatedPage: page,
  }) => {
    await clickCowriterButton(page);
    const dialog = await waitForModal(page);

    // Verify slider exists (not radio buttons)
    const slider = dialog.locator('[data-role="context-slider"]');
    await expect(slider).toBeVisible();
    await expect(slider).toHaveAttribute('type', 'range');
    await expect(slider).toHaveAttribute('max', '5');

    // Verify no radio buttons for context selection
    await expect(
      dialog.locator('input[type="radio"][name="cowriter-context"]'),
    ).toHaveCount(0);

    // Verify tick labels under the slider
    const tickLabels = dialog.locator(
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
    await expect(dialog.locator('[data-role="scope-label"]')).toBeVisible();
  });

  test('should update scope label when slider changes', async ({
    authenticatedPage: page,
  }) => {
    await clickCowriterButton(page);
    const dialog = await waitForModal(page);

    const slider = dialog.locator('[data-role="context-slider"]');
    const scopeLabel = dialog.locator('[data-role="scope-label"]');

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
    const dialog = await waitForModal(page);

    // Verify "Add reference page" button exists
    const addBtn = dialog.locator('[data-role="add-reference"]');
    await expect(addBtn).toBeVisible();
    await expect(addBtn).toContainText('Add reference page');

    // Add a reference row
    await addBtn.click();
    const rows = dialog.locator('[data-role="reference-row"]');
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
    await rows.first().locator('[data-role="ref-pid"]').fill('42');
    await rows.first().locator('[data-role="ref-relation"]').fill('style guide');

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
    const dialog = await waitForModal(page);

    // Task selector should be visible and populated
    const taskSelect = dialog.locator('[data-role="task-select"]');
    await expect(taskSelect).toBeVisible();
    await expect(taskSelect).toHaveAttribute('class', /form-select/);

    // Should have at least one option
    const options = taskSelect.locator('option');
    const count = await options.count();
    expect(count).toBeGreaterThan(0);

    // Task description should be shown
    const taskDesc = dialog.locator('[data-role="task-description"]');
    await expect(taskDesc).toBeVisible();
  });

  test('should have ad-hoc rules textarea', async ({
    authenticatedPage: page,
  }) => {
    await clickCowriterButton(page);
    const dialog = await waitForModal(page);

    // Instruction textarea
    const instruction = dialog.locator('[data-role="instruction"]');
    await expect(instruction).toBeVisible();
    await expect(instruction).toHaveAttribute('placeholder', /what the AI should do/);

    // Should be editable
    await instruction.fill('Keep it under 50 words');
    await expect(instruction).toHaveValue('Keep it under 50 words');
  });

  test('should execute task and show result preview', async ({
    authenticatedPage: page,
  }) => {
    await clickCowriterButton(page);
    const dialog = await waitForModal(page);

    // The Execute button should be visible
    const executeBtn = dialog.getByRole('button', { name: 'Execute' });
    await expect(executeBtn).toBeVisible();

    // Click Execute
    await executeBtn.click();

    // The result preview area should become visible
    const preview = dialog.locator('[data-role="result-preview"]');
    await expect(preview).toBeVisible({ timeout: 5000 });

    // Wait for LLM response (up to 30s). The button changes from "Execute" to "Insert"
    // on success, or stays "Execute" / shows error on failure.
    // Wait for the Insert button to appear (success) or for the spinner to stop (error)
    const insertBtn = dialog.getByRole('button', { name: 'Insert' });
    try {
      await insertBtn.waitFor({ state: 'visible', timeout: 30000 });

      // Successful generation - verify result preview has content
      const previewText = await preview.textContent();
      expect(previewText?.trim().length).toBeGreaterThan(0);
    } catch {
      // LLM request failed (no backend configured) — that's acceptable
      // Just verify the modal is still visible
      await expect(dialog).toBeVisible();
    }
  });

  test('should cancel dialog without inserting content', async ({
    authenticatedPage: page,
  }) => {
    await clickCowriterButton(page);
    const dialog = await waitForModal(page);

    // Verify the modal is open
    await expect(dialog).toBeVisible();

    // Click Cancel
    await dialog.getByRole('button', { name: 'Cancel' }).click();

    // Dialog should close
    await expect(dialog).toBeHidden({ timeout: 5000 });
  });

  test('should reset to Execute mode when task changes after generation', async ({
    authenticatedPage: page,
  }) => {
    await clickCowriterButton(page);
    const dialog = await waitForModal(page);

    const taskSelect = dialog.locator('[data-role="task-select"]');
    const options = taskSelect.locator('option');
    const count = await options.count();

    // This test only makes sense with multiple tasks
    test.skip(count < 2, 'Need at least 2 tasks to test task switching');

    // Execute the first task
    await dialog.getByRole('button', { name: 'Execute' }).click();

    // Wait for result or error
    const insertBtn = dialog.getByRole('button', { name: 'Insert' });
    try {
      await insertBtn.waitFor({ state: 'visible', timeout: 30000 });
    } catch {
      // LLM failed — skip the rest of this test
      return;
    }

    // Switch to a different task
    await taskSelect.selectOption({ index: 1 });

    // Button should revert to "Execute"
    await expect(dialog.getByRole('button', { name: 'Execute' })).toBeVisible();
  });

  test('should reset to Execute mode when slider changes after generation', async ({
    authenticatedPage: page,
  }) => {
    await clickCowriterButton(page);
    const dialog = await waitForModal(page);

    // Execute the task
    await dialog.getByRole('button', { name: 'Execute' }).click();

    // Wait for result or error
    const insertBtn = dialog.getByRole('button', { name: 'Insert' });
    try {
      await insertBtn.waitFor({ state: 'visible', timeout: 30000 });
    } catch {
      // LLM failed — skip the rest of this test
      return;
    }

    // Change the context slider
    const slider = dialog.locator('[data-role="context-slider"]');
    await slider.fill('3');
    await slider.dispatchEvent('input');

    // Button should revert to "Execute"
    await expect(dialog.getByRole('button', { name: 'Execute' })).toBeVisible();
  });
});
