# Cowriter Visual Enhancement Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enable the cowriter to produce rich visual content (lists, tables, headings, blockquotes, styling) by detecting the editor's capabilities at runtime and passing them to the LLM.

**Architecture:** The CKEditor plugin detects available formatting features (plugins + CSS class styles) and passes a capabilities string through the dialog → API → backend. The backend injects this into the LLM prompt so the model knows exactly what HTML elements and classes it can use. New visual-specific tasks are added alongside enhanced existing prompts.

**Tech Stack:** CKEditor 5 plugin API, TYPO3 AJAX backend (PHP 8.2+), vitest (JS tests), PHPUnit (PHP tests)

---

### Task 1: Add `_getEditorCapabilities()` to cowriter.js

**Files:**
- Modify: `Resources/Public/JavaScript/Ckeditor/cowriter.js`
- Test: `Tests/JavaScript/cowriter.test.js`

**Step 1: Write the failing test**

In `Tests/JavaScript/cowriter.test.js`, add a new describe block after the `'no _sanitizeContent method'` block:

```javascript
describe('_getEditorCapabilities', () => {
    it('should detect available plugins and return capabilities string', () => {
        const plugin = new Cowriter();
        plugin.editor = {
            plugins: {
                has: vi.fn((name) => ['HeadingEditing', 'BoldEditing', 'ItalicEditing',
                    'ListEditing', 'TableEditing', 'BlockQuoteEditing'].includes(name)),
            },
            config: { get: vi.fn().mockReturnValue(undefined) },
        };

        const caps = plugin._getEditorCapabilities();
        expect(caps).toContain('headings');
        expect(caps).toContain('bold');
        expect(caps).toContain('tables');
        expect(caps).toContain('lists');
        expect(caps).not.toContain('highlight');
    });

    it('should include custom style definitions when configured', () => {
        const plugin = new Cowriter();
        plugin.editor = {
            plugins: { has: vi.fn().mockReturnValue(false) },
            config: {
                get: vi.fn((key) => {
                    if (key === 'style') {
                        return {
                            definitions: [
                                { name: 'Lead', element: 'p', classes: ['lead'] },
                                { name: 'Muted', element: 'span', classes: ['text-muted'] },
                            ],
                        };
                    }
                    return undefined;
                }),
            },
        };

        const caps = plugin._getEditorCapabilities();
        expect(caps).toContain('Lead');
        expect(caps).toContain('Muted');
    });

    it('should return empty string when no plugins available', () => {
        const plugin = new Cowriter();
        plugin.editor = {
            plugins: { has: vi.fn().mockReturnValue(false) },
            config: { get: vi.fn().mockReturnValue(undefined) },
        };

        const caps = plugin._getEditorCapabilities();
        expect(caps).toBe('');
    });
});
```

**Step 2: Run test to verify it fails**

Run: `npx vitest run Tests/JavaScript/cowriter.test.js`
Expected: FAIL — `_getEditorCapabilities is not a function`

**Step 3: Write the implementation**

In `Resources/Public/JavaScript/Ckeditor/cowriter.js`, add before the `async init()` method:

```javascript
/**
 * Detect available CKEditor formatting capabilities at runtime.
 *
 * Queries loaded plugins and style configuration to build a
 * human-readable capabilities string for LLM prompt context.
 *
 * @returns {string} Comma-separated list of available formatting features
 * @private
 */
_getEditorCapabilities() {
    const editor = this.editor;
    const caps = [];

    const pluginMap = [
        ['HeadingEditing', 'headings (h2, h3, h4)'],
        ['BoldEditing', 'bold'],
        ['ItalicEditing', 'italic'],
        ['UnderlineEditing', 'underline'],
        ['StrikethroughEditing', 'strikethrough'],
        ['ListEditing', 'bulleted and numbered lists'],
        ['TableEditing', 'tables with headers and merged cells'],
        ['BlockQuoteEditing', 'blockquotes'],
        ['AlignmentEditing', 'text alignment (left, center, right, justify)'],
        ['LinkEditing', 'hyperlinks'],
        ['HorizontalLineEditing', 'horizontal rules (<hr>)'],
        ['HighlightEditing', 'text highlighting'],
        ['FontColorEditing', 'font colors'],
        ['FontBackgroundColorEditing', 'background colors'],
        ['SubscriptEditing', 'subscript'],
        ['SuperscriptEditing', 'superscript'],
    ];

    for (const [plugin, label] of pluginMap) {
        if (editor.plugins.has(plugin)) {
            caps.push(label);
        }
    }

    // Detect custom style definitions (CSS classes)
    const styleConfig = editor.config.get('style');
    if (styleConfig?.definitions?.length) {
        const styleNames = styleConfig.definitions.map((d) => d.name);
        caps.push(`styles: ${styleNames.join(', ')}`);
    }

    return caps.join(', ');
}
```

