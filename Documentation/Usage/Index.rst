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
    Each task has a description shown below the dropdown. You can also
    select "Custom instruction" to write a freeform prompt.

**LLM configuration**
    Choose which :ref:`LLM configuration <nrllm:configuration-llm>` runs
    the task. The first option, "Task setting", keeps the configuration
    the task names, or the default configuration when the task names
    none. The list shows only the configurations your backend groups may
    use; a configuration restricted to other groups is neither listed
    nor accepted. The picker appears once the list has loaded and stays
    hidden when there is nothing to choose.

**Audience, tone of voice and length** (optional)
    Pick an audience and a tone of voice from the prompt snippets the
    administrator maintains in nr-llm, and a length step from
    :guilabel:`Much shorter` to :guilabel:`Much longer`. They apply to this
    request only. When the page TSconfig sets a target length for the
    content element's type, the length step scales that target, and the
    result shows the word count next to it
    (:ref:`configuration-style`).

**Context scope**
    Control how much context the AI receives:

    *   **Selection** — only the highlighted text (pre-selected when
        you have a selection)
    *   **Full content** — the entire editor content
    *   **Content element** — the full tt_content record
    *   **Page content** — all content on the current page
    *   **Parent page** / **Grandparent page** — include ancestor
        page content for broader context

    Options that require a record context (Content element and above)
    are disabled when the record cannot be detected.

**Reference pages** (optional)
    Add pages whose content should be included as reference material.
    Search by title or UID, and specify a relation label (e.g.,
    "style guide", "reference material").

**Additional instructions** (optional)
    Add ad-hoc rules for the current request, e.g., "Write in formal
    tone" or "Keep sentences short".

**Execute and preview**
    Click :guilabel:`Execute` to send the request to the LLM. The answer
    appears in the preview while the model writes it; when it is
    complete, the preview shows the final text with the model name. You
    can then:

    *   Click :guilabel:`Insert` to replace the content in the editor
    *   Click :guilabel:`Reset` to clear the result and adjust settings
    *   Click :guilabel:`Execute` again to refine the result (the
        previous output becomes the new input)
    *   Click :guilabel:`Cancel` to discard

Available tasks
===============

:ref:`Tasks <nrllm:configuration-tasks>` are configured in the
nr-llm extension (``tx_nrllm_task`` table) with
``category = 'content'``. The following default tasks are provided:

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

Inline translation
==================

The **Translate** dropdown in the CKEditor toolbar lets you translate
selected text without opening the full dialog.

1.  Select the text you want to translate
2.  Click the Translate button (globe icon) in the toolbar
3.  Choose the target language from the dropdown
4.  A notification confirms the translation is in progress
5.  The selected text is replaced with the translation

Supported languages:

*   German, English, French, Spanish, Italian
*   Dutch, Portuguese, Polish, Japanese, Chinese

The translation uses the default
:ref:`LLM configuration <nrllm:configuration-llm>` from the
nr-llm extension. Administrators can optionally pass a
``configuration`` parameter via the API to route translations
through a specific LLM provider (e.g. DeepL or a dedicated
translation model).

..  note::

    Inline translation requires text to be selected. A warning
    notification appears if no text is selected. For translating
    entire content elements, use the task-based dialog instead.

..  tip::

    Translation preserves HTML formatting. If you select bold or
    linked text, the formatting is maintained in the translated
    output.

Alt text generation
===================

The **Vision** button (image icon) generates alt text for images using
LLM vision analysis.

1.  Click on an image in the editor to select it
2.  Click the Vision button in the toolbar
3.  A notification confirms the analysis is in progress
4.  The alt text is set on the image automatically

..  note::

    You must select an image before clicking the Vision button.
    A warning notification appears if no image is selected.

..  tip::

    This is useful for accessibility compliance — generate descriptive
    alt text for images without leaving the editor.

Tasks shortcut
==============

The **Tasks** dropdown (document icon) lets you open the Cowriter
dialog with a specific task pre-selected, skipping the task selection
step.

1.  Click the Tasks button (document icon) in the toolbar
2.  Select a task from the dropdown (tasks are loaded from nr-llm)
3.  The Cowriter dialog opens with the chosen task pre-selected
4.  Review, optionally adjust instructions, and execute

Tasks are loaded once when you first open the dropdown and cached for
the duration of the editing session. If you create new tasks in the
LLM module, reload the page to see them in the dropdown.

..  note::

    If no tasks with ``category = 'content'`` are configured, a
    notification guides you to the LLM module to create them.

..  _usage-field-suggestions:

Field suggestions
=================

Outside the rich text editor, the **Suggest values with AI** button
(light bulb icon) next to a form field asks the LLM for alternative
values. By default it sits next to these page properties:

*   :guilabel:`SEO` > :guilabel:`Title for search engines`
    (``pages.seo_title``, only with the system extension ``seo``)
*   :guilabel:`SEO` > :guilabel:`Description` (``pages.description``)
*   :guilabel:`SEO` > :guilabel:`Keywords` (``pages.keywords``)
*   :guilabel:`General` > :guilabel:`URL Segment` (``pages.slug``)

1.  Click the light bulb button next to the field (or focus it and press
    :kbd:`Enter` or :kbd:`Space`)
2.  The suggestions appear in a list below the field; by default there
    are three
3.  Click a suggestion (or move to it with the arrow keys and press
    :kbd:`Enter`) to insert it into the field; the cursor moves into the
    field
4.  Save the record to keep the value

..  figure:: /Images/Usage/FieldSuggestionsSeoTitle.png
    :alt: Page properties, SEO tab: three AI suggestions listed below the
          Title for search engines field, with a close button and a status
          line under the list
    :class: with-border with-shadow

    The suggestion list below :guilabel:`Title for search engines`: three
    suggestions to pick from and the status line that announces them.

Nothing is saved automatically: a picked suggestion only changes the form,
exactly as if you had typed it, and :kbd:`Escape` closes the list without
changing anything.

The suggestions are generated from the page title, the current value of
the field (including text you typed but have not saved yet) and the text
of the content elements on the page. In a workspace, the page and its
content elements are read as they look in your workspace; drafts of other
workspaces are never used. They follow the length guidance of
the field: at most 60 characters for the SEO title and 160 characters for
the description; keywords are a comma-separated list. For the URL segment
the AI only proposes the words of the last path segment. TYPO3 adds the
parent page path and turns the words into a valid URL segment. It checks
that the URL segment is unique the same way as for a segment you type in.

The button only appears for fields you may edit, and the server checks
your permissions again for every request: you need write access to the
table, access to the field if it is an exclude field, and edit rights on
the page (for a new page: the right to create pages below the parent
page).

Categories are not filled by the button. For tags, use the
:guilabel:`Keywords` suggestions and copy the terms you want to use.

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
