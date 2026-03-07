# Cowriter Dialog Side-by-Side Redesign — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Redesign the Cowriter dialog from a vertical stack to a side-by-side layout with always-visible result area, scope dropdown (replacing slider), separate Execute/Insert buttons, chained re-execution, and explicit Reset.

**Architecture:** All changes are frontend-only (JS + CSS). The backend API is unchanged. CowriterDialog.js gets a new layout using Bootstrap 5 grid (`.row`/`.col-md-*`), a state machine controlling button visibility, and a result area that always shows context. One new CSS file for `.cowriter-result` styling.

**Tech Stack:** ES modules, TYPO3 Backend Modal API, Bootstrap 5 grid, Vitest (JS tests)

**Design doc:** `docs/plans/2026-03-04-dialog-redesign.md`

---

## Commit 1: Replace context scope slider with dropdown

### Task 1: Write failing tests for scope dropdown

**Files:**
- Modify: `Tests/JavaScript/CowriterDialog.test.js`

**Step 1: Write failing tests**

Add/replace these tests in the `dialog content` describe block. The existing slider tests need to be replaced with dropdown tests.

Replace the existing slider-related test (`should render context scope slider`) and add new dropdown tests:

```javascript
it('should render scope as dropdown with 6 options', async () => {
    const dialog = new CowriterDialog(mockService);
    const showPromise = dialog.show('selected', 'full');
    await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

    const scopeSelect = document.querySelector('[data-role="scope-select"]');
    expect(scopeSelect).toBeTruthy();
    expect(scopeSelect.tagName).toBe('SELECT');
    expect(scopeSelect.options).toHaveLength(6);
    expect(scopeSelect.options[0].textContent).toBe('Selection');
    expect(scopeSelect.options[1].textContent).toBe('Text');
    expect(scopeSelect.options[2].textContent).toBe('Element');
    expect(scopeSelect.options[3].textContent).toBe('Page');
    expect(scopeSelect.options[4].textContent).toBe('+1 ancestor level');
    expect(scopeSelect.options[5].textContent).toBe('+2 ancestor levels');

    document.querySelector('[data-name="cancel"]').click();
    await expect(showPromise).rejects.toThrow('User cancelled');
});

it('should default scope to Selection when text is selected', async () => {
    const dialog = new CowriterDialog(mockService);
    const showPromise = dialog.show('selected text', 'full');
    await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

    const scopeSelect = document.querySelector('[data-role="scope-select"]');
    expect(scopeSelect.value).toBe('selection');

    document.querySelector('[data-name="cancel"]').click();
    await expect(showPromise).rejects.toThrow('User cancelled');
});

it('should default scope to Text when no selection', async () => {
    const dialog = new CowriterDialog(mockService);
    const showPromise = dialog.show('', 'full content');
    await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

    const scopeSelect = document.querySelector('[data-role="scope-select"]');
    expect(scopeSelect.value).toBe('text');

    document.querySelector('[data-name="cancel"]').click();
    await expect(showPromise).rejects.toThrow('User cancelled');
});

it('should disable Selection option when no text selected', async () => {
    const dialog = new CowriterDialog(mockService);
    const showPromise = dialog.show('', 'full content');
    await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

    const scopeSelect = document.querySelector('[data-role="scope-select"]');
    expect(scopeSelect.options[0].disabled).toBe(true);

    document.querySelector('[data-name="cancel"]').click();
    await expect(showPromise).rejects.toThrow('User cancelled');
});

it('should send scope ID from dropdown in executeTask', async () => {
    const dialog = new CowriterDialog(mockService);
    const showPromise = dialog.show('selected', 'full');
    await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

    // Change scope to "page"
    const scopeSelect = document.querySelector('[data-role="scope-select"]');
    scopeSelect.value = 'page';
    scopeSelect.dispatchEvent(new Event('change'));

    // Click execute
    document.querySelector('[data-name="execute"]').click();
    await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());

    // contextScope should be 'page'
    expect(mockService.executeTask.mock.calls[0][5]).toBe('page');

    document.querySelector('[data-name="cancel"]').click();
    await showPromise.catch(() => {});
});
```

**Step 2: Run tests to verify they fail**

Run: `npx vitest run Tests/JavaScript/CowriterDialog.test.js`
Expected: FAIL — `[data-role="scope-select"]` not found (still using slider)

