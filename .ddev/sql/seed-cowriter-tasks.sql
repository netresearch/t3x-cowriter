-- Seed data for cowriter AI tasks
-- These tasks appear in the cowriter dialog for common text operations.
-- Run with: ddev seed-cowriter-tasks
-- Idempotent: uses INSERT ... ON DUPLICATE KEY UPDATE

INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'cowriter_improve',
    'Improve Text',
    'Enhance readability, clarity, and overall quality while preserving the original meaning.',
    'content',
    0,
    'Improve the following text. Enhance readability, clarity, and overall quality while preserving the original meaning. Respond with ONLY the improved text, without any explanations or commentary.\n\n{{input}}',
    'manual',
    '',
    'plain',
    1, 1, 10,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    is_active = VALUES(is_active),
    tstamp = UNIX_TIMESTAMP();

INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'cowriter_summarize',
    'Summarize',
    'Create a concise summary of the text.',
    'content',
    0,
    'Summarize the following text concisely. Capture the key points and main ideas. Respond with ONLY the summary, without any explanations or commentary.\n\n{{input}}',
    'manual',
    '',
    'plain',
    1, 1, 20,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    is_active = VALUES(is_active),
    tstamp = UNIX_TIMESTAMP();

INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'cowriter_extend',
    'Extend / Elaborate',
    'Add depth, detail, and supporting information to the text.',
    'content',
    0,
    'Expand and elaborate on the following text. Add depth, detail, and supporting information while maintaining the original tone and style. Respond with ONLY the extended text, without any explanations or commentary.\n\n{{input}}',
    'manual',
    '',
    'plain',
    1, 1, 30,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    is_active = VALUES(is_active),
    tstamp = UNIX_TIMESTAMP();

INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'cowriter_fix_grammar',
    'Fix Grammar & Spelling',
    'Correct grammar, spelling, and punctuation with minimal changes to the text.',
    'content',
    0,
    'Fix all grammar, spelling, and punctuation errors in the following text. Make minimal changes — only correct errors, do not rephrase or restructure. Respond with ONLY the corrected text, without any explanations or commentary.\n\n{{input}}',
    'manual',
    '',
    'plain',
    1, 1, 40,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    is_active = VALUES(is_active),
    tstamp = UNIX_TIMESTAMP();

INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'cowriter_translate_en',
    'Translate to English',
    'Translate the text to English.',
    'content',
    0,
    'Translate the following text to English. Preserve the original meaning, tone, and formatting. Respond with ONLY the translation, without any explanations or commentary.\n\n{{input}}',
    'manual',
    '',
    'plain',
    1, 1, 50,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    is_active = VALUES(is_active),
    tstamp = UNIX_TIMESTAMP();

INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'cowriter_translate_de',
    'Translate to German',
    'Translate the text to German.',
    'content',
    0,
    'Translate the following text to German. Preserve the original meaning, tone, and formatting. Respond with ONLY the translation, without any explanations or commentary.\n\n{{input}}',
    'manual',
    '',
    'plain',
    1, 1, 60,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    is_active = VALUES(is_active),
    tstamp = UNIX_TIMESTAMP();
