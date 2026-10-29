<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\InlineSuggestion;

use Netresearch\T3Cowriter\Service\CallerSource;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Extension settings `inlineSuggestions.*` (ext_conf_template.txt): whether
 * the rich text editor proposes a continuation while an editor types, how long
 * it waits after the last key, how much text a block needs first, and which
 * nr-llm configuration answers.
 *
 * All of them come from the extension configuration only; a request never
 * sets one. Numbers outside their range are clamped, so a typo in the
 * settings cannot make the editor ask the model on every key.
 *
 * @internal
 */
final readonly class InlineSuggestionConfiguration
{
    public const DEFAULT_DELAY = 1200;

    public const MIN_DELAY = 800;

    public const MAX_DELAY = 3000;

    public const DEFAULT_MIN_CHARS = 30;

    public const MIN_MIN_CHARS = 10;

    public const MAX_MIN_CHARS = 500;

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * Whether inline suggestions are switched on. Off by default, and off when
     * the setting cannot be read. Only the string '1' counts as on.
     */
    public function isEnabled(): bool
    {
        return $this->setting('inlineSuggestions/enable') === '1';
    }

    /**
     * Milliseconds the editor waits after the last key before it asks.
     */
    public function delay(): int
    {
        return $this->number('inlineSuggestions/delay', self::DEFAULT_DELAY, self::MIN_DELAY, self::MAX_DELAY);
    }

    /**
     * Characters the current text block needs before a suggestion is asked for.
     */
    public function minChars(): int
    {
        return $this->number('inlineSuggestions/minChars', self::DEFAULT_MIN_CHARS, self::MIN_MIN_CHARS, self::MAX_MIN_CHARS);
    }

    /**
     * The nr-llm configuration that answers; null when the setting is empty,
     * so the default configuration answers.
     */
    public function configuration(): ?string
    {
        $identifier = trim($this->setting('inlineSuggestions/configuration'));

        return $identifier !== '' ? $identifier : null;
    }

    private function number(string $path, int $default, int $min, int $max): int
    {
        $value = trim($this->setting($path));
        if (preg_match('/^\d+$/', $value) !== 1) {
            return $default;
        }

        return max($min, min($max, (int) $value));
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