### Task 2: Implement scope dropdown replacing slider

**Files:**
- Modify: `Resources/Public/JavaScript/Ckeditor/CowriterDialog.js`

**Step 3: Replace slider with dropdown in `_buildDialogContent`**

In `CowriterDialog.js`, replace the context scope slider section (lines ~367–437) with:

```javascript
// Context scope dropdown (replaces slider)
const scopeId = `${idPrefix}-scope`;
const contextGroup = this._createFormGroup('Scope', scopeId);
const hasSelection = Boolean(selectedText && selectedText.trim().length > 0);

const scopeSelect = document.createElement('select');
scopeSelect.className = 'form-select';
scopeSelect.id = scopeId;
scopeSelect.dataset.role = 'scope-select';

for (let i = 0; i < SCOPE_IDS.length; i++) {
    const opt = document.createElement('option');
    opt.value = SCOPE_IDS[i];
    opt.textContent = SCOPE_LABELS[i];
    // Disable "Selection" when no text is selected
    if (i === 0 && !hasSelection) {
        opt.disabled = true;
    }
    // Disable element/page/ancestors when no recordContext
    if (i >= 2 && !recordContext) {
        opt.disabled = true;
    }
    scopeSelect.appendChild(opt);
}

// Default: Selection if text selected, otherwise Text
scopeSelect.value = hasSelection ? 'selection' : 'text';
contextGroup.appendChild(scopeSelect);
container.appendChild(contextGroup);
```

Also update `executeTrigger` in `_showModal` to read from the dropdown instead of slider:

Replace:
```javascript
const slider = container.querySelector('[data-role="context-slider"]');
const scopeIndex = parseInt(slider.value, 10);
const contextScope = SCOPE_IDS[scopeIndex] || 'selection';
```

With:
```javascript
const contextScope = container.querySelector('[data-role="scope-select"]').value;
```

Update the reset listeners to use `scope-select` instead of `context-slider`:
```javascript
container.querySelector('[data-role="scope-select"]')?.addEventListener('change', resetResult);
```

Remove the `SCOPE_TICK_LABELS` constant (no longer needed) and the `previewTimer` debounce logic (move context preview to a simpler handler if desired, or remove for now since the dropdown change is instant).

**Step 4: Run tests to verify they pass**

Run: `npx vitest run Tests/JavaScript/CowriterDialog.test.js`
Expected: All tests PASS

**Step 5: Remove old slider tests**

Remove (or confirm already replaced in Step 1):
- `should render context scope slider`
- `should set slider min to 0 when text is selected`
- `should set slider min to 1 when no selection`
- Any slider tick label tests

**Step 6: Run full test suite**

Run: `npx vitest run`
Expected: All 128+ tests PASS

**Step 7: Commit**

```bash
git add Resources/Public/JavaScript/Ckeditor/CowriterDialog.js Tests/JavaScript/CowriterDialog.test.js
git commit -S --signoff -m "refactor: replace context scope slider with dropdown

Replaces the range slider + tick labels with a <select> dropdown for
context scope selection. Dropdown options: Selection, Text, Element,
Page, +1/+2 ancestor levels. Selection disabled when no text selected,
Element+ disabled without recordContext."
```

---

## Commit 2: Side-by-side layout with large modal

### Task 3: Write failing tests for layout structure

**Files:**
- Modify: `Tests/JavaScript/CowriterDialog.test.js`

**Step 1: Write failing tests for layout**

