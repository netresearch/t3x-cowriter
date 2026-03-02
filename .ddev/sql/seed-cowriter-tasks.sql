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
    'Improve the following HTML content from a rich text editor. Enhance readability, clarity, and overall quality while preserving the original meaning and HTML structure. Keep existing HTML tags (p, strong, em, ul, ol, li, h2-h4, a, blockquote). Respond with ONLY the improved HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
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
    'Summarize the following HTML content from a rich text editor. Capture the key points and main ideas. Preserve HTML structure using tags like p, strong, em, ul, ol, li. Respond with ONLY the summary as HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
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
    'Expand and elaborate on the following HTML content from a rich text editor. Add depth, detail, and supporting information while maintaining the original tone, style, and HTML structure. Keep existing HTML tags (p, strong, em, ul, ol, li, h2-h4, a, blockquote). Respond with ONLY the extended HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
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
    'Fix all grammar, spelling, and punctuation errors in the following HTML content from a rich text editor. Make minimal changes — only correct errors, do not rephrase or restructure. Preserve the HTML structure exactly. Respond with ONLY the corrected HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
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
    'Translate the following HTML content to English. Preserve the original meaning, tone, and HTML structure. Keep all HTML tags intact. Respond with ONLY the translated HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
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
    'Translate the following HTML content to German. Preserve the original meaning, tone, and HTML structure. Keep all HTML tags intact. Respond with ONLY the translated HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
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
