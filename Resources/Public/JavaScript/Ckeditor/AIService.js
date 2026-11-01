/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

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
 * of the failed response. budgetExceeded marks a call the editor's AI budget
 * refused: the message names the limit and is a notice, not a failure.
 */
class AIServiceError extends Error {
    /** @type {string|null} */
    statusUrl = null;

    /** @type {number|null} */
    status = null;

    /** @type {boolean} */
    budgetExceeded = false;

    /**
     * A guardrail stopped the answer, and the message says why.
     * @type {boolean}
     */
    guardrailStop = false;

    /**
     * @param {string} message
     * @param {string|null} [statusUrl]
     * @param {number|null} [status]
     * @param {boolean} [budgetExceeded]
     * @param {boolean} [guardrailStop]
     */
    constructor(message, statusUrl = null, status = null, budgetExceeded = false, guardrailStop = false) {
        super(message);
        this.name = 'AIServiceError';
        this.statusUrl = statusUrl;
        this.status = status;
        this.budgetExceeded = budgetExceeded;
        this.guardrailStop = guardrailStop;
    }
}

/**
 * The style choices that differ from "no preference", as request fields.
 *
 * @param {{audience?: number, tone?: number, length?: number, variants?: number, useTools?: boolean}} style
 * @returns {Record<string, number|boolean>}
 */
function styleFields(style) {
    const fields = {};
    for (const key of ['audience', 'tone', 'length']) {
        const value = Number(style?.[key] ?? 0);
        if (Number.isInteger(value) && value !== 0) {
            fields[key] = value;
        }
    }
    // One version is the default; only a request for more is sent.
    const variants = Number(style?.variants ?? 1);
    if (Number.isInteger(variants) && variants > 1) {
        fields.variants = variants;
    }
    // Tools are off by default; only a request for them is sent.
    if (style?.useTools === true) {
        fields.useTools = true;
    }
    return fields;
}

