-- Seed data for local Ollama LLM development with t3x-cowriter
-- This creates a pre-configured Ollama provider, models, and sample configurations
-- Run with: ddev seed-ollama

-- Provider: Local Ollama instance
INSERT INTO tx_nrllm_provider (
    pid, identifier, name, description, adapter_type, endpoint_url, api_key,
    api_timeout, max_retries, is_active, priority, sorting, tstamp, crdate
) VALUES (
    0,
    'ollama-local',
    'Local Ollama',
    'Local Ollama LLM server running in DDEV container. No API key required.',
    'ollama',
    'http://ollama:11434',
    '',
    30,
    3,
    1,
    100,
    10,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    endpoint_url = VALUES(endpoint_url),
    priority = VALUES(priority),
    tstamp = UNIX_TIMESTAMP();

-- Get the provider UID for the model relation
SET @ollama_provider_uid = (SELECT uid FROM tx_nrllm_provider WHERE identifier = 'ollama-local' AND deleted = 0 LIMIT 1);

-- Model: Qwen 3 0.6B (default small model)
INSERT INTO tx_nrllm_model (
    pid, identifier, name, description, provider_uid, model_id,
    context_length, max_output_tokens, capabilities, default_timeout,
    cost_input, cost_output, is_active, is_default, sorting, tstamp, crdate
) VALUES (
    0,
    'qwen3-0.6b',
    'Qwen 3 0.6B',
    'Alibaba Qwen 3 with 600M parameters. Fast, efficient for development and testing.',
    @ollama_provider_uid,
    'qwen3:0.6b',
    32768,
    4096,
    'chat,completion,streaming',
    60,
    0,
    0,
    1,
    1,
    10,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    provider_uid = @ollama_provider_uid,
    model_id = VALUES(model_id),
    tstamp = UNIX_TIMESTAMP();

-- Model: Gemma 3 1B (alternative small model)
INSERT INTO tx_nrllm_model (
    pid, identifier, name, description, provider_uid, model_id,
    context_length, max_output_tokens, capabilities, default_timeout,
    cost_input, cost_output, is_active, is_default, sorting, tstamp, crdate
) VALUES (
    0,
    'gemma3-1b',
    'Gemma 3 1B',
    'Google Gemma 3 with 1B parameters. Good quality for its size.',
    @ollama_provider_uid,
    'gemma3:1b',
    32768,
    4096,
    'chat,completion,streaming',
    60,
    0,
    0,
    1,
    0,
    20,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    provider_uid = @ollama_provider_uid,
    model_id = VALUES(model_id),
    tstamp = UNIX_TIMESTAMP();

-- Model: SmolLM2 360M (ultra-small model)
INSERT INTO tx_nrllm_model (
    pid, identifier, name, description, provider_uid, model_id,
    context_length, max_output_tokens, capabilities, default_timeout,
    cost_input, cost_output, is_active, is_default, sorting, tstamp, crdate
) VALUES (
    0,
    'smollm2-360m',
    'SmolLM2 360M',
    'Hugging Face SmolLM2 with 360M parameters. Ultra-fast, minimal resources.',
    @ollama_provider_uid,
    'smollm2:360m',
    8192,
    2048,
    'chat,completion',
    30,
    0,
    0,
    1,
    0,
    30,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    provider_uid = @ollama_provider_uid,
    model_id = VALUES(model_id),
    tstamp = UNIX_TIMESTAMP();

-- Get the default model UID for configurations
SET @default_model_uid = (SELECT uid FROM tx_nrllm_model WHERE identifier = 'qwen3-0.6b' AND deleted = 0 LIMIT 1);

-- Configuration: General Purpose (default for Cowriter)
INSERT INTO tx_nrllm_configuration (
    pid, identifier, name, description, model_uid,
    system_prompt, temperature, max_tokens, top_p, timeout,
    is_active, is_default, sorting, tstamp, crdate
) VALUES (
    0,
    'local-general',
    'Local General Purpose',
    'General-purpose configuration for Cowriter content editing and generation.',
    @default_model_uid,
    'You are a professional writing assistant integrated into a CMS editor. Your task is to improve, enhance, or generate text based on the user''s request. Respond ONLY with the improved/generated text.',
    0.7,
    2048,
    0.9,
    0,
    1,
    1,
    10,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    model_uid = @default_model_uid,
    tstamp = UNIX_TIMESTAMP();

-- Configuration: Content Summarizer
INSERT INTO tx_nrllm_configuration (
    pid, identifier, name, description, model_uid,
    system_prompt, temperature, max_tokens, top_p, timeout,
    is_active, is_default, sorting, tstamp, crdate
) VALUES (
    0,
    'local-summarizer',
    'Local Content Summarizer',
    'Optimized for summarizing articles, documents, and CMS content.',
    @default_model_uid,
    'You are a content summarizer. Create clear, concise summaries that capture the key points. Focus on the most important information and maintain the original meaning.',
    0.3,
    1024,
    0.85,
    0,
    1,
    0,
    20,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    model_uid = @default_model_uid,
    tstamp = UNIX_TIMESTAMP();

-- Configuration: Creative Writing
INSERT INTO tx_nrllm_configuration (
    pid, identifier, name, description, model_uid,
    system_prompt, temperature, max_tokens, top_p, timeout,
    is_active, is_default, sorting, tstamp, crdate
) VALUES (
    0,
    'local-creative',
    'Local Creative Writing',
    'Higher temperature for creative content generation in CMS.',
    @default_model_uid,
    'You are a creative writing assistant. Help generate engaging, imaginative content with varied vocabulary and interesting prose.',
    0.9,
    2048,
    0.95,
    120,
    1,
    0,
    30,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    model_uid = @default_model_uid,
    tstamp = UNIX_TIMESTAMP();

SELECT 'Ollama seed data imported successfully!' AS status;
SELECT CONCAT('Provider: ', name, ' (', identifier, ')') AS created FROM tx_nrllm_provider WHERE identifier = 'ollama-local' AND deleted = 0;
SELECT CONCAT('Models: ', COUNT(*), ' configured') AS created FROM tx_nrllm_model WHERE provider_uid = @ollama_provider_uid AND deleted = 0;
SELECT CONCAT('Configurations: ', COUNT(*), ' configured') AS created FROM tx_nrllm_configuration WHERE model_uid = @default_model_uid AND deleted = 0;