**Step 4: Run test to verify it passes**

Run: `npx vitest run Tests/JavaScript/cowriter.test.js`
Expected: PASS

**Step 5: Commit**

```
feat: add runtime CKEditor capability detection
```

---

### Task 2: Pass capabilities through dialog and API

**Files:**
- Modify: `Resources/Public/JavaScript/Ckeditor/cowriter.js` (pass caps to dialog)
- Modify: `Resources/Public/JavaScript/Ckeditor/CowriterDialog.js` (accept + forward caps)
- Modify: `Resources/Public/JavaScript/Ckeditor/AIService.js` (send editorCapabilities)
- Test: `Tests/JavaScript/CowriterDialog.test.js`
- Test: `Tests/JavaScript/AIService.test.js`

**Step 1: Update AIService.executeTask to accept editorCapabilities**

In `AIService.js`, update the `executeTask` method signature and body:

```javascript
/**
 * @param {number} taskUid
 * @param {string} context
 * @param {string} contextType
 * @param {string} [adHocRules='']
 * @param {string} [editorCapabilities='']
 * @returns {Promise<CompleteResponse>}
 */
async executeTask(taskUid, context, contextType, adHocRules = '', editorCapabilities = '') {
    // ... existing validation ...
    body: JSON.stringify({ taskUid, context, contextType, adHocRules, editorCapabilities }),
```

**Step 2: Update CowriterDialog to accept and pass capabilities**

In `CowriterDialog.js`:
- `show(selectedText, fullContent, editorCapabilities)` — accept new param
- `_showModal(tasks, selectedText, fullContent, editorCapabilities)` — pass through
- In `executeTrigger`, pass to `this._service.executeTask(taskUid, context, contextType, adHocRules, editorCapabilities)`

**Step 3: Update cowriter.js to pass capabilities to dialog**

```javascript
const caps = this._getEditorCapabilities();
const dialog = new CowriterDialog(this._service);
const result = await dialog.show(selectedText || '', fullContent, caps);
```

**Step 4: Write tests for AIService**

In `Tests/JavaScript/AIService.test.js`, add test:

```javascript
it('should include editorCapabilities in executeTask request body', async () => {
    // ... setup fetch mock ...
    await service.executeTask(1, 'text', 'selection', '', 'bold, tables, lists');
    const body = JSON.parse(fetchMock.mock.calls[0][1].body);
    expect(body.editorCapabilities).toBe('bold, tables, lists');
});
```

**Step 5: Write tests for CowriterDialog**

In `Tests/JavaScript/CowriterDialog.test.js`, update the `'should call executeTask with correct parameters'` test to include the capabilities param:

```javascript
expect(mockService.executeTask).toHaveBeenCalledWith(
    1, 'my selected text', 'selection', '', '',
);
```

**Step 6: Run tests**

Run: `npx vitest run`
Expected: All pass

**Step 7: Commit**

```
feat: pass editor capabilities through dialog to API
```

---

### Task 3: Backend — add editorCapabilities to ExecuteTaskRequest + inject into prompt

**Files:**
- Modify: `Classes/Domain/DTO/ExecuteTaskRequest.php`
- Modify: `Classes/Controller/AjaxController.php`
- Test: `Tests/Unit/Domain/DTO/ExecuteTaskRequestTest.php`
- Test: `Tests/Unit/Controller/AjaxControllerTest.php`

