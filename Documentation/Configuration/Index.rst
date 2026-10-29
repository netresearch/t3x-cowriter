.. SPDX-License-Identifier: GPL-3.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

LLM provider setup
==================

The Cowriter extension uses the :ref:`nr-llm extension <nrllm:start>` for
LLM provider configuration. Configure your preferred provider in the
:ref:`nr-llm backend module <nrllm:administration-backend-module>`.

Supported providers
-------------------

*   **OpenAI** - GPT-5.x series, o-series reasoning models
*   **Anthropic Claude** - Claude 4.5 Opus, Sonnet, Haiku
*   **Google Gemini** - Gemini 3 Pro, Flash
*   **OpenRouter** - Access to 100+ models from multiple providers
*   **Mistral** - Mistral Large, Medium, and open models
*   **Groq** - Ultra-fast inference with Llama, Mixtral

Configuration steps
-------------------

1.  Navigate to :guilabel:`Admin Tools` > :guilabel:`LLM Management`
2.  Add a provider (e.g., OpenAI with your API key)
3.  Create a model configuration
4.  Create an LLM configuration and set it as default

..  tip::

    See the
    :ref:`nr-llm provider configuration <nrllm:configuration-provider>`
    for detailed provider configuration options.

RTE configuration
=================

There are two ways to configure the CKEditor integration:

Option 1: Using the shipped preset
----------------------------------

If you don't have a custom RTE configuration, point the RTE at the preset
this extension registers:

1.  Go to your root page
2.  Open :guilabel:`Page Properties` > :guilabel:`Page TSconfig`
3.  Add this line:

    ..  code-block:: typoscript

        RTE.default.preset = cowriter

The preset is inherited by every page below, and brings all four toolbar
items with it.

..  figure:: /Images/pagetsconfig.png
    :alt: Page TSconfig configuration in TYPO3 v14
    :class: with-border with-shadow

    The Page TSconfig field with ``RTE.default.preset = cowriter``.

Option 2: Custom RTE configuration
----------------------------------

If you have your own RTE configuration file
(:file:`your_ext/Configuration/RTE/YourConfig.yaml`), add the Cowriter module:

..  code-block:: yaml

    editor:
      config:
        importModules:
          - { module: '@netresearch/t3_cowriter/cowriter', exports: ['Cowriter'] }
        toolbar:
          items:
            - cowriter
            - cowriterVision
            - cowriterTranslate
            - cowriterTemplates
            - cowriterImage

The five toolbar items are:

``cowriter``
    Main dialog — task-based content generation with preview

``cowriterVision``
    Generate image alt text via LLM vision analysis

``cowriterTranslate``
    Inline translation dropdown with the languages of the record's site,
    see :ref:`configuration-translation-languages`

``cowriterTemplates``
    Open the Cowriter dialog with a task pre-selected

``cowriterImage``
    Generate an image from a description and insert it, see
    :ref:`configuration-image-generation`. The button appears only while
    ``image.enable`` is on, and it works only together with the image
    plugin of ``netresearch/rte-ckeditor-image``.

..  tip::

    You can include only the toolbar items you need. For example, if you
    only want the main dialog and translation, omit ``cowriterVision``
    and ``cowriterTemplates``.

..  warning::

    An explicit ``toolbar.items`` list REPLACES the toolbar, it does not add
    to it. Importing the module and then listing only ``cowriter`` gives you
    exactly one button — the other four are registered but have nowhere to
    appear. If buttons are missing, check this list before suspecting the
    extension.

..  _configuration-translation-languages:

Translation languages and formality
===================================

The **Translate** dropdown offers the languages of the site the edited
record belongs to: every language of the site configuration, labelled with
its title. Languages hidden from the frontend (``enabled: false``) are
included, so editors can translate into a language before it goes live.
When the record belongs to no
site (a record on the root page, pid 0, or in a folder outside every site),
the dropdown keeps its fixed list: German, English, French, Spanish,
Italian, Dutch, Portuguese, Polish, Japanese and Chinese. The same list
appears when none of the site's languages has a target code (for example a
site whose only language is ``fil_PH``, see below).