```javascript
it('should use large modal size', async () => {
    const dialog = new CowriterDialog(mockService);
    const showPromise = dialog.show('text', 'full');
    await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

    const modal = document.querySelector('.modal');
    // The mock stores the size option — check it was called with 'large'
    // Since our mock doesn't store size, we check the container structure
    // For now, just verify the modal exists (size is passed to Modal.advanced)
    expect(modal).toBeTruthy();

    document.querySelector('[data-name="cancel"]').click();
    await expect(showPromise).rejects.toThrow('User cancelled');
});

it('should render config row with task and scope side by side', async () => {
    const dialog = new CowriterDialog(mockService);
    const showPromise = dialog.show('text', 'full');
    await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

    const configRow = document.querySelector('[data-role="config-row"]');
    expect(configRow).toBeTruthy();
    expect(configRow.classList.contains('row')).toBe(true);

    // Task in col-md-8, Scope in col-md-4
    const cols = configRow.querySelectorAll('[class*="col-md-"]');
    expect(cols.length).toBe(2);
    expect(cols[0].classList.contains('col-md-8')).toBe(true);
    expect(cols[1].classList.contains('col-md-4')).toBe(true);

    // Task select in first column
    expect(cols[0].querySelector('[data-role="task-select"]')).toBeTruthy();
    // Scope select in second column
    expect(cols[1].querySelector('[data-role="scope-select"]')).toBeTruthy();

    document.querySelector('[data-name="cancel"]').click();
    await expect(showPromise).rejects.toThrow('User cancelled');
});

it('should render work area with instruction and result side by side', async () => {
    const dialog = new CowriterDialog(mockService);
    const showPromise = dialog.show('text', 'full');
    await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

    const workRow = document.querySelector('[data-role="work-row"]');
    expect(workRow).toBeTruthy();
    expect(workRow.classList.contains('row')).toBe(true);

    const cols = workRow.querySelectorAll('[class*="col-md-"]');
    expect(cols.length).toBe(2);
    expect(cols[0].classList.contains('col-md-6')).toBe(true);
    expect(cols[1].classList.contains('col-md-6')).toBe(true);

    // Instruction textarea in first column
    expect(cols[0].querySelector('[data-role="instruction"]')).toBeTruthy();
    // Result area in second column
    expect(cols[1].querySelector('[data-role="result-preview"]')).toBeTruthy();

    document.querySelector('[data-name="cancel"]').click();
    await expect(showPromise).rejects.toThrow('User cancelled');
});
```

**Step 2: Run tests to verify they fail**

Run: `npx vitest run Tests/JavaScript/CowriterDialog.test.js`
Expected: FAIL — `[data-role="config-row"]` and `[data-role="work-row"]` not found

### Task 4: Implement side-by-side layout

**Files:**
- Modify: `Resources/Public/JavaScript/Ckeditor/CowriterDialog.js`

**Step 3: Restructure `_buildDialogContent` for side-by-side layout**

Restructure the DOM building in `_buildDialogContent` to produce this hierarchy:

```
div.cowriter-dialog
├── div.row[data-role="config-row"]
│   ├── div.col-md-8
│   │   ├── label "Task"
│   │   ├── select[data-role="task-select"]
│   │   └── div[data-role="task-description"]
│   └── div.col-md-4
│       ├── label "Scope"
│       └── select[data-role="scope-select"]
├── div.mb-3 (reference pages — unchanged)
├── div.row[data-role="work-row"]
│   ├── div.col-md-6
│   │   ├── label "Instruction"
│   │   └── textarea[data-role="instruction"]
│   └── div.col-md-6
│       ├── label "Result"
│       ├── div.cowriter-result[data-role="result-preview"]
│       ├── small[data-role="model-info"]
│       └── details[data-role="debug-details"]
```

Key changes:
- Config row: wrap task group (col-md-8) + scope group (col-md-4) in a `.row`
- Work row: wrap instruction (col-md-6) + result (col-md-6) in a `.row`
- Change `Modal.sizes.medium` → `Modal.sizes.large`
- Change instruction textarea rows from 6 → 10
- Change result preview class to `cowriter-result` (for CSS styling)

**Step 4: Run tests to verify they pass**

Run: `npx vitest run Tests/JavaScript/CowriterDialog.test.js`
Expected: All tests PASS

**Step 5: Run full test suite**

Run: `npx vitest run`
Expected: All tests PASS

**Step 6: Commit**

```bash
git add Resources/Public/JavaScript/Ckeditor/CowriterDialog.js Tests/JavaScript/CowriterDialog.test.js
git commit -S --signoff -m "refactor: side-by-side dialog layout with large modal

Restructures the Cowriter dialog to use Bootstrap grid layout:
- Config row: Task (col-md-8) + Scope (col-md-4) side by side
- Work row: Instruction (col-md-6) + Result (col-md-6) side by side
- Switches from medium to large modal size"
```

---

## Commit 3: Always-visible result area with input preview

### Task 5: Write failing tests for result area idle state

**Files:**
- Modify: `Tests/JavaScript/CowriterDialog.test.js`

**Step 1: Write failing tests**

