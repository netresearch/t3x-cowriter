..  include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

API key setup
=============

1.  Create a new API key at https://openai.com/api/
2.  Add the API key to your TYPO3 Extension configuration:

    *   Go to :guilabel:`Admin Tools` > :guilabel:`Settings` > :guilabel:`Extension Configuration`
    *   Select :guilabel:`t3_cowriter`
    *   Enter your OpenAI API key

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

Extension settings
==================

..  confval:: apiKey
    :name: extension-apiKey
    :type: string
    :Default: (empty)

    Your OpenAI API key. Required for the extension to work.

..  confval:: model
    :name: extension-model
    :type: string
    :Default: gpt-3.5-turbo

    The OpenAI model to use for content generation.
