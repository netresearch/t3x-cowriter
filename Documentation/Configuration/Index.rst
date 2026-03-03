..  include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

LLM provider setup
==================

The Cowriter extension uses the :composer:`netresearch/nr-llm` extension for
LLM provider configuration. Configure your preferred provider in the nr-llm
extension settings.

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

    See the `nr-llm documentation <https://github.com/netresearch/t3x-nr-llm>`_
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

..  image:: /Images/pagetsconfig.png
    :alt: PageTSconfig include dialog

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

Task configuration
==================

The Cowriter dialog shows tasks from the nr-llm extension with
``category = 'content'``. Default tasks (Improve, Summarize, Extend,
Fix Grammar, Translate EN/DE) are seeded during installation.

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
