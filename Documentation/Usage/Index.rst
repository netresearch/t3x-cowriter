.. SPDX-License-Identifier: GPL-3.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

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

**Saved prompts**
    Below the instruction, :guilabel:`Save as prompt` stores the current
    instruction under a title. Your prompts appear in the task list under
    :guilabel:`My prompts`; choosing one fills the instruction, and
    :guilabel:`Delete prompt` next to the description removes it. With
    :guilabel:`Share with other editors`, other editors find the prompt
    under :guilabel:`Shared prompts` once an administrator has approved it
    (:ref:`configuration-saved-prompts`). Until then your list marks it
    "awaiting approval". A saved prompt runs as a custom instruction.

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

**Versions**
    Ask for one, two or three versions. With more than one, a choice above
    the result switches between them, and :guilabel:`Insert` takes the one
    shown. Several versions arrive together, not while they are written.

**Tools** (off by default)
    With :guilabel:`Let the AI look up content with tools that change
    nothing`, the model may call the nr-llm tools that run without an
    approval, for example to search the site's content, before it answers.
    A tool of the site that changes content needs an approval in nr-llm,
    and the dialog has no step to give one, so it is not offered. A tool
    from a remote MCP server is judged by what its operator declares: only
    one declared as needing approval is left out. Which tools a
    backend user may use is set in nr-llm; with none left, the task runs
    without tools. The answer is not streamed and comes as one version;
    the result line names the number of tool steps.

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

**Your AI usage**
    Below the result, :guilabel:`Your AI usage` opens a table of your own
    requests, tokens and cost today and this month, read from nr-llm. With
    a :ref:`personal budget <nrllm:administration-user-budgets>`, each
    value shows the limit next to it ("12 of 50") and a bar; without one,
    the table says that no personal limit is set and still counts your
    requests. Cost is an estimate in USD. Today counts from 00:00, this
    month from the first day of the month, both in server time. The table
    is read again after every request.

**Budget refusal**
    When a limit is used up, the request is refused. The preview names
    the limit, for example "This limit is used up: your daily cost.", and
    :guilabel:`Your AI usage` opens. A limit of an LLM configuration
    applies to all users of that configuration together. The same message
    appears for translation, alt text and field suggestions.

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

The dropdown lists the languages of the site the record belongs to, with
the titles of the site configuration. Translations into a language use the
formality configured for it, for example the formal address for German.
Records outside every site get a fixed list: German, English, French,
Spanish, Italian, Dutch, Portuguese, Polish, Japanese and Chinese. See
:ref:`configuration-translation-languages`.

DeepL translates when it is set up in nr-llm (an API key is configured
and stored) and supports the target language. Otherwise the language
model of the default
:ref:`LLM configuration <nrllm:configuration-llm>` from the nr-llm
extension translates. A request to the API may pass a ``configuration``
parameter; the translation then always uses that configuration's
language model, also when DeepL could translate the language.

When the administrator switched on the revision of DeepL translations
(:ref:`configuration-translation-polish`), a language model revises the
translation before it replaces the selection. This takes a second request
and a little longer. The success notification then reads "Translated to
… and revised by AI". When the revision cannot be applied, the DeepL
translation is inserted as it came and the notification has no hint.

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

The alt text is written in the language of the record you edit: a content
element in the German language of the site gets a German alt text. This
needs a saved record; in a new record that has not been saved yet, the
language is not known and the model chooses it. What the alt text should
contain is set by the administrator
(:ref:`configuration <configuration-alt-text>`).

..  tip::

    This is useful for accessibility compliance — generate descriptive
    alt text for images without leaving the editor.

Image generation
================

The **Generate image** button (picture with a sparkle) creates an image
from a description. It is shown only when the administrator switched the
feature on (:ref:`configuration <configuration-image-generation>`), and it
is disabled in an editor without the image plugin of rte-ckeditor-image.

1.  Place the cursor where the image should go
2.  Click the Generate image button
3.  Describe the image (up to 1000 characters) and click
    :guilabel:`Generate`
4.  Wait while the image is generated; this can take one or two minutes
5.  The image is stored in your upload folder and inserted at the cursor

The description becomes the image's alternative text. It says what you
asked for, not what the model drew: check it and correct it where needed.
You can close the dialog while the image is generated and go on editing:
the image is still inserted when it is ready, where the cursor was when you
clicked the button, as long as the record stays open. If you save and
close the record or leave the page in the meantime, nothing is inserted;
the image is then in your upload folder. If the request is cut off, for example by a
timeout, a message says so; the image may then already be in your upload
folder.

..  _usage-image-generation-file-fields:

Generating an image for a file field
------------------------------------