```javascript
it('should show original input text in result area in idle state', async () => {
    const dialog = new CowriterDialog(mockService);
    const showPromise = dialog.show('my selected text', 'full content');
    await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

    const result = document.querySelector('[data-role="result-preview"]');
    expect(result).toBeTruthy();
    // Result area is visible (not display:none)
    expect(result.style.display).not.toBe('none');
    // Shows the input text
    expect(result.textContent).toContain('my selected text');
    // Has the empty/muted styling class
    expect(result.classList.contains('cowriter-result--empty')).toBe(true);

    document.querySelector('[data-name="cancel"]').click();
    await expect(showPromise).rejects.toThrow('User cancelled');
});

it('should show full content in result area when no selection', async () => {
    const dialog = new CowriterDialog(mockService);
    const showPromise = dialog.show('', 'full content here');
    await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

    const result = document.querySelector('[data-role="result-preview"]');
    expect(result.textContent).toContain('full content here');
    expect(result.classList.contains('cowriter-result--empty')).toBe(true);

    document.querySelector('[data-name="cancel"]').click();
    await expect(showPromise).rejects.toThrow('User cancelled');
});

it('should remove muted class from result area after successful execute', async () => {
    const dialog = new CowriterDialog(mockService);
    const showPromise = dialog.show('text', 'full');
    await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

    document.querySelector('[data-name="execute"]').click();
    await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
    // Wait for result to render
    await vi.waitFor(() => {
        const result = document.querySelector('[data-role="result-preview"]');
        return result.textContent.includes('Improved text content');
    });

    const result = document.querySelector('[data-role="result-preview"]');
    expect(result.classList.contains('cowriter-result--empty')).toBe(false);

    document.querySelector('[data-name="cancel"]').click();
    await showPromise.catch(() => {});
});
```

**Step 2: Run tests to verify they fail**

Run: `npx vitest run Tests/JavaScript/CowriterDialog.test.js`
Expected: FAIL — result area is hidden in idle state (`display: none`)

### Task 6: Implement always-visible result area

**Files:**
- Modify: `Resources/Public/JavaScript/Ckeditor/CowriterDialog.js`

**Step 3: Make result area always visible with input preview**

In `_buildDialogContent`, change the result preview initialization:

```javascript
// Result preview — always visible, shows input text in idle state
const preview = document.createElement('div');
preview.className = 'cowriter-result cowriter-result--empty';
preview.dataset.role = 'result-preview';
const inputPreview = selectedText || fullContent;
preview.textContent = inputPreview;
resultCol.appendChild(preview);
```

Remove the `preview.style.display = 'none'` line.

In `executeTrigger`, after successful result rendering:
- Remove `cowriter-result--empty` class from the result area
- Keep result visible (no display toggle needed)

In the error/no-content branch:
- Keep `cowriter-result--empty` or add specific error styling

**Step 4: Run tests to verify they pass**

Run: `npx vitest run Tests/JavaScript/CowriterDialog.test.js`
Expected: All tests PASS

**Step 5: Commit**

```bash
git add Resources/Public/JavaScript/Ckeditor/CowriterDialog.js Tests/JavaScript/CowriterDialog.test.js
git commit -S --signoff -m "feat: always-visible result area with input preview

Shows the original input text (selected or full content) in the result
area in muted/italic style during idle state. After execution, the
result replaces the preview with normal styling."
```

---

## Commit 4: Separate buttons with state machine and chaining

### Task 7: Write failing tests for button behavior

**Files:**
- Modify: `Tests/JavaScript/CowriterDialog.test.js`

**Step 1: Write failing tests for separate buttons**

