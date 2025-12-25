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

*   **OpenAI** - GPT-4, GPT-3.5-turbo
*   **Anthropic Claude** - Claude 3.5, Claude 3
*   **Google Gemini** - Gemini Pro, Gemini Ultra
*   **OpenRouter** - Access to 100+ models
*   **Mistral** - Mistral Large, Mistral Medium
*   **Groq** - Fast inference with Llama, Mixtral

Configuration steps
-------------------

1.  Go to :guilabel:`Admin Tools` > :guilabel:`Settings` > :guilabel:`Extension Configuration`
2.  Select :guilabel:`nr_llm`
3.  Choose your preferred LLM provider
4.  Enter your API key for the selected provider

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
(:file:`your_ext/Configuration/RTE/YourConfig.yml`), add the external plugin:

..  code-block:: yaml

    editor:
      externalPlugins:
        cowriter:
          resource: "EXT:t3_cowriter/Resources/Public/JavaScript/Plugins/cowriter/"

Security considerations
=======================

The Cowriter extension routes all LLM requests through the TYPO3 backend,
ensuring that:

*   API keys are never exposed to the frontend
*   All requests are authenticated via TYPO3's backend session
*   Content generation is logged and auditable

..  warning::

    Always configure your LLM provider's API key in the backend. Never
    expose API keys in frontend JavaScript or client-accessible files.
