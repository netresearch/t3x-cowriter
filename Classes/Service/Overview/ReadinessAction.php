<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Overview;

/**
 * The cowriter actions the overview reports readiness for, each named after
 * the runtime path that serves it.
 *
 * @internal
 */
enum ReadinessAction: string
{
    /** Writing in the dialog without a task: the default, or a configuration the dialog's picker offers. */
    case Dialog = 'dialog';

    /** The dialog's tasks: each through ConfigurationSelector with its own configuration, else the default. */
    case Tasks = 'tasks';

    /** Image description: nr-llm's generic default configuration (VisionService). */
    case Vision = 'vision';

    /** Translation without a pinned configuration: a specialized translator, else the generic default. */
    case Translation = 'translation';

    /** Image by prompt: image.enable, nr-llm's OpenAI image service, an upload folder. */
    case Image = 'image';

    /** Inline suggestions: inlineSuggestions.enable and its configuration. */
    case InlineSuggestions = 'inlineSuggestions';

    /** "Suggest" field control and the record overview: ConfigurationSelector's default path. */
    case FieldSuggestions = 'fieldSuggestions';

    /** Tabs or accordion: presentation.enable, bootstrap_package, ConfigurationSelector's default path. */
    case Presentation = 'presentation';
}