The target language is taken from the language's ``locale``:

*   The two-letter language code is used, the region is dropped:
    ``de_DE.UTF-8`` and ``de_CH.UTF-8`` both translate into ``de``.
*   English and Portuguese keep the region, because DeepL translates into
    their regional variants: ``en_GB`` gives ``en-GB``, ``en_US`` gives
    ``en-US``, ``pt_BR`` gives ``pt-BR``, ``pt_PT`` gives ``pt-PT``. Other
    regions of these languages (``en_AU``) give ``en`` or ``pt``.
*   Chinese gives ``zh``. DeepL translates ``zh`` into Simplified Chinese;
    the language model receives only "Chinese" and chooses the script
    itself. The regions that write Traditional Chinese keep their
    region: ``zh_TW`` gives ``zh-TW``, ``zh_HK`` gives ``zh-HK``, ``zh_MO``
    gives ``zh-MO``; a locale with the script ``Hant`` and no region gives
    ``zh-TW``, one with the script ``Hans`` gives ``zh``. DeepL has no target
    for these codes, so nr-llm translates them with the language model.
*   A language without a two-letter code (``fil_PH``) is not offered, nor
    is the ``C`` or ``POSIX`` locale.

Formality
---------

Each site language can set the formality of translations into it with the
key ``cowriterFormality``:

``default``
    No preference (the default when the key is missing).

``formal``
    Formal address, for German the "Sie" form.

``informal``
    Informal address, for German the "du" form.

Any other value counts as ``default``. DeepL applies the formality for the
languages it supports it for (with nr-llm 0.41: German, French, Italian,
Spanish, Dutch, Polish, Portuguese, Russian, Japanese) and ignores it for
the others; with
a language model as translator it becomes an instruction to keep that tone.

Set the key in the Sites module (:guilabel:`Site Management` >
:guilabel:`Sites`, the language's field
:guilabel:`Cowriter: formality of translations`) or directly in the site
configuration:

..  code-block:: yaml
    :caption: config/sites/<identifier>/config.yaml

    languages:
      - languageId: 0
        title: English
        locale: en_US.UTF-8
        base: /
      - languageId: 1
        title: Deutsch
        locale: de_DE.UTF-8
        base: /de/
        cowriterFormality: formal

With this configuration the dropdown offers "English" (``en-US``) and
"Deutsch" (``de``), and German translations use the formal address.

..  note::

    Saving a site in the Sites module keeps only the language keys that
    have a field in the module. The extension adds a field for
    ``cowriterFormality``, so the setting survives an edit there.

..  _configuration-translation-polish:

Revising DeepL translations
---------------------------

