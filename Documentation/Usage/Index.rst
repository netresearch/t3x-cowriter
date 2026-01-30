..  include:: /Includes.rst.txt

.. _usage:

=====
Usage
=====

Using the AI Cowriter
=====================

Once the extension is installed and configured:

1.  Open any content element with a rich text field in the TYPO3 backend
2.  You will see a new AI Cowriter button in the CKEditor toolbar
3.  Click the button to open the AI prompt dialog
4.  Enter a description of the text you want to generate
5.  The AI will generate content based on your prompt and insert it into the editor

Tips for effective prompts
==========================

*   Be specific about the type of content you need
*   Mention the target audience if relevant
*   Specify the desired tone (formal, casual, technical, etc.)
*   Include any keywords that should be used

Example prompts
===============

*   "Write an introduction paragraph about our company's 25 years of experience
    in web development"
*   "Create a bullet-point list of benefits for using our e-commerce solution"
*   "Write a call-to-action paragraph encouraging visitors to contact us"

Model override
==============

You can override the default model for a specific prompt by using the
``#cw:`` prefix followed by the model identifier:

..  code-block:: text

    #cw:gpt-5.2-thinking Write a detailed technical analysis of our API architecture

The model name must match a model available in your configured LLM provider.
Valid model names follow the pattern: alphanumeric characters, hyphens, underscores,
dots, colons, and forward slashes.

..  tip::

    This feature is useful for switching to a reasoning model (like ``gpt-5.2-thinking``
    or ``claude-opus-4-5``) for complex prompts while keeping a faster model as the default.