**Step 1: Write failing test for ExecuteTaskRequest**

In `ExecuteTaskRequestTest.php`:

```php
#[Test]
public function fromRequestParsesEditorCapabilities(): void
{
    $request = $this->createJsonRequest([
        'taskUid'             => 1,
        'context'             => 'text',
        'contextType'         => 'selection',
        'adHocRules'          => '',
        'editorCapabilities'  => 'bold, italic, tables, lists',
    ]);

    $dto = ExecuteTaskRequest::fromRequest($request);

    self::assertSame('bold, italic, tables, lists', $dto->editorCapabilities);
}

#[Test]
public function fromRequestDefaultsEditorCapabilitiesToEmpty(): void
{
    $request = $this->createJsonRequest([
        'taskUid'     => 1,
        'context'     => 'text',
        'contextType' => 'selection',
    ]);

    $dto = ExecuteTaskRequest::fromRequest($request);

    self::assertSame('', $dto->editorCapabilities);
}
```

**Step 2: Run to verify failure, then implement**

Add `editorCapabilities` field to `ExecuteTaskRequest`:

```php
public function __construct(
    public int $taskUid,
    public string $context,
    public string $contextType,
    public string $adHocRules,
    public ?string $configuration,
    public string $editorCapabilities,
) {}
```

In `fromRequest()`:
```php
editorCapabilities: self::extractString($data, 'editorCapabilities'),
```

Add constant and validation:
```php
private const MAX_CAPABILITIES_LENGTH = 2048;

// In isValid():
if (mb_strlen($this->editorCapabilities, 'UTF-8') > self::MAX_CAPABILITIES_LENGTH) {
    return false;
}
```

**Step 3: Write failing test for AjaxController injection**

In `AjaxControllerTest.php`, add test:

```php
#[Test]
public function executeTaskActionInjectsEditorCapabilitiesIntoPrompt(): void
{
    $task = $this->createTaskMock(1, 'improve', 'Improve', 'desc', true);
    $task->method('buildPrompt')->willReturn('Improve this: hello');
    $task->method('getConfiguration')->willReturn(null);

    $this->taskRepositoryMock->method('findByUid')->willReturn($task);
    $config = $this->createConfigurationMock();
    $this->configRepositoryMock->method('findDefault')->willReturn($config);

    $capturedMessages = null;
    $this->llmServiceManagerMock
        ->method('chatWithConfiguration')
        ->willReturnCallback(function (array $messages) use (&$capturedMessages) {
            $capturedMessages = $messages;
            return $this->createCompletionResponse('result');
        });

    $request = $this->createRequestWithJsonBody([
        'taskUid'            => 1,
        'context'            => 'hello',
        'contextType'        => 'selection',
        'editorCapabilities' => 'bold, tables, lists',
    ]);

    $this->subject->executeTaskAction($request);

    // Capabilities should be injected as a system message
    self::assertNotNull($capturedMessages);
    $allContent = implode(' ', array_column($capturedMessages, 'content'));
    self::assertStringContainsString('bold', $allContent);
    self::assertStringContainsString('tables', $allContent);
}
```

**Step 4: Implement capability injection in executeTaskAction**

In `AjaxController.php`, after building messages from the task prompt (line ~453), add:

```php
// Inject editor capabilities as formatting context if provided
if (trim($dto->editorCapabilities) !== '') {
    array_unshift($messages, [
        'role'    => 'system',
        'content' => 'The rich text editor supports these formatting features: '
            . $dto->editorCapabilities
            . '. Actively use these HTML elements and styles to improve the visual structure and readability of the output.',
    ]);
}
```

**Step 5: Run tests**

Run: `composer ci:test:php:unit`
Expected: All pass

**Step 6: Commit**

```
feat: inject editor capabilities into LLM prompt for rich formatting
```

---

### Task 4: Enhance existing task prompts + add new visual tasks

**Files:**
- Modify: `.ddev/sql/seed-cowriter-tasks.sql`

**Step 1: Update the 6 existing prompts**

Change all existing prompts from passive HTML preservation to active formatting encouragement. Example for "Improve Text":