A translation without a pinned LLM configuration goes to a specialized
translator such as DeepL when nr-llm has one for the language pair. A
language model can then revise that translation for fluency, terminology and
tone (nr-llm's "polish", ADR-233). Both settings are in the extension
configuration (:guilabel:`Admin Tools` > :guilabel:`Settings` >
:guilabel:`Extension Configuration` > ``t3_cowriter``); a request from the
editor cannot switch the revision on or change its instruction.

..  confval:: translation.polish
    :name: t3-cowriter-translation-polish
    :type: boolean
    :default: ``0``

    Switches the revision on. It applies only when the specialized
    translator translated the text. A translation that a language model
    writes anyway (no specialized translator for the pair, or a pinned
    configuration) is not revised a second time.

..  confval:: translation.polishInstruction
    :name: t3-cowriter-translation-polishInstruction
    :type: string
    :default: empty

    An additional instruction for the revising model, for example
    ``Address the reader directly.``. It is added to nr-llm's built-in
    revision instruction, which keeps meaning, formality, markup,
    placeholders and glossary terms. An empty value uses the built-in
    instruction alone.

Costs and fallback:

*   A revised translation uses two requests, the translator's and the
    language model's. Both count against the editor's
    :ref:`nr-llm budget <nrllm:administration-user-budgets>`.
*   The Cowriter does not use nr-llm's translation cache. Every translation
    with the revision sends both requests, also when the same text was
    translated before.
*   The revision never fails a translation. When the language model fails,
    the budget refuses the second request, or the answer changes markup,
    placeholders or glossary terms, the editor gets the translator's text
    unchanged.

The response of the translate route carries ``polished`` (``true`` or
``false``) when the setting is on and the specialized translator
translated, and ``polishReason`` with nr-llm's reason when the revision was
not applied (for example ``budget_refused`` or ``markup_changed``). Without
the setting the response is unchanged.

..  _configuration-image-generation:

Image generation
================

With :guilabel:`Cowriter - Generate image` an editor describes an image,
nr-llm's OpenAI image service generates it, and the Cowriter stores it as a
file and inserts it into the text. The feature is off until an administrator
switches it on in the extension configuration (:guilabel:`Admin Tools` >
:guilabel:`Settings` > :guilabel:`Extension Configuration` >
``t3_cowriter``). A request from the editor cannot switch it on or choose
the model.

..  confval:: image.enable
    :name: t3-cowriter-image-enable
    :type: boolean
    :default: ``0``

    Shows the toolbar button and opens the route. While it is off, the
    button is removed from every CKEditor toolbar and the route answers
    with HTTP 403.

..  confval:: image.model
    :name: t3-cowriter-image-model
    :type: string
    :default: empty

    The OpenAI image model, for example ``gpt-image-2`` or
    ``gpt-image-2.5-flare``. Empty uses the active image model that nr-llm
    marks as default in its model list (:guilabel:`Admin Tools` >
    :guilabel:`LLM` > :guilabel:`Models`), or ``gpt-image-2`` when there is
    none. The Cowriter does not read nr-llm's setting
    ``image.dalle.defaultModel``.

Requirements and behaviour:

*   The image plugin of ``netresearch/rte-ckeditor-image`` (extension key
    ``rte_ckeditor_image``) must be loaded in the editor configuration, for
    example by importing its plugin configuration into the preset:

    ..  code-block:: yaml

        imports:
          - { resource: 'EXT:rte_ckeditor_image/Configuration/RTE/Plugin.yaml' }

    The image is inserted as that plugin's image with its file reference
    (``data-htmlarea-file-uid``), like an image chosen in the file browser.
    TYPO3's CKEditor has no image plugin of its own, and the core RTE
    processing removes a plain ``<img>`` on save (``denyTags: img`` in
    :file:`EXT:rte_ckeditor/Configuration/RTE/Processing.yaml`). Without
    the plugin the button is shown disabled, says why, and requests
    nothing.
*   An OpenAI API key in the nr-llm extension configuration
    (``providers.openai.apiKeyIdentifier``).
*   The editor needs a folder to store the image in: the folder TYPO3 uses
    for the editor's uploads (user TSconfig ``options.defaultUploadFolder``,
    otherwise the first default folder of a storage the editor may write
    to). The storage must be public. Without such a folder the request is
    refused before the image is generated, so nothing is paid for.
*   The file is added with the editor's own file permissions and file
    mounts. Its name is made from the first words of the description and a
    random suffix, for example :file:`a-lighthouse-at-dusk-3f9c2a1b.png`.
*   Only the image data the model returns is stored, never a URL. It must be
    a PNG, JPEG or WebP image of at most 10 MB; anything else is refused.
*   Each image counts against the editor's
    :ref:`nr-llm budget <nrllm:administration-user-budgets>`. nr-llm 0.41
    has prices for ``gpt-image-2``, ``gpt-image-1.5``, ``gpt-image-1-mini``,
    ``gpt-image-1`` and the DALL·E models, but none for the
    ``gpt-image-2.5`` models. Unless their record in nr-llm's model list
    carries a price, their images count with cost 0 against a cost limit;
    request limits apply in every case.
