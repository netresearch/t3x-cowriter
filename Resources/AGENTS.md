# Resources/AGENTS.md

**Scope:** JavaScript/CKEditor 5 integration and frontend assets
**Parent:** [../AGENTS.md](../AGENTS.md)

## Overview

Frontend components for t3_cowriter CKEditor integration. JavaScript communicates with PHP backend via TYPO3 AJAX routes - never directly with LLM APIs.

### Components

- **AIService.js** - API client for backend communication (chat, complete, stream)
- **cowriter.js** - CKEditor 5 plugin integration
- **UrlLoader.js** - CSP-compliant AJAX URL injection from data attributes

### File Structure

```
Resources/
├── Public/
│   ├── Icons/
│   │   ├── Extension.svg      # Extension icon
│   │   └── Module.svg         # Module icon
│   └── JavaScript/
│       └── Ckeditor/
│           ├── AIService.js   # AJAX API client
│           ├── cowriter.js    # CKEditor plugin
│           └── UrlLoader.js   # CSP-compliant URL loader
```

## Architecture

### Data Flow

```
User Action → cowriter.js → AIService.js → TYPO3 AJAX → Backend
                                                    ↓
UI Update ← cowriter.js ← AIService.js ← Response ←┘
```

### Key Principle: No Direct API Calls

All LLM communication goes through the PHP backend:

```javascript
// GOOD: Use TYPO3 AJAX routes
const response = await fetch(TYPO3.settings.ajaxUrls.tx_cowriter_chat, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ messages }),
});

// For streaming responses, use the stream route
const response = await fetch(TYPO3.settings.ajaxUrls.tx_cowriter_stream, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ prompt, ...options }),
});

// BAD: Never call LLM APIs directly
const response = await fetch('https://api.openai.com/v1/chat', {
    headers: { 'Authorization': `Bearer ${apiKey}` }  // NO!
});
```

## Code Style

### JavaScript Standards

- **ES6 modules:** Modern JavaScript syntax
- **TYPO3 AJAX:** Always use TYPO3.settings.ajaxUrls
- **Async/await:** For all asynchronous operations
- **Error handling:** Try/catch with meaningful messages

### Key Patterns

**1. AJAX Route Usage**
```javascript
export class AIService {
    // Use underscore prefix for private members
    _routes = {
        chat: null,
        complete: null,
        stream: null,
    };

    constructor() {
        if (typeof TYPO3 !== 'undefined' && TYPO3.settings && TYPO3.settings.ajaxUrls) {
            this._routes.chat = TYPO3.settings.ajaxUrls.tx_cowriter_chat || null;
            this._routes.complete = TYPO3.settings.ajaxUrls.tx_cowriter_complete || null;
        }
    }

    async chat(messages, options = {}) {
        this._validateRoutes();
        const response = await fetch(this._routes.chat, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ messages, options }),
        });
        return response.json();
    }
}
```

**2. CKEditor Plugin Structure**
```javascript
import { Core, UI } from "@typo3/ckeditor5-bundle.js";

export class Cowriter extends Core.Plugin {
    // Use static property (not getter) for plugin name
    static pluginName = 'cowriter';

    async init() {
        const editor = this.editor;
        // Plugin initialization...
    }
}
```

## Security

### Critical Rules

1. **No API keys in JavaScript:** All keys stay in PHP backend
2. **Use TYPO3 AJAX:** Routes are protected by backend authentication
3. **Validate responses:** Check response structure before using
4. **Error handling:** Never expose internal errors to users

### Input/Output

- **Sanitize input:** Validate user input before sending
- **Safe output:** Use textContent, not innerHTML for AI responses
- **XSS prevention:** Let CKEditor handle content sanitization

## PR/Commit Checklist

### JavaScript-Specific Checks

1. **ES6 syntax:** Use modern JavaScript features
2. **TYPO3 AJAX:** Never direct API calls to LLM providers
3. **Error handling:** All async operations have try/catch
4. **No hardcoded URLs:** Use TYPO3.settings.ajaxUrls
5. **CKEditor patterns:** Follow CKEditor 5 conventions

## Good vs Bad Examples

### Good: AIService Pattern

```javascript
export class AIService {
    _routes = {
        chat: null,
        complete: null,
        stream: null,
    };

    constructor() {
        if (typeof TYPO3 !== 'undefined' && TYPO3.settings && TYPO3.settings.ajaxUrls) {
            this._routes.chat = TYPO3.settings.ajaxUrls.tx_cowriter_chat || null;
            this._routes.complete = TYPO3.settings.ajaxUrls.tx_cowriter_complete || null;
        }
    }

    async chat(messages, options = {}) {
        this._validateRoutes();
        const response = await fetch(this._routes.chat, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ messages, options }),
        });

        if (!response.ok) {
            const error = await response.json().catch(() => ({ error: 'Unknown error' }));
            throw new Error(error.error || `HTTP ${response.status}`);
        }

        return response.json();
    }
}
```

### Bad: Direct API Call

```javascript
// NEVER DO THIS - exposes API keys to browser!
class AIService {
    constructor() {
        this.apiKey = 'sk-...';  // API key in frontend!
    }

    async chat(messages) {
        const response = await fetch('https://api.openai.com/v1/chat/completions', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${this.apiKey}`,  // Exposed!
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                model: 'gpt-4',
                messages,
            }),
        });
        return response.json();
    }
}
```

## When Stuck

### Documentation

- **CKEditor 5:** https://ckeditor.com/docs/ckeditor5/
- **TYPO3 RTE:** https://docs.typo3.org/c/typo3/cms-rte-ckeditor/main/en-us/
- **TYPO3 AJAX:** https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Ajax/Index.html

### Common Issues

- **AJAX 403:** User not logged into TYPO3 backend
- **Route not found:** Check Configuration/Backend/AjaxRoutes.php
- **CKEditor not loading:** Check browser console for import errors
- **Empty response:** Check backend PHP errors in TYPO3 logs

## House Rules

### JavaScript

- **Modules:** Use ES6 import/export
- **Async:** Always use async/await, not callbacks
- **TYPO3 integration:** Use TYPO3.settings for configuration
- **No jQuery:** Use vanilla JavaScript

### CKEditor Integration

- **Plugin pattern:** Extend CKEditor Plugin class
- **Static methods:** Use static get pluginName()
- **Editor reference:** Access via this.editor
- **Commands:** Register via editor.commands.add()

### Error Handling

- **User-friendly:** Show helpful messages, not stack traces
- **Logging:** console.error for debugging
- **Graceful degradation:** Handle failures without breaking editor

## Related

- **[../Classes/AGENTS.md](../Classes/AGENTS.md)** - PHP backend controllers
- **[../Configuration/Backend/AjaxRoutes.php](../Configuration/Backend/AjaxRoutes.php)** - AJAX route definitions
- **[../ext_localconf.php](../ext_localconf.php)** - CKEditor plugin registration
