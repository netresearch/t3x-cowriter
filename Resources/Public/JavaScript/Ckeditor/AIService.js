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

/**
 * @typedef {object} CompleteResponse
 * @property {boolean} success - Whether the request succeeded
 * @property {string} content - The generated content
 * @property {string} [model] - The model used for generation
 * @property {string} [finishReason] - Why generation stopped (stop, length, etc.)
 * @property {{promptTokens: number, completionTokens: number, totalTokens: number}} [usage] - Token usage statistics
 */

export class AIService {
    /**
     * TYPO3 AJAX route URLs - populated from TYPO3.settings.ajaxUrls
     * @type {{chat: string|null, complete: string|null, stream: string|null}}
     * @private
     */
    _routes = {
        chat: null,
        complete: null,
        stream: null,
    };

    constructor() {
        // Get AJAX URLs from TYPO3 global settings
        if (typeof TYPO3 !== 'undefined' && TYPO3.settings && TYPO3.settings.ajaxUrls) {
            this._routes.chat = TYPO3.settings.ajaxUrls.tx_cowriter_chat || null;
            this._routes.complete = TYPO3.settings.ajaxUrls.tx_cowriter_complete || null;
            this._routes.stream = TYPO3.settings.ajaxUrls.tx_cowriter_stream || null;
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
     * @returns {Promise<CompleteResponse>} The completion result
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

    /**
     * Send a streaming completion request using Server-Sent Events.
     *
     * @param {string} prompt - The prompt to complete
     * @param {CompletionOptions} [options={}] - Optional completion parameters
     * @param {function(string): void} onChunk - Callback for each content chunk
     * @returns {Promise<{done: boolean, model?: string}>} Final completion status
     */
    async completeStream(prompt, options = {}, onChunk) {
        if (!this._routes.stream) {
            // Fall back to non-streaming if stream route not available
            const result = await this.complete(prompt, options);
            if (onChunk && result.content) {
                onChunk(result.content);
            }
            return { done: true, model: result.model };
        }

        const response = await fetch(this._routes.stream, {
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

        if (!response.body) {
            throw new Error('Streaming not supported: response has no body');
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let lastData = { done: false };

        try {
            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop() || '';

                for (const line of lines) {
                    if (line.startsWith('data: ')) {
                        const data = JSON.parse(line.slice(6));
                        if (data.error) {
                            throw new Error(data.error);
                        }
                        if (data.content && onChunk) {
                            onChunk(data.content);
                        }
                        if (data.done) {
                            lastData = data;
                        }
                    }
                }
            }

            // Process any remaining data in the buffer
            if (buffer.trim().startsWith('data: ')) {
                try {
                    const data = JSON.parse(buffer.trim().slice(6));
                    if (data.content && onChunk) {
                        onChunk(data.content);
                    }
                    if (data.done) {
                        lastData = data;
                    }
                } catch {
                    // Ignore incomplete final chunk
                }
            }
        } finally {
            reader.releaseLock();
        }

        return lastData;
    }
}
