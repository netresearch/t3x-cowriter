-- Demo page tree and content for testing the cowriter CKEditor plugin
-- Creates a minimal site with RTE content for rewrite/summarize/extend testing
-- Run with: ddev seed-pages [v13|v14]

-- =============================================
-- Pages: Root + 3 child pages
-- =============================================

-- Root page (site root)
-- TSconfig enables the cowriter RTE preset so the CKEditor toolbar includes the AI button
INSERT INTO pages (
    uid, pid, title, slug, doktype, is_siteroot, hidden, sorting, TSconfig, tstamp, crdate
) VALUES (
    1, 0, 'Cowriter Demo', '/', 1, 1, 0, 256,
    'RTE.default.preset = cowriter',
    UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    slug = VALUES(slug),
    is_siteroot = VALUES(is_siteroot),
    TSconfig = VALUES(TSconfig),
    tstamp = UNIX_TIMESTAMP();

-- Child page: Welcome
INSERT INTO pages (
    uid, pid, title, slug, doktype, is_siteroot, hidden, sorting, tstamp, crdate
) VALUES (
    2, 1, 'Welcome', '/welcome', 1, 0, 0, 256, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    slug = VALUES(slug),
    tstamp = UNIX_TIMESTAMP();

-- Child page: Blog Article
INSERT INTO pages (
    uid, pid, title, slug, doktype, is_siteroot, hidden, sorting, tstamp, crdate
) VALUES (
    3, 1, 'Blog Article', '/blog-article', 1, 0, 0, 512, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    slug = VALUES(slug),
    tstamp = UNIX_TIMESTAMP();

-- Child page: Product Description
INSERT INTO pages (
    uid, pid, title, slug, doktype, is_siteroot, hidden, sorting, tstamp, crdate
) VALUES (
    4, 1, 'Product Description', '/product-description', 1, 0, 0, 768, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    slug = VALUES(slug),
    tstamp = UNIX_TIMESTAMP();

-- =============================================
-- Content elements: RTE text blocks
-- =============================================

-- Welcome page: intro text
INSERT INTO tt_content (
    uid, pid, CType, header, bodytext, sorting, tstamp, crdate
) VALUES (
    1, 2, 'text', 'Welcome to the Cowriter Demo',
    '<p>This TYPO3 installation demonstrates the <strong>Cowriter</strong> extension, an AI-powered writing assistant integrated directly into CKEditor. Editors can highlight text and use AI to rewrite, summarize, or extend content without leaving the rich text editor.</p>\n<p>The extension connects to configurable LLM providers, including local models via Ollama and cloud-based APIs. It is designed for content teams who want to accelerate their editorial workflow while maintaining full control over tone and style.</p>',
    256, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    header = VALUES(header),
    bodytext = VALUES(bodytext),
    tstamp = UNIX_TIMESTAMP();

-- Welcome page: getting started
INSERT INTO tt_content (
    uid, pid, CType, header, bodytext, sorting, tstamp, crdate
) VALUES (
    2, 2, 'text', 'Getting Started',
    '<p>To test the cowriter plugin, navigate to any page in the page tree and edit a content element with a rich text field. Select some text in the editor, then use the cowriter toolbar button or context menu to invoke AI assistance.</p>\n<p>Available actions include:</p>\n<ul>\n<li><strong>Rewrite</strong> &ndash; rephrase the selected text while preserving meaning</li>\n<li><strong>Summarize</strong> &ndash; condense the selected text into a shorter version</li>\n<li><strong>Extend</strong> &ndash; expand the selected text with additional detail</li>\n<li><strong>Custom prompt</strong> &ndash; provide a free-form instruction for the AI</li>\n</ul>',
    512, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    header = VALUES(header),
    bodytext = VALUES(bodytext),
    tstamp = UNIX_TIMESTAMP();

-- Blog Article page: article body
INSERT INTO tt_content (
    uid, pid, CType, header, bodytext, sorting, tstamp, crdate
) VALUES (
    3, 3, 'text', 'How AI is Transforming Content Management',
    '<p>Content management systems have always been about making it easier for teams to publish and maintain web content. With the rise of large language models, a new generation of editing tools is emerging that goes beyond formatting and layout.</p>\n<h3>The Shift Toward Assisted Editing</h3>\n<p>Traditional CMS editors focus on structure: headings, paragraphs, lists, and media. AI-assisted editing adds a semantic layer. Instead of manually rewording a paragraph, an editor can select text and ask the AI to adjust the tone, simplify the language, or expand a bullet point into a full paragraph.</p>\n<p>This does not replace human judgement. Editors still choose what to publish, review every suggestion, and maintain editorial standards. The AI handles the mechanical work of rephrasing and restructuring, freeing editors to focus on accuracy and voice.</p>\n<h3>Practical Benefits for Editorial Teams</h3>\n<p>Teams that have adopted AI writing assistants report several advantages:</p>\n<ul>\n<li>Faster turnaround on routine content updates such as product descriptions and event announcements</li>\n<li>More consistent tone across pages written by different authors</li>\n<li>Reduced writer''s block when starting from a blank page &ndash; the AI can generate an initial draft from a brief outline</li>\n<li>Easier translation preparation &ndash; simplified source text leads to better machine translation output</li>\n</ul>\n<h3>Privacy and Control</h3>\n<p>A key concern with AI tools is data privacy. The Cowriter extension supports local LLM providers like Ollama, meaning content never leaves the server infrastructure. Organizations can choose between cloud-based providers for maximum capability or local models for full data sovereignty.</p>',
    256, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    header = VALUES(header),
    bodytext = VALUES(bodytext),
    tstamp = UNIX_TIMESTAMP();

-- Product Description page: product intro
INSERT INTO tt_content (
    uid, pid, CType, header, bodytext, sorting, tstamp, crdate
) VALUES (
    4, 4, 'text', 'Enterprise Content Platform',
    '<p>The Enterprise Content Platform is a comprehensive solution for organizations that manage large-scale web presences across multiple brands, regions, and languages. Built on TYPO3, it combines a proven open-source foundation with enterprise-grade features for governance, workflow, and personalization.</p>\n<h3>Key Features</h3>\n<ul>\n<li><strong>Multi-site management</strong> &ndash; run dozens of websites from a single installation with shared assets and centralized user management</li>\n<li><strong>Structured content</strong> &ndash; define reusable content types with custom fields, validation rules, and rendering templates</li>\n<li><strong>Editorial workflow</strong> &ndash; configurable approval chains with role-based permissions and audit logging</li>\n<li><strong>AI-powered editing</strong> &ndash; integrated writing assistant for content creation, summarization, and translation preparation</li>\n</ul>',
    256, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    header = VALUES(header),
    bodytext = VALUES(bodytext),
    tstamp = UNIX_TIMESTAMP();

-- Product Description page: technical specs
INSERT INTO tt_content (
    uid, pid, CType, header, bodytext, sorting, tstamp, crdate
) VALUES (
    5, 4, 'text', 'Technical Specifications',
    '<p>The platform is designed for high-availability deployments and integrates with existing infrastructure through standard protocols and APIs.</p>\n<ul>\n<li><strong>Runtime</strong>: PHP 8.1+ with support for PHP 8.5</li>\n<li><strong>Database</strong>: MariaDB 10.5+, MySQL 8.0+, or PostgreSQL 12+</li>\n<li><strong>Cache</strong>: Redis, Memcached, or APCu for application-level caching</li>\n<li><strong>Search</strong>: Elasticsearch or Apache Solr for full-text search and faceted navigation</li>\n<li><strong>CDN</strong>: Native support for asset distribution via Cloudflare, AWS CloudFront, or custom origins</li>\n</ul>\n<p>All components are containerized and can be deployed via Docker Compose, Kubernetes, or traditional server setups. Infrastructure-as-code templates are provided for AWS, Azure, and on-premises environments.</p>',
    512, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
) ON DUPLICATE KEY UPDATE
    header = VALUES(header),
    bodytext = VALUES(bodytext),
    tstamp = UNIX_TIMESTAMP();

SELECT 'Demo pages imported successfully!' AS status;
SELECT CONCAT('Pages: ', COUNT(*), ' created/updated') AS result FROM pages WHERE uid IN (1,2,3,4);
SELECT CONCAT('Content: ', COUNT(*), ' elements created/updated') AS result FROM tt_content WHERE uid IN (1,2,3,4,5);