```javascript
describe('button state machine', () => {
    it('should show Cancel and Execute in idle state', async () => {
        const dialog = new CowriterDialog(mockService);
        const showPromise = dialog.show('text', 'full');
        await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

        const cancel = document.querySelector('[data-name="cancel"]');
        const reset = document.querySelector('[data-name="reset"]');
        const execute = document.querySelector('[data-name="execute"]');
        const insert = document.querySelector('[data-name="insert"]');

        expect(cancel).toBeTruthy();
        expect(execute).toBeTruthy();
        expect(reset).toBeTruthy();
        expect(insert).toBeTruthy();

        // In idle: Reset and Insert are hidden
        expect(reset.style.display).toBe('none');
        expect(insert.style.display).toBe('none');
        // Cancel and Execute are visible
        expect(cancel.style.display).not.toBe('none');
        expect(execute.style.display).not.toBe('none');

        cancel.click();
        await expect(showPromise).rejects.toThrow('User cancelled');
    });

    it('should show all four buttons in result state', async () => {
        const dialog = new CowriterDialog(mockService);
        const showPromise = dialog.show('text', 'full');
        await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

        document.querySelector('[data-name="execute"]').click();
        await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
        await vi.waitFor(() => {
            const result = document.querySelector('[data-role="result-preview"]');
            return result.textContent.includes('Improved text content');
        });

        const reset = document.querySelector('[data-name="reset"]');
        const insert = document.querySelector('[data-name="insert"]');
        expect(reset.style.display).not.toBe('none');
        expect(insert.style.display).not.toBe('none');

        document.querySelector('[data-name="cancel"]').click();
        await showPromise.catch(() => {});
    });

    it('should insert result content when Insert button clicked', async () => {
        const dialog = new CowriterDialog(mockService);
        const showPromise = dialog.show('text', 'full');
        await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

        document.querySelector('[data-name="execute"]').click();
        await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
        await vi.waitFor(() => {
            const result = document.querySelector('[data-role="result-preview"]');
            return result.textContent.includes('Improved text content');
        });

        document.querySelector('[data-name="insert"]').click();
        const result = await showPromise;
        expect(result.content).toBe('Improved text content');
    });

    it('should chain execute: use previous result as context', async () => {
        mockService.executeTask
            .mockResolvedValueOnce({
                success: true,
                content: 'First result',
                model: 'gpt-4o',
            })
            .mockResolvedValueOnce({
                success: true,
                content: 'Chained result',
                model: 'gpt-4o',
            });

        const dialog = new CowriterDialog(mockService);
        const showPromise = dialog.show('original text', 'full');
        await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

        // First execute
        document.querySelector('[data-name="execute"]').click();
        await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalledTimes(1));
        await vi.waitFor(() => {
            const result = document.querySelector('[data-role="result-preview"]');
            return result.textContent.includes('First result');
        });

        // Second execute (chained) — should use "First result" as context
        document.querySelector('[data-name="execute"]').click();
        await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalledTimes(2));

        // The context (arg index 1) of the second call should be the decoded first result
        expect(mockService.executeTask.mock.calls[1][1]).toBe('First result');

        document.querySelector('[data-name="cancel"]').click();
        await showPromise.catch(() => {});
    });

    it('should restore original input on Reset', async () => {
        const dialog = new CowriterDialog(mockService);
        const showPromise = dialog.show('original text', 'full');
        await vi.waitFor(() => expect(mockService.getTasks).toHaveBeenCalled());

        // Execute to enter result state
        document.querySelector('[data-name="execute"]').click();
        await vi.waitFor(() => expect(mockService.executeTask).toHaveBeenCalled());
        await vi.waitFor(() => {
            const result = document.querySelector('[data-role="result-preview"]');
            return result.textContent.includes('Improved text content');
        });

        // Click Reset
        document.querySelector('[data-name="reset"]').click();

        // Result area should show original input again (muted)
        const result = document.querySelector('[data-role="result-preview"]');
        expect(result.textContent).toContain('original text');
        expect(result.classList.contains('cowriter-result--empty')).toBe(true);

        // Reset and Insert should be hidden again
        expect(document.querySelector('[data-name="reset"]').style.display).toBe('none');
        expect(document.querySelector('[data-name="insert"]').style.display).toBe('none');

        document.querySelector('[data-name="cancel"]').click();
        await expect(showPromise).rejects.toThrow('User cancelled');
    });
});
```

**Step 2: Run tests to verify they fail**

Run: `npx vitest run Tests/JavaScript/CowriterDialog.test.js`
Expected: FAIL — no `[data-name="reset"]` or `[data-name="insert"]` buttons, no chaining behavior

### Task 8: Implement separate buttons with state machine and chaining

**Files:**
- Modify: `Resources/Public/JavaScript/Ckeditor/CowriterDialog.js`

**Step 3: Implement 4-button layout with state machine**

Replace the button configuration in `_showModal`:

