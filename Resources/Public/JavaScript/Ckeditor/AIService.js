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

import { t } from '@netresearch/t3_cowriter/Labels';

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
 * @property {string} [content] - The generated content (when success=true)
 * @property {string} [error] - Error message (when success=false)
 * @property {string} [model] - The model used for generation
 * @property {string} [finishReason] - Why generation stopped (stop, length, etc.)
 * @property {{promptTokens: number, completionTokens: number, totalTokens: number}} [usage] - Token usage statistics
 * @property {string} [thinking] - Model thinking output (when available)
 * @property {Array<{role: string, content: string}>} [debugMessages] - LLM messages sent (dev mode only)
 */

/**
 * Error with optional status URL for configuration issues and the HTTP status
 * of the failed response.
 */
class AIServiceError extends Error {
    /** @type {string|null} */
    statusUrl = null;

    /** @type {number|null} */
    status = null;

    /**
     * @param {string} message
     * @param {string|null} [statusUrl]
     * @param {number|null} [status]
     */
    constructor(message, statusUrl = null, status = null) {
        super(message);
        this.name = 'AIServiceError';
        this.statusUrl = statusUrl;
        this.status = status;
    }
}

/**
 * The style choices that differ from "no preference", as request fields.
 *
 * @param {{audience?: number, tone?: number, length?: number}} style
 * @returns {Record<string, number>}
 */
function styleFields(style) {
    const fields = {};
    for (const key of ['audience', 'tone', 'length']) {
        const value = Number(style?.[key] ?? 0);
        if (Number.isInteger(value) && value !== 0) {
            fields[key] = value;
        }
    }
    return fields;
}

export class AIService {
    /**
     * TYPO3 AJAX route URLs - populated from TYPO3.settings.ajaxUrls
     * @type {{chat: string|null, complete: string|null, stream: string|null, tasks: string|null, taskExecute: string|null, context: string|null, vision: string|null, translate: string|null, templates: string|null, tools: string|null, pageSearch: string|null, tasksModule: string|null, llmModule: string|null, statusModule: string|null}}
     * @private
     */
    _routes = {
        chat: null,
        complete: null,
        stream: null,
        configurations: null,
        styleOptions: null,
        tasks: null,
        taskExecute: null,
        taskStream: null,
        context: null,
        vision: null,
        translate: null,
        templates: null,
        tools: null,
        pageSearch: null,
        tasksModule: null,
        llmModule: null,
        statusModule: null,
    };

    constructor() {
        // Get AJAX URLs from TYPO3 global settings
        if (typeof TYPO3 !== 'undefined' && TYPO3.settings && TYPO3.settings.ajaxUrls) {
            this._routes.chat = TYPO3.settings.ajaxUrls.tx_cowriter_chat || null;
            this._routes.complete = TYPO3.settings.ajaxUrls.tx_cowriter_complete || null;
            this._routes.stream = TYPO3.settings.ajaxUrls.tx_cowriter_stream || null;
            this._routes.configurations = TYPO3.settings.ajaxUrls.tx_cowriter_configurations || null;
            this._routes.styleOptions = TYPO3.settings.ajaxUrls.tx_cowriter_style_options || null;
            this._routes.tasks = TYPO3.settings.ajaxUrls.tx_cowriter_tasks || null;
            this._routes.taskExecute = TYPO3.settings.ajaxUrls.tx_cowriter_task_execute || null;
            this._routes.taskStream = TYPO3.settings.ajaxUrls.tx_cowriter_task_stream || null;
            this._routes.context = TYPO3.settings.ajaxUrls.tx_cowriter_context || null;
            this._routes.vision = TYPO3.settings.ajaxUrls.tx_cowriter_vision || null;
            this._routes.translate = TYPO3.settings.ajaxUrls.tx_cowriter_translate || null;
            this._routes.templates = TYPO3.settings.ajaxUrls.tx_cowriter_templates || null;
            this._routes.tools = TYPO3.settings.ajaxUrls.tx_cowriter_tools || null;
            this._routes.pageSearch = TYPO3.settings.ajaxUrls.tx_cowriter_page_search || null;
            this._routes.tasksModule = TYPO3.settings.ajaxUrls.nrllm_tasks_module || null;
            this._routes.llmModule = TYPO3.settings.ajaxUrls.nrllm_module || null;
            this._routes.statusModule = TYPO3.settings.ajaxUrls.cowriter_status || null;
        }
    }

