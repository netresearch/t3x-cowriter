# Cowriter Context Zoom Design

## Problem

The cowriter dialog offers only two context options: "Selected text" and "Whole content element" (bodytext only). Editors need broader context — other content on the page, ancestor pages, or reference pages — so the LLM produces contextually appropriate output.

## Solution

Replace the radio buttons with a "context zoom" range slider that progressively widens scope, plus an optional reference page picker.

## UI: Context Zoom Slider

```
Context scope
|--●-----|---------|---------|---------|---------|
Selection  Text    Element    Page    +1 level  +2 levels

📄 Current: "Selected text (42 words)"
```

### Slider Stops

| Stop | Value | Label | Content Included |
|---|---|---|---|
| 1 | `selection` | Selection | Selected text only |
| 2 | `text` | Text | Full bodytext of current tt_content |
| 3 | `element` | Element | All text fields of current tt_content (header, subheader, bodytext, etc.) |
| 4 | `page` | Page | All tt_content records on current page, in sorting order |
| 5 | `ancestors_1` | +1 level | Page content + parent page content |
| 6 | `ancestors_2` | +2 levels | Page content + 2 ancestor levels |

- When no text is selected, slider starts at stop 2 and stop 1 is disabled
- Moving the slider shows a live summary with word count
- Summary fetched via lightweight `getContextAction` AJAX call

### Reference Page Picker

Below the slider, an optional section:

```
+ Add reference page                    [Browse...]
  ┌──────────────────────────────────────────────┐
  │ "About Us" (id:5)  ✕   Relation: [reference ▾]  │
  └──────────────────────────────────────────────┘
```

- Uses TYPO3's `ElementBrowser` page tree
- Each page has a "Relation" free-text combobox with presets: "reference material", "parent topic", "style guide", "similar content"
- Multiple pages can be added
- Removable with ✕ button

## Architecture

### Record Context Extraction (Frontend)

The CKEditor plugin extracts record context from the DOM:

```javascript
// textarea name="data[tt_content][123][bodytext]"
const nameAttr = editor.sourceElement?.closest('[name]')?.name
    || editor.sourceElement?.querySelector('textarea')?.name;
// Parse: { table: 'tt_content', uid: 123, field: 'bodytext' }
```

This is passed to the dialog and included in AJAX requests.

### Data Flow

```
CKEditor Plugin → extracts table/uid/field from DOM
    ↓
CowriterDialog → renders slider, handles scope changes
    ↓
On slider change: GET /cowriter/context?table=tt_content&uid=123&scope=page
    → { summary: "3 elements, ~420 words", wordCount: 420 }
    ↓
On execute: POST /cowriter/task-execute
    { taskUid, context, contextScope, recordContext: {table, uid, field},
      referencePages: [{pid: 5, relation: "reference material"}],
      adHocRules, editorCapabilities }
    ↓
Backend assembles full context from DB → builds LLM prompt → returns result
```

### Backend: Context Assembly

**New AJAX endpoint: `getContextAction`** (lightweight preview)

```php
GET /cowriter/context?table=tt_content&uid=123&scope=page
→ { "summary": "3 elements, ~420 words", "wordCount": 420 }
```

**Extended `executeTaskAction`**: resolves context server-side based on `contextScope`:

| Scope | Backend Action |
|---|---|
| `selection` / `text` | Use `context` from request body (current behavior) |
| `element` | Fetch full tt_content record by UID — all text fields |
| `page` | Fetch all tt_content on the record's PID, sorted by `sorting` |
| `ancestors_N` | Walk page tree N levels up, fetch content for each page |
| Reference pages | Fetch tt_content for each referenced PID |

### Context Assembly Format

```
=== Current content element (tt_content #123) ===
Header: Welcome to Our Service
Bodytext: <p>We provide excellent solutions...</p>

=== Page: "Services" (pid=5) ===

--- Content element: tt_content #124 ---
Header: Our Process
Bodytext: <p>Our process involves three steps...</p>

=== Reference page: "About Us" (pid=3) — Relation: style guide ===
--- Content element: tt_content #200 ---
Header: Our Story
Bodytext: <p>Founded in 2001...</p>
```

### New DTO: ContextRequest

```php
final readonly class ContextRequest {
    public function __construct(
        public string $table,
        public int $uid,
        public string $field,
        public string $scope,           // selection|text|element|page|ancestors_1|ancestors_2
        public array $referencePages,   // [{pid: int, relation: string}, ...]
    ) {}
}
```

### Extended ExecuteTaskRequest

Add fields:
- `contextScope`: string (default: 'selection' for backwards compatibility)
- `recordContext`: `{table: string, uid: int, field: string}` (optional)
- `referencePages`: `[{pid: int, relation: string}]` (optional, default: [])

## Security

- Content fetching respects `$GLOBALS['BE_USER']` page/record permissions
- Only `tt_content` and `pages` tables are queryable (whitelist)
- Reference page UIDs validated against user's page access list
- `contextScope` validated against allowed values
- Word count limit to prevent excessive context (configurable, default 10000 words)

## Files to Modify

### Frontend
- `Resources/Public/JavaScript/Ckeditor/cowriter.js` — extract record context from DOM
- `Resources/Public/JavaScript/Ckeditor/CowriterDialog.js` — slider UI, reference page picker, context preview
- `Resources/Public/JavaScript/Ckeditor/AIService.js` — new `getContext()` method, extended `executeTask()`

### Backend
- `Classes/Controller/AjaxController.php` — new `getContextAction()`, extended `executeTaskAction()`
- `Classes/Domain/DTO/ExecuteTaskRequest.php` — add contextScope, recordContext, referencePages
- `Classes/Domain/DTO/ContextRequest.php` — new DTO for context preview
- `Classes/Service/ContextAssemblyService.php` — new service for fetching and assembling content
- `Configuration/Backend/AjaxRoutes.php` — add `tx_cowriter_context` route

### Tests
- `Tests/JavaScript/cowriter.test.js` — record context extraction
- `Tests/JavaScript/CowriterDialog.test.js` — slider behavior, reference page UI
- `Tests/JavaScript/AIService.test.js` — new getContext, extended executeTask
- `Tests/Unit/Domain/DTO/ExecuteTaskRequestTest.php` — new fields
- `Tests/Unit/Domain/DTO/ContextRequestTest.php` — new DTO tests
- `Tests/Unit/Service/ContextAssemblyServiceTest.php` — context assembly logic
- `Tests/Unit/Controller/AjaxControllerTest.php` — new endpoint, extended execute
