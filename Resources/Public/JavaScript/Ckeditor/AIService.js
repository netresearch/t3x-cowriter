// vim: ts=4 sw=4 expandtab colorcolumn=120
// @ts-check

/**
 * AIService - TYPO3 backend integration for LLM requests.
 *
 * This service routes all LLM requests through the TYPO3 backend,
 * which uses the nr-llm extension for provider-agnostic LLM access.
 * API keys are securely stored on the server.
 *
 * @module AIService
 */

/**
 * @typedef {object} ChatMessage
 * @property {string} role - The role (user, assistant, system)
 * @property {string} content - The message content
 */

/**
 * @typedef {object} CompletionOptions
 * @property {number} [maxTokens] - Maximum tokens to generate
 * @property {number} [temperature] - Sampling temperature (0-2)
 */

/**
 * @typedef {object} ChatResponse
 * @property {string} content - The response content
 * @property {string} [role] - The role (usually 'assistant')
 */

export class AIService {
    /**
     * TYPO3 AJAX route URLs - populated from TYPO3.settings.ajaxUrls
     * @type {{chat: string|null, complete: string|null}}
     * @private
     */
    _routes = {
        chat: null,
        complete: null,
    };

    constructor() {
        // Get AJAX URLs from TYPO3 global settings
        if (typeof TYPO3 !== 'undefined' && TYPO3.settings && TYPO3.settings.ajaxUrls) {
            this._routes.chat = TYPO3.settings.ajaxUrls.tx_cowriter_chat || null;
            this._routes.complete = TYPO3.settings.ajaxUrls.tx_cowriter_complete || null;
        }
    }

    /**
     * Validate that AJAX routes are available.
     * @throws {Error} If routes are not configured
     * @private
     */
    _validateRoutes() {
        if (!this._routes.chat || !this._routes.complete) {
            throw new Error(
                'TYPO3 AJAX routes not configured. Ensure the cowriter extension is properly installed.'
            );
        }
    }

    /**
     * Send a chat request with conversation history.
     *
     * @param {ChatMessage[]} messages - Array of messages in the conversation
     * @param {CompletionOptions} [options={}] - Optional completion parameters
     * @returns {Promise<ChatResponse>} The assistant's response
     */
    async chat(messages, options = {}) {
        this._validateRoutes();

        const response = await fetch(this._routes.chat, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ messages, options }),
        });

        if (!response.ok) {
            const error = await response.json().catch(() => ({ error: 'Unknown error' }));
            throw new Error(error.error || `HTTP ${response.status}`);
        }

        return response.json();
    }

    /**
     * Send a single completion request.
     *
     * @param {string} prompt - The prompt to complete
     * @param {CompletionOptions} [options={}] - Optional completion parameters
     * @returns {Promise<{completion: string}>} The completion result
     */
    async complete(prompt, options = {}) {
        this._validateRoutes();

        const response = await fetch(this._routes.complete, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ prompt, options }),
        });

        if (!response.ok) {
            const error = await response.json().catch(() => ({ error: 'Unknown error' }));
            throw new Error(error.error || `HTTP ${response.status}`);
        }

        return response.json();
    }
}

// Legacy exports for backward compatibility (deprecated)
// These will be removed in a future version
export const APIType = {
    OPENAI: 'openai',
    OLLAMA: 'ollama',
};

export class AIServiceOptions {
    /**
     * @deprecated Use the new AIService() without options. Configuration is now handled by nr-llm.
     */
    constructor() {
        console.warn(
            'AIServiceOptions is deprecated. Configuration is now handled by the nr-llm extension.'
        );
    }

    validate() {
        // No-op for backward compatibility
    }
}