    /**
     * Get a backend module URL by key.
     * @param {string} key - Route key (e.g. 'tasksModule', 'llmModule')
     * @returns {string|null}
     */
    getModuleUrl(key) {
        return this._routes[key] || null;
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
     * Throw an error from a failed response, preserving statusUrl if present.
     * @param {Response} response
     * @private
     */
    async _throwResponseError(response) {
        const body = await response.json().catch(() => ({ error: t('ckeditor.unknownError', 'Unknown error') }));
        throw new AIServiceError(
            body.error || `HTTP ${response.status}`,
            body.statusUrl || null,
            response.status,
        );
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
            await this._throwResponseError(response);
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
            await this._throwResponseError(response);
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
            // Try JSON first, then SSE format (backend may return text/event-stream errors)
            const text = await response.text().catch(() => '');
            let errorMessage = `HTTP ${response.status}`;
            let statusUrl = null;
            try {
                const json = JSON.parse(text);
                errorMessage = json.error || errorMessage;
                statusUrl = json.statusUrl || null;
            } catch {
                // Try SSE format: "data: {"error": "..."}\n\n"
                const match = text.match(/^data:\s*(.+)/m);
                if (match) {
                    try {
                        const sseData = JSON.parse(match[1]);
                        errorMessage = sseData.error || errorMessage;
                        statusUrl = sseData.statusUrl || null;
                    } catch { /* use HTTP status fallback */ }
                }
            }
            throw new AIServiceError(errorMessage, statusUrl);
        }

        return this._readEventStream(response, onChunk);
    }

    /**
     * Read a Server-Sent Events body: hand the text of each content event to
     * onChunk, throw on an error event, and return the final done event. A
     * done event's own content is the complete answer and is not a chunk.
     * Lines other than `data:` (the padding comments) are skipped.
     *
     * @param {Response} response
     * @param {function(string): void} [onChunk]
     * @returns {Promise<{done: boolean, model?: string, content?: string}>}
     * @private
     */
    async _readEventStream(response, onChunk) {
        if (!response.body) {
            throw new Error('Streaming not supported: response has no body');
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let lastData = { done: false };

        const handle = (line) => {
            if (!line.startsWith('data: ')) {
                return;
            }
            let data;
            try {
                data = JSON.parse(line.slice(6));
            } catch {
                return; // Skip malformed SSE chunks
            }
            if (data.error) {
                throw new Error(data.error);
            }
            if (data.done) {
                lastData = data;
                return;
            }
            if (data.content && onChunk) {
                onChunk(data.content);
            }
        };

        try {
            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop() || '';
                lines.forEach(handle);
            }

            // Flush any remaining bytes from the TextDecoder's internal buffer
            buffer += decoder.decode();
            handle(buffer.trim());
        } finally {
            reader.releaseLock();
        }

        return lastData;
    }

    /**
     * Fetch the LLM configurations the current backend user may use.
     *
     * @returns {Promise<{success: boolean, configurations: Array<{identifier: string, name: string, isDefault: boolean}>}>}
     */
    async getConfigurations() {
        if (!this._routes.configurations) {
            throw new Error(
                'TYPO3 AJAX routes not configured. Ensure the cowriter extension is properly installed.'
            );
        }

        const response = await fetch(this._routes.configurations, {
            method: 'GET',
        });

        if (!response.ok) {
            await this._throwResponseError(response);
        }

        return response.json();
    }