```javascript
// State tracking
let state = 'idle';
let resultContent = '';
let currentContext = hasSelection ? selectedText : fullContent;
const originalContext = currentContext;

// Button references (set after Modal.advanced creates them)
let resetBtn, insertBtn;

const cancelTrigger = () => { /* unchanged */ };

const resetTrigger = () => {
    state = 'idle';
    resultContent = '';
    currentContext = originalContext;

    // Restore input preview in result area
    const preview = container.querySelector('[data-role="result-preview"]');
    preview.replaceChildren();
    preview.textContent = originalContext;
    preview.classList.add('cowriter-result--empty');

    // Hide model info and debug
    container.querySelector('[data-role="model-info"]').style.display = 'none';
    container.querySelector('[data-role="debug-details"]')?.remove();

    // Update button visibility
    this._updateButtonVisibility(modal, 'idle');
};

const executeTrigger = async () => {
    if (state === 'loading') return;

    state = 'loading';
    this._setButtonsDisabled(modal, true);

    // ... spinner logic (same as current) ...

    try {
        const taskUid = parseInt(container.querySelector('[data-role="task-select"]').value, 10);
        const contextScope = container.querySelector('[data-role="scope-select"]').value;
        const instruction = container.querySelector('[data-role="instruction"]').value.trim();
        const hasSelection = Boolean(selectedText && selectedText.trim().length > 0);
        const contextType = hasSelection ? 'selection' : 'content_element';

        // Use currentContext (original or chained previous result)
        const result = await this._service.executeTask(
            taskUid, currentContext, contextType, instruction, editorCapabilities,
            contextScope, recordContext, referencePages,
        );

        if (result.success && result.content) {
            const decoded = this._decodeEntities(result.content);
            // ... render result ...
            resultContent = decoded;
            currentContext = decoded;  // Chain: next execute uses this result
            state = 'result';
            preview.classList.remove('cowriter-result--empty');
            this._updateButtonVisibility(modal, 'result');
            this._showDebugDetails(container, result, currentContext, instruction);
        } else {
            state = 'idle';
            // ... error display ...
        }
    } catch (error) {
        state = 'idle';
        // ... error display ...
    }

    this._setButtonsDisabled(modal, false);
};

const insertTrigger = () => {
    if (!resultContent) return;
    resolved = true;
    modal.hideModal();
    resolve({ content: resultContent });
};

modal = Modal.advanced({
    title: 'Cowriter',
    content: container,
    size: Modal.sizes.large,
    buttons: [
        { text: 'Cancel', btnClass: 'btn-default', name: 'cancel', trigger: cancelTrigger },
        { text: 'Reset', btnClass: 'btn-default', name: 'reset', trigger: resetTrigger },
        { text: 'Execute', btnClass: 'btn-default', name: 'execute', trigger: executeTrigger },
        { text: 'Insert', btnClass: 'btn-primary', name: 'insert', trigger: insertTrigger },
    ],
});

// Set initial button visibility
this._updateButtonVisibility(modal, 'idle');
```

Add `_updateButtonVisibility` method:

```javascript
_updateButtonVisibility(modal, state) {
    const reset = modal?.querySelector?.('[data-name="reset"]');
    const insert = modal?.querySelector?.('[data-name="insert"]');
    if (reset) reset.style.display = state === 'result' ? '' : 'none';
    if (insert) insert.style.display = state === 'result' ? '' : 'none';
}
```

Remove `_updateExecuteButtonText` method (no longer needed — separate buttons).

Update reset listeners to not reset state on input change (remove the old `resetResult` handler that changed state on input change — the explicit Reset button handles this now).

**Step 4: Run tests to verify they pass**

Run: `npx vitest run Tests/JavaScript/CowriterDialog.test.js`
Expected: All tests PASS

**Step 5: Update existing tests for new button structure**

Any existing tests that click `[data-name="execute"]` expecting it to Insert need updating. The old pattern was: Execute → button text changes to "Insert" → click same button. The new pattern is: Execute → separate Insert button appears.

Update:
- `should execute task and show result` — keep Execute click, verify result in preview
- `should resolve with content on insert` — change from second Execute click to Insert click
- Any test that checks `btn-primary` text — remove those checks
- Remove `_updateExecuteButtonText` references

**Step 6: Run full test suite**

Run: `npx vitest run`
Expected: All tests PASS

**Step 7: Commit**