*   A generation can take one to two minutes; nr-llm waits up to 300
    seconds for OpenAI. What cuts such a request off is PHP-FPM's
    `request_terminate_timeout
    <https://www.php.net/manual/en/install.fpm.configuration.php>`__ and the
    read timeouts of the web server and of proxies in front of TYPO3; allow
    them more than that. PHP's ``max_execution_time`` does not count the
    wait on Linux, because it measures only the script's own execution and
    not stream operations such as the request to OpenAI (`PHP manual,
    set_time_limit() <https://www.php.net/manual/en/function.set-time-limit.php>`__;
    on Windows the real time counts). A request cut off after the image was
    generated leaves the file in the upload folder without inserting it;
    the editor gets a message that says so.
*   The editor may close the dialog while the image is generated. The
    request goes on, and the image is inserted when it is ready.
*   The description becomes the image's alternative text and the editor is
    told to check it. Extensions that react to new files, such as one that
    writes alternative texts on upload, also run for a generated image.

Task configuration
==================

The Cowriter dialog shows :ref:`tasks <nrllm:configuration-tasks>` from
the nr-llm extension with ``category = 'content'``. Default tasks
(Improve, Summarize, Extend, Fix Grammar, Translate EN/DE) are seeded
during installation.

Adding custom tasks
-------------------

1.  Navigate to :guilabel:`Admin Tools` > :guilabel:`LLM Management`
2.  Create a new task record with ``category = 'content'``
3.  Set a descriptive name and identifier
4.  Write a prompt template using ``{{input}}`` as the placeholder for
    user content

..  code-block:: text
    :caption: Example prompt template

    Rewrite the following text in a more engaging tone, suitable for
    a marketing audience. Output ONLY the rewritten text without
    explanations.

    {{input}}

..  tip::

    Tasks can have their own LLM configuration. If a task has no
    configuration assigned, the request's configuration or the default
    configuration is used as fallback.

..  _configuration-field-suggestions:

Field suggestions
=================

The fields that get the :ref:`Suggest values with AI <usage-field-suggestions>`
button are set in the extension configuration
(:guilabel:`Admin Tools` > :guilabel:`Settings` >
:guilabel:`Extension Configuration` > ``t3_cowriter``):

..  confval:: fieldSuggestions.fields
    :name: t3-cowriter-fieldSuggestions-fields
    :type: string
    :default: ``pages.seo_title,pages.description,pages.keywords,pages.slug``

    Comma-separated list of ``table.field``. Only single-line text fields,
    plain-text areas (no rich text) and slug fields are supported. A field
    that does not exist is skipped, so ``pages.seo_title`` only gets the
    button when the system extension ``seo`` is installed. An empty value
    switches the button off.

..  confval:: fieldSuggestions.count
    :name: t3-cowriter-fieldSuggestions-count
    :type: int
    :default: ``3``

    How many suggestions the button asks for, from 1 to 5. The server
    treats it as the upper bound: a request for more is answered with this
    many.

The list is extension configuration rather than page TSconfig because the
button is added to the TCA, which is the same for the whole installation.
After changing either setting, flush the caches so the TCA is rebuilt.

..  _configuration-saved-prompts:

Saved prompts
=============

Editors save prompts from the Cowriter dialog
(:ref:`usage <usage>`). The records are stored in the table
``tx_cowriter_prompt`` on the root level (page 0), one owner each; an
editor can have up to 100. Only the owner sees an unshared prompt and
only the owner deletes one in the dialog.

..  confval:: prompts.sharedNeedApproval
    :name: t3-cowriter-prompts-sharedNeedApproval
    :type: boolean
    :default: ``1``

    With the setting on, a shared prompt reaches other editors only after
    an administrator approves it: in the :guilabel:`List` module on the
    root page, open the :guilabel:`Cowriter prompt` record and set
    :guilabel:`Approved for other editors`. A prompt an administrator
    shares is approved when saved. With the setting off, every shared
    prompt is visible at once.