    /**
     * Fetch the audience and tone-of-voice prompt snippets the dialog offers.
     *
     * @returns {Promise<{success: boolean, audiences: Array<{uid: number, name: string}>,
     *     tones: Array<{uid: number, name: string}>}>}
     */
    async getStyleOptions() {
        if (!this._routes.styleOptions) {
            throw new Error(
                'TYPO3 AJAX routes not configured. Ensure the cowriter extension is properly installed.'
            );
        }

        const response = await fetch(this._routes.styleOptions, {
            method: 'GET',
        });

        if (!response.ok) {
            await this._throwResponseError(response);
        }

        return response.json();
    }

    /**
     * Fetch available cowriter tasks.
     *
     * @returns {Promise<{success: boolean, tasks: Array<{uid: number, identifier: string, name: string, description: string, promptTemplate: string}>}>}
     */
    async getTasks() {
        if (!this._routes.tasks) {
            throw new Error(
                'TYPO3 AJAX routes not configured. Ensure the cowriter extension is properly installed.'
            );
        }

        const response = await fetch(this._routes.tasks, {
            method: 'GET',
        });

        if (!response.ok) {
            await this._throwResponseError(response);
        }

        return response.json();
    }

    /**
     * Fetch a lightweight context preview (summary + word count).
     *
     * @param {string} table - Record table name
     * @param {number} uid - Record UID
     * @param {string} field - Record field name
     * @param {string} scope - Context scope
     * @returns {Promise<{success: boolean, summary: string, wordCount: number}>}
     */
    async getContext(table, uid, field, scope) {
        if (!this._routes.context) {
            throw new Error(
                'TYPO3 AJAX routes not configured. Ensure the cowriter extension is properly installed.'
            );
        }

        const params = new URLSearchParams({ table, uid: String(uid), field, scope });
        const separator = this._routes.context.includes('?') ? '&' : '?';
        const url = `${this._routes.context}${separator}${params.toString()}`;

        const response = await fetch(url, {
            method: 'GET',
        });

        if (!response.ok) {
            await this._throwResponseError(response);
        }

        return response.json();
    }

