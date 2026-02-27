..  include:: /Includes.rst.txt

.. _installation:

============
Installation
============

The extension is installed via Composer only.

..  note::

    This extension requires the :composer:`netresearch/nr-llm` extension
    for LLM provider abstraction.

Composer installation
=====================

Install via Composer (the :composer:`netresearch/nr-llm` dependency is installed
automatically):

..  code-block:: bash

    composer require netresearch/t3-cowriter

After installation, activate the extensions in the TYPO3 Extension Manager or via CLI:

..  code-block:: bash

    vendor/bin/typo3 extension:activate nr_llm
    vendor/bin/typo3 extension:activate t3_cowriter

Version matrix
==============

==============  ==============  ================
Extension       TYPO3           PHP
==============  ==============  ================
4.x             13.4 - 14       8.2 - 8.5
3.x             14.0            8.5
2.x             12.4            8.2 - 8.4
1.x             11.5            7.4 - 8.1
==============  ==============  ================

Migration from 2.x
==================

Version 4.0.0 introduces significant architectural changes:

1.  **Install nr-llm extension**: The LLM provider abstraction is now handled
    by the separate :composer:`netresearch/nr-llm` extension.

2.  **Configure providers in nr-llm**: API keys and provider settings are
    now managed through the nr-llm extension configuration.

3.  **Remove old configuration**: The old ``apiKey`` and ``model`` settings
    in the t3_cowriter extension are no longer used.

Benefits of the new architecture:

*   Supports multiple LLM providers (OpenAI, Claude, Gemini, OpenRouter, Mistral, Groq)
*   API keys are securely stored on the server (not exposed to frontend)
*   Centralized LLM configuration for all extensions
