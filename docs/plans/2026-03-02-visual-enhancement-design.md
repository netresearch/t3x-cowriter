# Cowriter Visual Enhancement Design

## Problem

The cowriter produces plain text output even though CKEditor supports rich formatting
(lists, tables, headings, blockquotes, alignment, styles). The LLM doesn't know what
formatting the editor supports, so it defaults to minimal HTML.

## Solution

Pass the editor's runtime capabilities to the LLM so it can actively use available
formatting features. Add new visual-specific tasks alongside enhanced existing prompts.

## Architecture

### A. Frontend Capability Detection (cowriter.js)

Detect available CKEditor plugins AND custom style definitions at runtime:

```javascript
_getEditorCapabilities(editor) {
    const caps = [];
    // Check plugins: HeadingEditing, BoldEditing, ListEditing, TableEditing, etc.
    // Check style definitions from editor.config for custom CSS classes
    return caps.join(', ');
}
```

Capabilities string passed through dialog → API as `editorCapabilities` parameter.

### B. API Extension (ExecuteTaskRequest + AjaxController)

- `ExecuteTaskRequest`: new optional `editorCapabilities` field
- `executeTaskAction`: inject capabilities into prompt as formatting instruction
- Template: `"The editor supports: {capabilities}. Use them to improve visual structure."`

### C. Enhanced Existing Prompts (seed SQL)

Update 6 existing task prompts from passive ("preserve HTML structure") to active
("use lists, tables, headings, blockquotes to improve readability and visual structure").

### D. New Visual Tasks (seed SQL)

| Task | Purpose |
|---|---|
| Format as Table | Convert data/comparisons into HTML tables |
| Add Structure | Add headings, lists, blockquotes to wall-of-text |
| Convert to List | Turn prose into bulleted/numbered lists |
| Enhance Visual Layout | Full visual overhaul with all available formatting |

### E. Demo CKEditor Config (Cowriter.yaml)

Add Highlight + FontColor plugins to the RTE preset for demonstration.
At runtime, capability detection ensures LLM only uses what's actually available.

### F. Data Flow

```
Editor → detect capabilities → dialog → executeTask(taskUid, context, caps)
  → backend builds prompt: task template + context + "Use these formats: {caps}"
  → LLM returns rich HTML → preview via _sanitizeHtml → insert via CKEditor pipeline
```

## Files to Modify

- `Resources/Public/JavaScript/Ckeditor/cowriter.js` — capability detection
- `Resources/Public/JavaScript/Ckeditor/CowriterDialog.js` — pass capabilities
- `Resources/Public/JavaScript/Ckeditor/AIService.js` — send capabilities in API call
- `Classes/Domain/DTO/ExecuteTaskRequest.php` — new field
- `Classes/Controller/AjaxController.php` — inject into prompt
- `.ddev/sql/seed-cowriter-tasks.sql` — enhanced + new prompts
- `Configuration/RTE/Cowriter.yaml` — add demo plugins
- Tests for all of the above