    /**
     * Execute a cowriter task with context.
     *
     * @param {number} taskUid
     * @param {string} context
     * @param {string} contextType
     * @param {string} [instruction='']
     * @param {string} [editorCapabilities='']
     * @param {string} [contextScope='']
     * @param {{table: string, uid: number, field: string}|null} [recordContext=null]
     * @param {Array<{pid: number, relation: string}>} [referencePages=[]]
     * @param {AbortSignal} [signal] - Optional AbortSignal to cancel the request
     * @param {string} [configuration=''] - Identifier of the LLM configuration the editor chose; '' for the task's own
     * @param {{audience?: number, tone?: number, length?: number}} [style={}] - The editor's style choices
     * @returns {Promise<CompleteResponse>}
     */
    async executeTask(
        taskUid, context, contextType,
        instruction = '', editorCapabilities = '',
        contextScope = '', recordContext = null, referencePages = [],
        signal = undefined, configuration = '', style = {},
    ) {
        if (!this._routes.taskExecute) {
            throw new Error(
                'TYPO3 AJAX routes not configured. Ensure the cowriter extension is properly installed.'
            );
        }

        const response = await fetch(this._routes.taskExecute, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                taskUid, context, contextType, instruction, editorCapabilities,
                contextScope, recordContext, referencePages,
                ...(configuration ? { configuration } : {}),
                ...styleFields(style),
            }),
            signal,
        });

        if (!response.ok) {
            await this._throwResponseError(response);
        }

        return response.json();
    }

    /**
     * Execute a cowriter task and receive the answer while it is written.
     *
     * Takes the same fields as executeTask(). A refusal before the stream
     * starts (rate limit, task, configuration) arrives as JSON and is thrown
     * as an AIServiceError; an error during the stream is thrown as an Error.
     *
     * @param {{taskUid: number, context: string, contextType: string, instruction?: string,
     *     editorCapabilities?: string, contextScope?: string,
     *     recordContext?: {table: string, uid: number, field: string}|null,
     *     referencePages?: Array<{pid: number, relation: string}>, configuration?: string}} request
     * @param {function(string): void} onChunk - Receives each piece of text as it arrives
     * @param {AbortSignal} [signal] - Optional AbortSignal to cancel the request
     * @returns {Promise<{done: boolean, model?: string, content?: string}>} The final event, whose
     *     content is the complete answer as HTML
     */
    async executeTaskStream(request, onChunk, signal = undefined) {
        if (!this._routes.taskStream) {
            throw new Error(
                'TYPO3 AJAX routes not configured. Ensure the cowriter extension is properly installed.'
            );
        }

        const { configuration = '', audience = 0, tone = 0, length = 0, ...fields } = request;
        const response = await fetch(this._routes.taskStream, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                ...fields,
                ...(configuration ? { configuration } : {}),
                ...styleFields({ audience, tone, length }),
            }),
            signal,
        });

        if (!response.ok) {
            await this._throwResponseError(response);
        }

        return this._readEventStream(response, onChunk);
    }

    /**
     * Analyze an image and generate alt text or description.
     *
     * @param {string} imageUrl - URL of the image to analyze
     * @param {string} [prompt] - Custom analysis prompt
     * @returns {Promise<{success: boolean, altText: string, model: string, confidence: number}>}
     */
    async analyzeImage(imageUrl, prompt) {
        if (!this._routes.vision) {
            throw new Error('Vision route not configured.');
        }

        const body = { imageUrl };
        if (prompt) {
            body.prompt = prompt;
        }

        const response = await fetch(this._routes.vision, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });

        if (!response.ok) {
            await this._throwResponseError(response);
        }

        return response.json();
    }

    /**
     * Translate text to a target language.
     *
     * @param {string} text - Text to translate
     * @param {string} targetLanguage - ISO 639-1 language code
     * @param {{formality?: string, domain?: string}} [options={}] - Translation options
     * @returns {Promise<{success: boolean, translation: string, sourceLanguage: string, confidence: number}>}
     */
    async translate(text, targetLanguage, options = {}) {
        if (!this._routes.translate) {
            throw new Error('Translation route not configured.');
        }

        const response = await fetch(this._routes.translate, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text, targetLanguage, ...options }),
        });

        if (!response.ok) {
            await this._throwResponseError(response);
        }

        return response.json();
    }

    /**
     * Execute a tool-calling request.
     *
     * @param {string} prompt - The prompt for the LLM
     * @param {string[]} [tools=[]] - Tool names to enable
     * @returns {Promise<{success: boolean, content: string, toolCalls: Array|null, finishReason: string}>}
     */
    async executeTools(prompt, tools = []) {
        if (!this._routes.tools) {
            throw new Error('Tools route not configured.');
        }

        const response = await fetch(this._routes.tools, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ prompt, tools }),
        });

        if (!response.ok) {
            await this._throwResponseError(response);
        }

        return response.json();
    }

    /**
     * Get available prompt templates.
     *
     * @returns {Promise<{success: boolean, templates: Array<{identifier: string, name: string, description: string, category: string}>}>}
     */
    async getTemplates() {
        if (!this._routes.templates) {
            throw new Error('Templates route not configured.');
        }

        const response = await fetch(this._routes.templates);

        if (!response.ok) {
            await this._throwResponseError(response);
        }

        return response.json();
    }

    /**
     * Search pages by title or UID for the reference page picker.
     *
     * @param {string} query - Search query (title substring or UID)
     * @returns {Promise<{success: boolean, pages: Array<{uid: number, title: string, slug: string}>}>}
     */
    async searchPages(query) {
        if (!this._routes.pageSearch) {
            throw new Error('TYPO3 AJAX routes not configured. Ensure the cowriter extension is properly installed.');
        }
        const params = new URLSearchParams({ query });
        const separator = this._routes.pageSearch.includes('?') ? '&' : '?';
        const url = `${this._routes.pageSearch}${separator}${params.toString()}`;
        const response = await fetch(url);
        if (!response.ok) {
            await this._throwResponseError(response);
        }
        return response.json();
    }
}
