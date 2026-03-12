..  include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

LLM provider setup
==================

The Cowriter extension uses the :ref:`nr-llm extension <nrllm:start>` for
LLM provider configuration. Configure your preferred provider in the
:ref:`nr-llm backend module <nrllm:configuration-backend-module>`.

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

Option 1: Using static PageTSconfig
-----------------------------------

If you don't have a custom RTE configuration, include the static PageTSconfig:

1.  Go to your root page
2.  Open :guilabel:`Page Properties` > :guilabel:`Resources`
3.  Add the static PageTSconfig from :guilabel:`t3_cowriter`

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

The four toolbar items are:

``cowriter``
    Main dialog — task-based content generation with preview

``cowriterVision``
    Generate image alt text via LLM vision analysis

``cowriterTranslate``
    Inline translation dropdown (10 languages)

``cowriterTemplates``
    Open the Cowriter dialog with a task pre-selected

..  tip::

    You can include only the toolbar items you need. For example, if you
    only want the main dialog and translation, omit ``cowriterVision``
    and ``cowriterTemplates``.

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
