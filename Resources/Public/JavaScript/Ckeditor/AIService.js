// vim: ts=4 sw=4 expandtab colorcolumn=120
// @ts-check

/** @typedef {string} OpenAIAuth */
/**
 * @typedef {object} OpenAIChoice
 * @property {number} index
 * @property {object} message
 * @property {string} message.content
 * @property {string} message.role
 */

/**
 * @readonly
 * @enum {string}
 */
export const APIType = {
    OPENAI: 'openai',
    OLLAMA: 'ollama',
}

export class AIServiceOptions {
    static OPENAI_URL = "https://api.openai.com";

    /**
     * @type {APIType}
     * @module
     */
    _apiType = APIType.OPENAI;

    /**
     * @type {string}
     * @module
     */
    _apiUrl = '';

    /**
     * @type {OpenAIAuth|null}
     * @module
     */
    _auth = null;

    /** 
     * @type {string}
     * @module
     */
    _systemPrompt = "You are a helpful assistant.";

    /**
     * @param {APIType} apiType
     * @param {string} apiUrl
     * @param {OpenAIAuth|null} auth
     */
    constructor(apiType, apiUrl, auth = null) {
        this._apiType = apiType;
        this._apiUrl = apiUrl;
        this._auth = auth;
    }

    /** @param {string} systemPrompt */
    setSystemPrompt(systemPrompt) {
        this._systemPrompt = systemPrompt;
    }

    validate() {
        if (this._apiUrl === '') {
            throw new Error("The provided apiUrl is empty")
        }
        if (this._apiUrl === AIServiceOptions.OPENAI_URL && this._auth === null) {
            throw new Error("OpenAI API needs the authorization option to be set")
        }
    }
}

/**
 * @typedef {object} CompletionOptions
 * @property {string} model
 * @property {number} [maxTokens]
 * @property {number} [temperature] What sampling temperature to use, between 0 and 2. Higher values like 0.8 will make the output more random, while lower values like 0.2 will make it more focused and deterministic.
 * @property {number} [presencePenalty] Number between -2.0 and 2.0. Positive values penalize new tokens based on whether they appear in the text so far, increasing the model's likelihood to talk about new topics.
 * @property {number} [frequencyPenalty] Number between -2.0 and 2.0. Positive values penalize new tokens based on their existing frequency in the text so far, decreasing the model's likelihood to repeat the same line verbatim.
 * @property {number} [topP] An alternative to sampling with temperature, called nucleus sampling, where the model considers the results of the tokens with top_p probability mass. So 0.1 means only the tokens comprising the top 10% probability mass are considered.
 * @property {number} [amount] Number of results to return
 *
 * @link {https://platform.openai.com/docs/api-reference/chat/create
 */

export class AIService {
    /**
     * @type {string[]}
     * @private
     */
    static MODELS_WITHOUT_SYSTEM_PROMPT = ['mistralai/Mistral-7B-Instruct-v0.2'];

    /**
     * @type {AIServiceOptions}
     * @private
     */
    _options;

    /** @param {AIServiceOptions} options */
    constructor(options) {
        if (!(options instanceof AIServiceOptions)) {
            throw new Error("provided options object isn't a AIServiceOptions")
        }

        options.validate();

        this._options = options;
    }

    /**
     * @returns {Headers}
     * @private
     */
    _constructHeaders() {
        const headers = new Headers();

        if (this._options._auth !== undefined && this._options._auth !== "") headers.set("Authorization", `Bearer ${this._options._auth}`);

        return headers;
    };

    /**
     * @returns {Promise<string[]>}
     */
    async fetchModels() {
        switch (this._options._apiType) {
            case APIType.OLLAMA: {
                // https://github.com/ollama/ollama/blob/main/docs/api.md
                return fetch(`${this._options._apiUrl}/api/tags`, { method: "GET", headers: this._constructHeaders() })
                    .then((r) => r.json())
                    .then(({ models }) => models.map(({ name }) => name));
            }
            case APIType.OPENAI: {
                // https://platform.openai.com/docs/api-reference/models
                return fetch(`${this._options._apiUrl}/v1/models`, { method: "GET", headers: this._constructHeaders() })
                    .then((r) => r.json())
                    .then(({ data }) => data.map(({ id }) => id));
            }
            default:
                throw new Error(`Unsupported API type: ${this._options._apiType}`);
        }
    }

    /**
     * @param {string} model
     * @returns {boolean}
     */
    supportsSystemPrompt(model) {
        return !AIService.MODELS_WITHOUT_SYSTEM_PROMPT.includes(model)
    }

    /**
     * @param {string} prompt
     * @param {CompletionOptions} options
     * @return {Promise<{[idx: number]: OpenAIChoice['message']}>}
     */
    async complete(
        prompt,
        { model, amount = 1, maxTokens = 4000, temperature = 0.4, topP = 1, frequencyPenalty = 0, presencePenalty = 0 }
    ) {
        const headers = this._constructHeaders();
        headers.set("Content-Type", "application/json");

        const messages = [
            { role: "user", content: prompt }
        ]

        if (!AIService.MODELS_WITHOUT_SYSTEM_PROMPT.includes(model)) {
            messages.unshift({ role: "system", content: this._options._systemPrompt });
        }

        // https://platform.openai.com/docs/api-reference/chat
        // https://docs.vllm.ai/en/latest/getting_started/quickstart.html#using-openai-chat-api-with-vllm
        const result = await fetch(`${this._options._apiUrl}/v1/chat/completions`, {
            method: "POST",
            headers,
            body: JSON.stringify({
                messages,
                max_tokens: maxTokens,
                model: model,
                temperature: temperature,
                n: amount,
                top_p: topP,
                frequency_penalty: frequencyPenalty,
                presence_penalty: presencePenalty
            })
        })
            .then(r => r.json())
            .then(({ choices }) => choices.reduce(
                (/** @type {{[idx: number]: OpenAIChoice['message']}} */ choices, /** @type {OpenAIChoice} */ choice) => {
                    choices[choice.index] = choice.message;
                    return choices;
                },
                {}
            ));

        return result;
    }
}
