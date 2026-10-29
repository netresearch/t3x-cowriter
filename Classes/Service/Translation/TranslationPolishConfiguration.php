<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Translation;

use Netresearch\T3Cowriter\Service\CallerSource;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Extension settings `translation.polish` and `translation.polishInstruction`
 * (ext_conf_template.txt): whether a language model revises a translation a
 * specialized translator such as DeepL produced (nr-llm ADR-233), and the
 * additional instruction it gets.
 *
 * Both come from the extension configuration only. The instruction goes into
 * the model's system message, so a request never sets either.
 *
 * @internal
 */
final readonly class TranslationPolishConfiguration
{
    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * Whether the revision is switched on. Off by default, and off when the
     * setting cannot be read.
     */
    public function isEnabled(): bool
    {
        return $this->setting('translation/polish') === '1';
    }

    /**
     * The additional instruction for the revising model; null when the
     * setting is empty or cannot be read, so nr-llm's own instruction applies
     * alone.
     */
    public function instruction(): ?string
    {
        $instruction = trim($this->setting('translation/polishInstruction'));

        return $instruction !== '' ? $instruction : null;
    }

    private function setting(string $path): string
    {
        try {
            $value = $this->extensionConfiguration->get(CallerSource::EXTENSION, $path);
        } catch (Throwable) {
            return '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
