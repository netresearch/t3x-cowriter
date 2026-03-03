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
    'Improve the following HTML content from a rich text editor. Enhance readability, clarity, and overall quality while preserving the original meaning and structure. Respond with ONLY the improved HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
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
    'Summarize the following HTML content from a rich text editor. Capture the key points and main ideas concisely. Structure the summary clearly using HTML formatting where appropriate. Respond with ONLY the summary as HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
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
    'Expand and elaborate on the following HTML content from a rich text editor. Add depth, detail, and supporting information while matching the existing tone and structure. Respond with ONLY the extended HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
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
    'Translate the following HTML content to English. Preserve the original meaning, tone, and HTML structure. Keep all HTML tags and formatting intact. Respond with ONLY the translated HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
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
    'Translate the following HTML content to German. Preserve the original meaning, tone, and HTML structure. Keep all HTML tags and formatting intact. Respond with ONLY the translated HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
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

-- Visual enhancement tasks

INSERT INTO tx_nrllm_task (
    pid, identifier, name, description, category, configuration_uid,
    prompt_template, input_type, input_source, output_format,
    is_active, is_system, sorting, tstamp, crdate
) VALUES (
    0,
    'cowriter_format_table',
    'Format as Table',
    'Convert data, comparisons, or structured information into an HTML table.',
    'content',
    0,
    'Analyze the following HTML content and convert any data, comparisons, lists of properties, or structured information into well-formatted HTML tables. Use <table>, <thead>, <tbody>, <tr>, <th>, <td> elements. Add a header row where appropriate. Keep any surrounding text that is not tabular data. Respond with ONLY the resulting HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
    'manual',
    '',
    'plain',
    1, 1, 70,
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
    'cowriter_add_structure',
    'Add Structure',
    'Add headings, lists, and blockquotes to organize unstructured text.',
    'content',
    0,
    'Add visual structure to the following HTML content. Break long paragraphs into logical sections with headings. Convert enumerations into bulleted or numbered lists. Use blockquotes for important statements or citations. Add bold for key terms. Use available formatting to organize the content clearly. Preserve the original meaning. Respond with ONLY the structured HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
    'manual',
    '',
    'plain',
    1, 1, 80,
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
    'cowriter_convert_list',
    'Convert to List',
    'Transform prose or paragraphs into organized bulleted or numbered lists.',
    'content',
    0,
    'Convert the following HTML content into well-organized lists. Use numbered lists for sequential steps or ranked items, and bulleted lists for unordered items. Group related items under headings if the content covers multiple topics. Use bold for list item lead-ins where helpful. Respond with ONLY the resulting HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
    'manual',
    '',
    'plain',
    1, 1, 90,
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
    'cowriter_visual_layout',
    'Enhance Visual Layout',
    'Full visual overhaul using all available formatting elements.',
    'content',
    0,
    'Perform a complete visual enhancement of the following HTML content. Use all available HTML formatting to maximize readability and visual appeal: organize with headings, use bulleted and numbered lists, add tables for data, use blockquotes for emphasis, apply bold and italic for key terms, and add horizontal rules between major sections. The goal is a professionally formatted document that is easy to scan and read. Preserve the original meaning. Respond with ONLY the enhanced HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
    'manual',
    '',
    'plain',
    1, 1, 100,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    prompt_template = VALUES(prompt_template),
    is_active = VALUES(is_active),
    tstamp = UNIX_TIMESTAMP();
