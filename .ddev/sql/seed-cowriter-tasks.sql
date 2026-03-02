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
    'Improve the following HTML content from a rich text editor. Enhance readability, clarity, and overall quality while preserving the original meaning. Actively use HTML formatting elements to improve visual structure: use headings (h2, h3) to organize sections, bulleted or numbered lists for sequential or grouped items, blockquotes for emphasis, bold/italic for key terms, and tables where data comparisons are appropriate. Respond with ONLY the improved HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
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
    'Summarize the following HTML content from a rich text editor. Capture the key points and main ideas. Use HTML formatting to structure the summary: bold for key terms, bulleted lists for main points, headings (h2, h3) if the summary covers multiple topics. Respond with ONLY the summary as HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
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
    'Expand and elaborate on the following HTML content from a rich text editor. Add depth, detail, and supporting information while maintaining the original tone and style. Actively use HTML formatting: add headings (h2, h3) to organize new sections, bulleted or numbered lists for details, bold/italic for emphasis, blockquotes for notable statements, and tables where comparisons are relevant. Respond with ONLY the extended HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
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
    'Add visual structure to the following HTML content. Break long paragraphs into logical sections with headings (h2, h3). Convert enumerations into bulleted or numbered lists (<ul>, <ol>). Use blockquotes for important statements or citations. Add bold (<strong>) for key terms. Preserve the original meaning and content. Respond with ONLY the structured HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
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
    'Convert the following HTML content into well-organized lists. Use numbered lists (<ol>) for sequential steps or ranked items, and bulleted lists (<ul>) for unordered items. Group related items under headings (h2, h3) if the content covers multiple topics. Use bold (<strong>) for list item lead-ins where helpful. Respond with ONLY the resulting HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
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
    'Perform a complete visual enhancement of the following HTML content. Use all available HTML formatting to maximize readability and visual appeal: organize with headings (h2, h3, h4), use bulleted and numbered lists, add tables for data, use blockquotes for emphasis, apply bold and italic for key terms, and add horizontal rules (<hr>) between major sections. The goal is a professionally formatted document that is easy to scan and read. Preserve the original meaning. Respond with ONLY the enhanced HTML, without any explanations, commentary, or markdown formatting.\n\n{{input}}',
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