```sql
'Improve the following HTML content from a rich text editor. Enhance readability, clarity, and overall quality while preserving the original meaning. Actively use HTML formatting elements to improve visual structure: use headings (h2, h3) to organize sections, bulleted or numbered lists for sequential or grouped items, blockquotes for emphasis, bold/italic for key terms, and tables where data comparisons are appropriate. Respond with ONLY the improved HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}'
```

Apply similar updates to Summarize, Extend, Fix Grammar, Translate EN, Translate DE — each encouraging the use of formatting elements appropriate for the task.

**Step 2: Add 4 new visual tasks**

Append to the SQL file:

1. **Format as Table** (identifier: `cowriter_format_table`, sorting: 70)
2. **Add Structure** (identifier: `cowriter_add_structure`, sorting: 80)
3. **Convert to List** (identifier: `cowriter_convert_list`, sorting: 90)
4. **Enhance Visual Layout** (identifier: `cowriter_visual_layout`, sorting: 100)

Each with appropriate prompt templates that instruct the LLM to transform content using specific HTML elements.

**Step 3: Commit**

```
feat: enhance prompts for rich formatting, add visual layout tasks
```

---

### Task 5: Add Highlight + FontColor to demo CKEditor preset

**Files:**
- Modify: `Configuration/RTE/Cowriter.yaml`

**Step 1: Add highlight and font color to toolbar and config**

```yaml
toolbar:
  items:
    # ... existing items ...
    - highlight
    - fontColor
    - '|'
    - cowriter

highlight:
  options:
    - { model: 'yellowMarker', class: 'marker-yellow', title: 'Yellow marker', color: '#fdfd77', type: 'marker' }
    - { model: 'greenMarker', class: 'marker-green', title: 'Green marker', color: '#63f963', type: 'marker' }
    - { model: 'pinkMarker', class: 'marker-pink', title: 'Pink marker', color: '#fc7999', type: 'marker' }
```

Note: TYPO3's CKEditor 5 bundled distribution includes Highlight and FontColor plugins — they just need to be added to the toolbar configuration. No npm install needed.

**Step 2: Verify TYPO3 CKEditor includes these plugins**

Check: `Configuration/RTE/Editor/Plugins.yaml` from `rte_ckeditor` extension to confirm availability.

**Step 3: Commit**

```
feat: add highlight and font color plugins to demo RTE preset
```

---

### Task 6: Update all tests + final verification

**Step 1: Run full test suites**

```bash
npx vitest run
composer ci:test:php:unit
```

**Step 2: Fix any failures from existing tests impacted by the new `editorCapabilities` parameter**

Existing tests that call `ExecuteTaskRequest` constructor or `AIService.executeTask` may need the new parameter added.

**Step 3: Commit fixes if needed**

```
test: update tests for editorCapabilities parameter
```

---

## File Summary

| File | Change |
|---|---|
| `Resources/Public/JavaScript/Ckeditor/cowriter.js` | Add `_getEditorCapabilities()`, pass to dialog |
| `Resources/Public/JavaScript/Ckeditor/CowriterDialog.js` | Accept + forward `editorCapabilities` |
| `Resources/Public/JavaScript/Ckeditor/AIService.js` | Send `editorCapabilities` in request |
| `Classes/Domain/DTO/ExecuteTaskRequest.php` | Add `editorCapabilities` field |
| `Classes/Controller/AjaxController.php` | Inject capabilities into LLM prompt |
| `.ddev/sql/seed-cowriter-tasks.sql` | Enhanced prompts + 4 new visual tasks |
| `Configuration/RTE/Cowriter.yaml` | Add highlight + fontColor to toolbar |
| `Tests/JavaScript/cowriter.test.js` | Tests for capability detection |
| `Tests/JavaScript/CowriterDialog.test.js` | Tests for capability passing |
| `Tests/JavaScript/AIService.test.js` | Tests for API call with capabilities |
| `Tests/Unit/Domain/DTO/ExecuteTaskRequestTest.php` | Tests for new field |
| `Tests/Unit/Controller/AjaxControllerTest.php` | Tests for prompt injection |
