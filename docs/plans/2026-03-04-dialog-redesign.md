# Cowriter Dialog Redesign: Side-by-Side Layout

**Date:** 2026-03-04
**Status:** Approved

## Problem

The current dialog layout is a vertical stack that:
- Wastes horizontal space (task + scope could share a row)
- Hides result until execute (no preview of what's being processed)
- Uses a slider with tick labels that's visually heavy for scope selection
- Has no explicit Reset or iterative refinement flow
- Overloads one button (Execute/Insert) causing confusion

## Design

### Layout: Side-by-Side (Large Modal)

```
+------------------------------------------------------------------+
| Cowriter                                                    [x]  |
+------------------------------------------------------------------+
|                                                                   |
|  +- .row -------------------------------------------------------+ |
|  | .col-md-8                      | .col-md-4                   | |
|  | Task: [Improve Text      v]   | Scope: [Page            v]  | |
|  | "Enhance readability"          |                              | |
|  +--------------------------------+------------------------------+ |
|                                                                   |
|  Ref pages: [5: style guide x]  [+ Add]                          |
|                                                                   |
|  +- .row --------------------------+----------------------------+ |
|  | .col-md-6                       | .col-md-6                  | |
|  |                                 |                            | |
|  | Instruction                     | Result                     | |
|  | +-----------------------------+ | +--------------------------+| |
|  | | Improve: The selected       | | | The selected paragraph  || |
|  | | paragraph about modern      | | | about modern PHP        || |
|  | | PHP features...             | | | features...             || |
|  | |                             | | |          (muted/italic) || |
|  | |                             | | |                         || |
|  | |                             | | |                         || |
|  | +-----------------------------+ | +--------------------------+| |
|  |                                 | Model: gpt-4o | 150 tokens | |
|  |                                 | > Debug info               | |
|  +---------------------------------+----------------------------+ |
|                                                                   |
+------------------------------------------------------------------+
|                    [Cancel]  [Reset]  [Execute]  [Insert]         |
+------------------------------------------------------------------+
```

### Components

| Element | Class | Notes |
|---------|-------|-------|
| Modal | `Modal.sizes.large` | ~900px+ content width |
| Config row | `.row` > `.col-md-8` + `.col-md-4` | Task + Scope side-by-side |
| Task select | `select.form-select` | "Custom instruction" (value=0) first, then tasks |
| Scope select | `select.form-select` | 6 options, replaces slider |
| Reference pages | Inline chips + add button | Compact row |
| Work area | `.row` > `.col-md-6` x2 | Equal columns |
| Instruction | `textarea.form-control` | 10 rows, prefilled from template |
| Result | `div.cowriter-result` | Bordered, scrollable, matches textarea height |
| Stats | `small.form-text` | "Model: X \| N tokens" after execute |
| Debug | `<details>` | Collapsible with copy button |
| Cancel | `btn.btn-default` | Always visible |
| Reset | `btn.btn-default` | Hidden in idle. Restores original input. |
| Execute | `btn.btn-default` | Sends to LLM. In result state: chains output. |
| Insert | `btn.btn-primary` | Hidden in idle. Accepts result into CKEditor. |

### State Machine

| State | Result area | Buttons | Execute behavior |
|-------|-------------|---------|------------------|
| idle | Original input (muted) | Cancel, Execute | Original context + instruction |
| loading | Spinner | Cancel (others disabled) | -- |
| result | Rendered HTML | Cancel, Reset, Execute, Insert | Previous result as context |
| error | Error message | Cancel, Execute | Retry same input |

### Scope Dropdown

Replaces the range slider. Six options:
- Selection (default when text selected)
- Text (default when no selection)
- Element (disabled without recordContext)
- Page (disabled without recordContext)
- +1 ancestor level (disabled without recordContext)
- +2 ancestor levels (disabled without recordContext)

### Data Flow

```
1st execute:  context = selectedText || fullContent
              instruction = textarea value
              -> LLM -> result

2nd execute:  context = previous result (decoded HTML)
(chained)     instruction = textarea value
              -> LLM -> new result

Reset:        context = original (restored)
              result area = original input (muted)
```

### Changes from Current

- Slider -> dropdown (scope selection)
- Hidden result area -> always visible (shows input in idle)
- Single Execute/Insert button -> separate Execute + Insert
- No Reset button -> explicit Reset (restores original input)
- Medium modal -> large modal (side-by-side layout)
- Execute chains results (previous output becomes next input)

### CSS

Minimal custom CSS. Everything uses Bootstrap 5 utilities + TYPO3 component
variables. Only `.cowriter-result` needs styling:

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