File fields, such as :guilabel:`Images` or :guilabel:`Media` of a content
element, have a **Generate image** button below the list of files when
the administrator switched the feature on.

1.  Click :guilabel:`Generate image` below the field
2.  Describe the image (up to 1000 characters) and click
    :guilabel:`Generate`
3.  Wait while the image is generated; this can take one or two minutes
4.  The image is stored in your upload folder and added to the field as a
    new file
5.  Save the record to keep it

You can close the dialog while the image is generated: the image is still
added to the field when it is ready, as long as the record stays open. If
you save and close the record or leave the page in the meantime, it is not
added; it is then in your upload folder. When the field is full, the button is
hidden until you remove a file. Fields that do not accept PNG files have
no button. The new file has no alternative text of its own: enter one
in the file's :guilabel:`Alternative` field where needed.

..  _usage-inline-suggestions:

Inline suggestions
==================

When the administrator switched them on
(:ref:`configuration <configuration-inline-suggestions>`), the editor
proposes how your text goes on while you write:

1.  Write at the end of a paragraph and pause
2.  After a moment a suggestion appears as grey text after the cursor
3.  Press :kbd:`Tab` to insert it, or keep typing to ignore it

:kbd:`Escape`, any other key, a click or leaving the editor removes the
suggestion. Nothing is inserted unless you press :kbd:`Tab`, and the grey
text is never saved. :kbd:`Tab` inserts the suggestion only while one is
shown; otherwise it works as usual. A :kbd:`Tab` pressed within a moment
of the suggestion appearing also works as usual and removes the
suggestion. To leave the field while a suggestion is shown, press
:kbd:`Escape`, then :kbd:`Tab`. :kbd:`Ctrl+Z` takes an inserted suggestion
back.

Suggestions appear only at the end of a paragraph or heading with enough
text (by default 30 characters), never in list items or table cells, and
never while text is selected. A screen reader announces "Suggestion
available, press Tab to accept" with the suggestion.

The **Inline suggestions** button in the toolbar switches suggestions off
for you and on again, in every rich text field of the backend; your choice
is kept in this browser. Each suggestion
counts against your AI budget, also one you do not take.

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

..  _usage-alternative-presentation:

Presenting a text as tabs or accordion
======================================

When the administrator switched it on
(:ref:`configuration <configuration-alternative-presentation>`), a text
element has the button **Present as tabs or accordion** next to its rich
text. It turns the text into a new :guilabel:`Tabs` or
:guilabel:`Accordion` element, with one tab or panel per section.

1.  Save the element: the button works with the saved text
2.  Click :guilabel:`Present as tabs or accordion` (or focus it and press
    :kbd:`Enter` or :kbd:`Space`)
3.  Below the field you see the proposed sections: a title you can change
    and the beginning of the section's text
4.  Choose :guilabel:`Tabs` or :guilabel:`Accordion`
5.  Click :guilabel:`Create element`

The new element appears directly after this one, on the same page and in
the same column. **It is hidden** until you switch: this element stays
visible and is not changed, so visitors see no difference yet. Below the
proposal you then have three buttons:

*   :guilabel:`Show the new element and hide the original` makes the new
    element visible and hides this one, in one step. In a workspace both
    changes are part of your workspace.
*   :guilabel:`Open the page in the Layout module` shows the page with the
    new element, so you can check it first.
*   :guilabel:`Open the new element` opens it for editing.

If the form has unsaved changes, the last two buttons ask first, as every
link out of the form does. :kbd:`Escape` or :guilabel:`Cancel` closes the
proposal without creating anything.

Hide the original rather than delete it. Deleting an element in the
default language also deletes its translations; the proposal warns when
the element has translations. They are not converted: create the new
element for each translation separately.

What the new element takes over from the original: the header, the
column, the language, its access restrictions (:guilabel:`Usergroup
Access Rights`, :guilabel:`Publish Date`, :guilabel:`Expiration Date`) and,
where you may edit them, the header layout, frame and spacing. The images
of a :guilabel:`Text & Images` or :guilabel:`Text & Media` element go
into the first tab or panel; the proposal says when you may not add
images there, and then they are not taken over. Anything else (for
example a link on the header, the subheader, the image position or
categories) is not taken over.

A translation that is connected to its default-language element becomes a
new element of its own in that language, not connected to the default
language (the proposal says so). On a page in connected translation mode
the page module then reports the page as mixed.

A text with headings is divided at its headings; the heading becomes the
title of its tab and text before the first heading becomes an
"Introduction". A text without headings is divided by the AI, which only
chooses where sections start and suggests their titles; your text is not
rewritten. Only this second case counts against your AI budget. The
introduction is named in the language of the element ("Einleitung" for a
German element). In a workspace the new element is created in your
workspace.

The button only appears when you may create such an element on the page.

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