Hiding or deleting the record in the List module takes a prompt away
from every editor, its owner included.

..  _configuration-alt-text:

Alt text
========

The :guilabel:`Generate alt text` button sends the selected image with an
instruction to the vision model. The instruction is set in the extension
configuration:

..  confval:: altText.prompt
    :name: t3-cowriter-altText-prompt
    :type: string
    :default: empty (``Generate a concise, descriptive alt text for this image.``)

    The instruction for alt texts. An empty value uses the built-in
    instruction shown as the default.

    Example: ``Write an alt text of at most 120 characters for a screen
    reader. Describe what the image shows, not how it looks. Do not start
    with "Image of".``

The Cowriter adds the language of the edited record to the instruction, for
example ``Answer in German (Germany).``. The language comes from the
record's language field and the locale of that language in the site
configuration, and is resolved on the server. There is no language hint
when the record has not been saved yet, has no language field, belongs to
no site, is set to "all languages", or when the editor may not read the
record, its page or its language.

A request to the AJAX route may still send its own instruction in the
``prompt`` field; it replaces the configured one, as it did before the
setting existed. The button in the editor sends none. The language hint is
added in both cases.

..  _configuration-style:

Audience, tone and target length
================================

The :guilabel:`Audience` and :guilabel:`Tone of voice` selectors in the
Cowriter dialog list the active nr-llm prompt snippets tagged ``audience``
and ``tone_of_voice``. Maintain them in the nr-llm backend module, prompt
snippets; the same snippets steer nr_repurpose. A selector without snippets
is not shown.

The length step is relative to the input, or to a target length per content
type set in page TSconfig:

..  code-block:: typoscript
    :caption: Page TSconfig

    tx_cowriter.targetLength {
        text = 150
        textmedia = 120
    }

..  confval:: tx_cowriter.targetLength.<CType>
    :name: t3-cowriter-tsconfig-targetLength
    :type: int (words)
    :default: none

    The number of words a content element of this type should have. The
    page of the element decides, so subpages inherit it. With a target,
    the length steps ask for 50, 75, 100, 125 or 150 per cent of it, and the
    dialog shows the word count of the answer next to the target.

Rate limiting
=============

The Cowriter enforces a rate limit of 20 requests per minute per
backend user. When the limit is exceeded, the API returns HTTP 429
with a ``Retry-After`` header.

Security considerations
=======================

The Cowriter extension routes all LLM requests through the TYPO3 backend,
ensuring that:

*   API keys are never exposed to the frontend
*   All requests are authenticated via TYPO3's backend session
*   Error conditions are logged for debugging

..  warning::

    Always configure your LLM provider's API key in the backend. Never
    expose API keys in frontend JavaScript or client-accessible files.

Troubleshooting
===============

Translation not working
-----------------------

If the translate button shows "Translation failed", check:

1.  An LLM provider is configured and marked as default in
    :guilabel:`Admin Tools` > :guilabel:`LLM Management`
2.  The provider's API key is valid and not expired
3.  The provider supports the ``translation`` feature
4.  Check the TYPO3 system log for detailed error messages

No tasks in dropdown
--------------------

If the Tasks dropdown shows "No tasks configured":

1.  Navigate to :guilabel:`Admin Tools` > :guilabel:`LLM Management`
    > :guilabel:`Tasks`
2.  Create at least one task with ``category = 'content'``
3.  Make sure the task record is not hidden or deleted
4.  Reload the page in the browser to refresh the task list

API key rejected
----------------

If you see "The LLM provider rejected the API key":

1.  Check the provider configuration in the LLM module
2.  Verify the API key is correct and has not been revoked
3.  Some providers require specific permissions or billing setup

Rate limit exceeded
-------------------

The Cowriter allows 20 requests per minute per backend user. If you
hit the limit, wait a moment and try again. The ``Retry-After``
response header indicates when the limit resets.