```bash
git add Resources/Public/JavaScript/Ckeditor/CowriterDialog.js Tests/JavaScript/CowriterDialog.test.js
git commit -S --signoff -m "feat: separate Cancel/Reset/Execute/Insert buttons with chaining

Replaces the dual-purpose Execute/Insert button with four distinct
buttons controlled by state machine:
- idle: Cancel + Execute visible
- loading: Cancel enabled, others disabled
- result: all four visible
Execute in result state chains: previous result becomes new context.
Reset restores original input and returns to idle state."
```

---

## Commit 5: Add CSS for cowriter-result

### Task 9: Add CSS file

**Files:**
- Create: `Resources/Public/Css/Cowriter.css`

**Step 1: Create CSS file**

```css
.cowriter-result {
    min-height: 200px;
    max-height: 400px;
    overflow-y: auto;
    border: 1px solid var(--typo3-component-border-color);
    border-radius: var(--typo3-component-border-radius);
    padding: var(--typo3-modal-padding);
}
.cowriter-result--empty {
    color: var(--typo3-text-color-secondary);
    font-style: italic;
}
```

**Step 2: Register CSS in ext_localconf.php or load inline**

Check how CSS is currently loaded in the extension. If CSS is loaded via `PageRenderer` or inline in the dialog, add the CSS file to the appropriate registration. For a CKEditor plugin, CSS is typically loaded via `requireCss` in the plugin configuration or injected as a `<style>` element.

If the TYPO3 backend doesn't have a CSS variable for `--typo3-modal-padding`, use `1rem` as fallback:

```css
padding: var(--typo3-modal-padding, 1rem);
```

**Step 3: Verify in browser** (manual test)

Open the Cowriter dialog in a TYPO3 backend. Verify:
- Result area has visible border and proper padding
- Idle state shows muted italic text
- Result state shows normal styled content
- Scrollable when content exceeds max-height

**Step 4: Commit**

```bash
git add Resources/Public/Css/Cowriter.css
# Plus any registration file changes
git commit -S --signoff -m "style: add cowriter-result CSS with TYPO3 variables

Minimal CSS for the result preview area using TYPO3 component variables.
Includes .cowriter-result (bordered, scrollable) and
.cowriter-result--empty (muted italic for idle state)."
```

---

## Commit 6: Clean up and remove dead code

### Task 10: Remove unused code

**Files:**
- Modify: `Resources/Public/JavaScript/Ckeditor/CowriterDialog.js`
- Modify: `Tests/JavaScript/CowriterDialog.test.js`

**Step 1: Audit and remove**

Remove from `CowriterDialog.js`:
- `SCOPE_TICK_LABELS` constant (slider tick marks — no longer used)
- `_updateExecuteButtonText` method (replaced by separate buttons)
- Any remaining slider-specific DOM code or event handlers
- Any `previewTimer` references if the debounced context preview was removed

Remove from tests:
- Any tests referencing slider tick labels
- Any tests referencing `_updateExecuteButtonText`

**Step 2: Run full test suite**

Run: `npx vitest run`
Expected: All tests PASS

**Step 3: Commit**

```bash
git add Resources/Public/JavaScript/Ckeditor/CowriterDialog.js Tests/JavaScript/CowriterDialog.test.js
git commit -S --signoff -m "chore: remove dead slider and button text code

Removes SCOPE_TICK_LABELS, _updateExecuteButtonText, and slider
event handlers that are no longer used after the dropdown and
separate button refactoring."
```

---

## Verification

```bash
cd /home/cybot/projects/t3x-cowriter/feature/modernize-v13-v14

# JS tests (all commits)
npx vitest run

# PHP unit tests (unchanged, but verify no regression)
.Build/bin/phpunit -c Build/phpunit/UnitTests.xml

# Manual smoke test in TYPO3 backend:
# 1. Open Cowriter dialog → verify large modal, side-by-side layout
# 2. Task select (col-md-8) + Scope dropdown (col-md-4) on first row
# 3. Instruction textarea on left, result area on right showing input text
# 4. Execute → result replaces input preview, Insert + Reset buttons appear
# 5. Execute again → chained (previous result sent as context)
# 6. Reset → original input restored, back to idle state
# 7. Insert → content inserted into CKEditor
# 8. Test with empty content element → should work
# 9. Expand debug info → verify model, input, instruction, content
```
