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

..  figure:: /Images/CowriterToolbarButton.png
    :alt: CKEditor toolbar with Cowriter button
    :class: with-border with-shadow

    The Cowriter button appears at the right end of the CKEditor toolbar.

3.  Optionally select the text you want to process (or leave empty to use
    the full content element)
4.  Click the Cowriter button — a dialog opens

Task-based dialog
=================

..  figure:: /Images/CowriterDialogCropped.png
    :alt: Cowriter task dialog
    :class: with-border with-shadow

    The Cowriter dialog with task selection, context scope, and
    additional instructions.

The Cowriter dialog lets you choose what to do with your content:

**Task selection**
    Choose from predefined tasks like "Improve Text", "Summarize",
    "Extend / Elaborate", "Fix Grammar & Spelling", or translations.
    Each task has a description shown below the dropdown.

**Context scope**
    Select whether the AI should work with your selected text or the
    whole content element. If you have text selected, "Selected text" is
    pre-selected.

**Additional instructions** (optional)
    Add ad-hoc rules for the current request, e.g., "Write in formal
    tone" or "Keep sentences short".

**Execute and preview**
    Click :guilabel:`Execute` to send the request to the LLM. The result
    appears in a preview area. You can then:

    *   Click :guilabel:`Insert` to replace the content in the editor
    *   Click :guilabel:`Retry` to re-execute the task
    *   Click :guilabel:`Cancel` to discard

Available tasks
===============

Tasks are configured in the nr-llm extension (``tx_nrllm_task`` table)
with ``category = 'content'``. The following default tasks are provided:

.. list-table::
   :header-rows: 1

   *  -  Task
      -  Description
   *  -  Improve Text
      -  Enhance readability and quality while preserving meaning
   *  -  Summarize
      -  Create a concise summary of the content
   *  -  Extend / Elaborate
      -  Add depth, detail, and examples
   *  -  Fix Grammar & Spelling
      -  Correct grammar and spelling with minimal changes
   *  -  Translate to English
      -  Translate content to English
   *  -  Translate to German
      -  Translate content to German

..  tip::

    You can add custom tasks by creating new records in
    :guilabel:`Admin Tools` > :guilabel:`LLM Management` with
    ``category = 'content'``. Use ``{{input}}`` in the prompt template
    as placeholder for the user's content.

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
