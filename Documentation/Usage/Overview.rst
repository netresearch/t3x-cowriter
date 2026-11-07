.. SPDX-License-Identifier: GPL-3.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

..  _usage-overview:

=================
Cowriter overview
=================

The backend module :guilabel:`AI` > :guilabel:`Cowriter` (identifier
``cowriter_status``, path ``/module/cowriter/status``) shows what the
cowriter does, whether each of its actions can run for you, how much it was
used and where it is set up. Up to version 3.24 the same module was the
administrator-only "Cowriter Status" page under :guilabel:`Admin Tools`;
bookmarks and links to it keep working.

Who sees the module
===================

Administrators see it without further setup. An editor sees it when one of
their backend groups grants the module :guilabel:`Cowriter` in the field
:guilabel:`Modules` (``groupMods``) of the group record.

An editor sees their own readiness, including the sites with their own
configuration, their own usage and their own budget. An administrator also
sees the usage of the whole installation, the extension settings and the
links that fix a problem. The page never shows another person's name, prompt or
text. The usage per person stays in :guilabel:`AI` > :guilabel:`Operation` >
:guilabel:`Analytics`, which only administrators open.

What the cowriter is
====================

A short description and a table of the places editors find the cowriter:
the buttons of the cowriter preset in the rich text toolbar, the
"Suggest" button next to text fields, the button in the record editor that
suggests values for all fields, the image file control, the "Present as
tabs or accordion" button and the "Create an audience variant" button.
Each place says whether it is on and which
setting of the extension configuration switches it.

The toolbar buttons appear in rich text fields whose RTE configuration lists
them in its toolbar: the cowriter preset (for example
``RTE.default.preset = cowriter`` in the page TSconfig), or a configuration
of your own that imports the Cowriter module and names its toolbar items.
Which configuration a field uses depends on the page and is not checked on
this page.

Readiness
=========

One row per action, checked the way the action runs for the person viewing
the page. Nothing is sent to a provider, so whether a provider answers is not
checked; the provider list has a connection test for that.

Each row has one of five states:

Ready
    The action finds everything it needs.

Partly ready
    The action runs on some of its paths and fails on others, and the reason
    names which. An administrator gets a link to the module that fixes it; an
    editor is asked to contact an administrator.

Not ready
    Something it needs is missing. The reason names it. An administrator
    gets a link to the module that fixes it; an editor is asked to contact
    an administrator.

Off
    The feature is switched off in the extension configuration.

Not checked
    The state cannot be known without making a request: the configuration
    chooses its model for each request, or the provider uses an adapter the
    check does not know. Such a row is never shown as ready.

What each action needs:

Write and rewrite in the dialog
    The default configuration of the AI section, which the person may use
    (it is not limited to backend groups they are not in), with an active
    model whose provider is active and has an API key when its adapter needs
    one. When the person cannot use the default, the dialog's configuration
    picker may still offer one that runs: the row is then partly ready and
    names it, because writing works only after picking it.

Field suggestions
    The same default configuration. Field suggestions have no configuration
    picker, so without a usable default they are not ready.

Tasks
    Every active task the dialog offers, checked the way a task runs: on its
    own configuration, or on the default when it has none. The row counts
    the tasks that cannot run and names them when there are at most three.
    It is partly ready when some tasks run and others do not, and off when
    there is no active task. A configuration picked in the dialog replaces the
    task's own one, so when the picker offers the person a configuration that
    runs, failing tasks still run once it is picked: the row is then partly
    ready and names that configuration.

Describe an image and translate
    nr-llm's generic call, which runs on the default configuration only when
    that has a model and is not limited to backend groups. Image descriptions
    also need a model marked as able to read images. Translation uses a
    specialized translator such as DeepL first when one is available; the
    language pairs it does not support go to the generic call. Without a
    default the generic call can run on, translation is partly ready.

Generate an image
    The setting :ref:`image.enable <configuration-image-generation>`, the
    OpenAI API key in nr-llm's extension configuration and a default upload
    folder the person may write to.

Inline suggestions
    The setting :ref:`inlineSuggestions.enable
    <configuration-inline-suggestions>` and the configuration it names, or the
    default configuration.

Tabs or accordion
    The setting :ref:`presentation.enable
    <configuration-alternative-presentation>`, the tab and accordion elements
    of bootstrap_package and the default configuration.

Audience variant
    The setting :ref:`variant.enable <configuration-audience-variant>`, an
    active nr-llm prompt snippet tagged ``audience`` and the default
    configuration.

A second table lists every site that names its own configuration in its site
settings, checked with the access of the person viewing the page. An editor
sees only the sites their web mounts reach: a mount on the root page, above
it or anywhere below it. A task
without its own configuration, run on a record of such a site, uses that
configuration instead of the default.

Configuration
=============

Administrators see every setting of the extension configuration with its
value and whether it is the default or was changed. The settings are changed
in :guilabel:`Settings` > :guilabel:`Extension Configuration` >
``t3_cowriter``, which only system maintainers open.

Usage
=====

The period is 7, 30 or 90 days, chosen with the form above the figures. It
starts at 00:00 of its first day and ends now.

Estimated cost, requests and tokens
    From nr-llm's daily usage record of the cowriter (source extension
    ``t3_cowriter``).

Actions run and failed, per action
    From nr-llm's telemetry. A task is listed with its name; an action name
    the cowriter does not know is shown as it is stored. The telemetry is
    deleted after nr-llm's retention period (``privacy.retention.telemetry``,
    by default 30 days). When the period reaches further back, the page says
    from which day the counts per action are complete. When the telemetry is
    switched off, the page says so.

Your AI budget
    Your own usage of all AI features today and this month against your
    personal limits, from nr-llm's budget.

Shared prompts
    For administrators: how many shared prompts wait for approval, with a
    link to the List module.

Set up and follow up
====================

Links to the modules of the AI section (Configurations, Tasks, Models,
Providers, Analytics, AI tasks), to the extension configuration and to this
documentation. A link is shown only when the person may open the module.