export class AIService {
    /**
     * TYPO3 AJAX route URLs - populated from TYPO3.settings.ajaxUrls
     * @type {{chat: string|null, complete: string|null, stream: string|null, tasks: string|null, taskExecute: string|null, context: string|null, vision: string|null, translate: string|null, templates: string|null, tools: string|null, pageSearch: string|null, budgetStatus: string|null, imageGenerate: string|null, imageContext: string|null, inlineSuggestion: string|null, tasksModule: string|null, llmModule: string|null, statusModule: string|null}}
     * @private
     */
    _routes = {
        chat: null,
        complete: null,
        stream: null,
        configurations: null,
        styleOptions: null,
        prompts: null,
        promptSave: null,
        promptDelete: null,
        tasks: null,
        taskExecute: null,
        taskStream: null,
        context: null,
        vision: null,
        translate: null,
        templates: null,
        tools: null,
        pageSearch: null,
        budgetStatus: null,
        imageGenerate: null,
        imageContext: null,
        inlineSuggestion: null,
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
            this._routes.prompts = TYPO3.settings.ajaxUrls.tx_cowriter_prompts || null;
            this._routes.promptSave = TYPO3.settings.ajaxUrls.tx_cowriter_prompt_save || null;
            this._routes.promptDelete = TYPO3.settings.ajaxUrls.tx_cowriter_prompt_delete || null;
            this._routes.tasks = TYPO3.settings.ajaxUrls.tx_cowriter_tasks || null;
            this._routes.taskExecute = TYPO3.settings.ajaxUrls.tx_cowriter_task_execute || null;
            this._routes.taskStream = TYPO3.settings.ajaxUrls.tx_cowriter_task_stream || null;
            this._routes.context = TYPO3.settings.ajaxUrls.tx_cowriter_context || null;
            this._routes.vision = TYPO3.settings.ajaxUrls.tx_cowriter_vision || null;
            this._routes.translate = TYPO3.settings.ajaxUrls.tx_cowriter_translate || null;
            this._routes.templates = TYPO3.settings.ajaxUrls.tx_cowriter_templates || null;
            this._routes.tools = TYPO3.settings.ajaxUrls.tx_cowriter_tools || null;
            this._routes.pageSearch = TYPO3.settings.ajaxUrls.tx_cowriter_page_search || null;
            this._routes.budgetStatus = TYPO3.settings.ajaxUrls.tx_cowriter_budget_status || null;
            this._routes.imageGenerate = TYPO3.settings.ajaxUrls.tx_cowriter_image_generate || null;
            this._routes.imageContext = TYPO3.settings.ajaxUrls.tx_cowriter_image_context || null;
            this._routes.inlineSuggestion = TYPO3.settings.ajaxUrls.tx_cowriter_inline_suggestion || null;
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
            body.budgetExceeded === true,
            body.guardrailStop === true,
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
            let budgetExceeded = false;
            try {
                const json = JSON.parse(text);
                errorMessage = json.error || errorMessage;
                statusUrl = json.statusUrl || null;
                budgetExceeded = json.budgetExceeded === true;
            } catch {
                // Try SSE format: "data: {"error": "..."}\n\n"
                const match = text.match(/^data:\s*(.+)/m);
                if (match) {
                    try {
                        const sseData = JSON.parse(match[1]);
                        errorMessage = sseData.error || errorMessage;
                        statusUrl = sseData.statusUrl || null;
                        budgetExceeded = sseData.budgetExceeded === true;
                    } catch { /* use HTTP status fallback */ }
                }
            }
            throw new AIServiceError(errorMessage, statusUrl, response.status, budgetExceeded);
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
                // AIServiceError keeps the status page link a configuration error carries.
                throw new AIServiceError(
                    data.error, data.statusUrl || null, response.status, data.budgetExceeded === true,
                );
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
     * Fetch the prompts the current backend user saved, and the shared
     * prompts of other editors.
     *
     * @returns {Promise<{success: boolean, prompts: Array<{uid: number, title: string, instruction: string,
     *     own: boolean, shared: boolean, awaitingApproval: boolean}>}>}
     */
    async getSavedPrompts() {
        return this._promptRequest(this._routes.prompts, { method: 'GET' });
    }

    /**
     * Save an instruction as a prompt of the current backend user.
     *
     * @param {{title: string, instruction: string, shared: boolean}} prompt
     * @returns {Promise<{success: boolean, prompt: {uid: number, title: string, instruction: string,
     *     own: boolean, shared: boolean, awaitingApproval: boolean}}>}
     */
    async savePrompt(prompt) {
        return this._promptRequest(this._routes.promptSave, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title: prompt.title, instruction: prompt.instruction, shared: prompt.shared === true }),
        });
    }

    /**
     * Delete a prompt the current backend user saved.
     *
     * @param {number} uid
     * @returns {Promise<{success: boolean}>}
     */
    async deletePrompt(uid) {
        return this._promptRequest(this._routes.promptDelete, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ uid }),
        });
    }

    /**
     * @param {string|null} url
     * @param {RequestInit} init
     * @returns {Promise<object>}
     * @private
     */
    async _promptRequest(url, init) {
        if (!url) {
            throw new Error(
                'TYPO3 AJAX routes not configured. Ensure the cowriter extension is properly installed.'
            );
        }

        const response = await fetch(url, init);

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

        const result = await this._readEventStream(response, onChunk);
        if (!result.done) {
            // The stream closed without its final event: what arrived is not the answer.
            throw new AIServiceError(
                t('ckeditor.dialog.streamIncomplete', 'The answer stopped before it was complete. Try again.'),
                null,
                response.status,
            );
        }

        return result;
    }

    /**
     * Analyze an image and generate alt text or description.
     *
     * @param {string} imageUrl - URL of the image to analyze
     * @param {string} [prompt] - Custom analysis prompt; without one the server uses the configured instruction
     * @param {{table: string, uid: number, field: string}|null} [recordContext=null] - The edited record; the server
     *     looks up its language and asks for the alt text in it
     * @returns {Promise<{success: boolean, altText: string, model: string, confidence: number}>}
     */
    async analyzeImage(imageUrl, prompt, recordContext = null) {
        if (!this._routes.vision) {
            throw new Error('Vision route not configured.');
        }

        const body = { imageUrl };
        if (prompt) {
            body.prompt = prompt;
        }
        if (recordContext) {
            body.recordContext = recordContext;
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
     * @returns {Promise<{success: boolean, translation: string, sourceLanguage: string, confidence: number, translator?: string, polished?: boolean, polishReason?: string}>}
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
     * Generate an image from a description. The server stores it in FAL as the
     * editor and answers with the stored file.
     *
     * A generation takes minutes, so a proxy or PHP-FPM may cut the request
     * off after the image was paid for and stored. A gateway timeout (504), a
     * lost connection and an answer that is not this route's JSON therefore
     * say so with their own message, which points to the upload folder. A
     * refusal of the route itself keeps the route's message.
     *
     * @param {string} prompt - Description of the image
     * @returns {Promise<{success: boolean, image: {fileUid: number, fileTable: string, url: string, width: number, height: number, mimeType: string, file: string}, alt: string}>}
     */
    async generateImage(prompt) {
        if (!this._routes.imageGenerate) {
            throw new Error('Image generation route not configured.');
        }

        const interrupted = (status) => new AIServiceError(
            t(
                'ckeditor.image.interrupted',
                'The image request timed out or failed on the way. The image may already be in your upload folder: '
                + 'look there before you generate it again.',
            ),
            null,
            status,
        );

        let response;
        try {
            response = await fetch(this._routes.imageGenerate, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ prompt }),
            });
        } catch {
            throw interrupted(null);
        }

        const body = await response.json().catch(() => null);
        if (body === null || typeof body !== 'object' || response.status === 504) {
            throw interrupted(response.status);
        }

        if (!response.ok) {
            if (typeof body.error !== 'string' || body.error === '') {
                throw interrupted(response.status);
            }
            throw new AIServiceError(body.error, body.statusUrl || null, response.status, body.budgetExceeded === true);
        }

        // A success without the stored file is no answer of this route either;
        // the image may have been stored all the same.
        const image = body.image;
        if (body.success !== true || typeof image !== 'object' || image === null
            || !Number.isInteger(image.fileUid) || image.fileUid <= 0
            || typeof image.url !== 'string' || image.url === '') {
            throw interrupted(response.status);
        }

        return body;
    }

    /**
     * What an image is for and a proposal for its description, built by the
     * server from the record and its page without asking a model, plus the
     * "About this suggestion" data. Optional for the dialog: any failure gives
     * null, and the dialog opens with an empty description.
     *
     * @param {{table?: string, uid?: number|string, field?: string, pid?: number|string}|null} target
     *     the record and field the image is for; null when unknown
     * @returns {Promise<{purpose: string, field: string, proposal: string, about: unknown}|null>}
     */
    async getImageContext(target) {
        if (!this._routes.imageContext) {
            return null;
        }

        const url = new URL(this._routes.imageContext, window.location.href);
        for (const key of ['table', 'uid', 'field', 'pid']) {
            const value = target?.[key];
            if (value !== undefined && value !== null && String(value) !== '') {
                url.searchParams.set(key, String(value));
            }
        }

        try {
            const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
            const body = await response.json();
            if (!response.ok || body?.success !== true) {
                return null;
            }
            const text = (value) => (typeof value === 'string' ? value : '');

            return { purpose: text(body.purpose), field: text(body.field), proposal: text(body.proposal), about: body.about ?? null };
        } catch {
            return null;
        }
    }

    /**
     * A short continuation of the text before the cursor, as plain text; an
     * empty string when the model had none. A refusal throws an
     * AIServiceError with the HTTP status and, for a rate limit, the seconds
     * from Retry-After as `retryAfter`.
     *
     * @param {string} text - The end of the text before the cursor
     * @param {{table: string, uid: number}|null} [recordContext=null] - The edited record; the server
     *     looks up its language
     * @param {AbortSignal} [signal] - Aborts the request when the editor types on
     * @returns {Promise<string>}
     */
    async inlineSuggestion(text, recordContext = null, signal = undefined) {
        if (!this._routes.inlineSuggestion) {
            throw new Error('Inline suggestion route not configured.');
        }

        const body = { text };
        if (recordContext) {
            body.recordContext = recordContext;
        }

        const response = await fetch(this._routes.inlineSuggestion, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            signal,
        });

        if (!response.ok) {
            const retryAfter = Number.parseInt(response.headers?.get?.('Retry-After') ?? '', 10);
            try {
                await this._throwResponseError(response);
            } catch (error) {
                if (Number.isFinite(retryAfter)) {
                    error.retryAfter = retryAfter;
                }
                throw error;
            }
        }

        const data = await response.json();
        return typeof data?.suggestion === 'string' ? data.suggestion : '';
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
     * The editor's own AI usage today and this month against the limits of
     * their budget. The numbers come formatted in the editor's locale.
     *
     * @returns {Promise<{success: boolean, status: {hasPersonalLimit: boolean,
     *     today: Record<string, {used: string, limit: string, limited: boolean, percent: number}>,
     *     month: Record<string, {used: string, limit: string, limited: boolean, percent: number}>}}>}
     */
    async getBudgetStatus() {
        if (!this._routes.budgetStatus) {
            throw new Error('Budget status route not configured.');
        }
        const response = await fetch(this._routes.budgetStatus);
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
