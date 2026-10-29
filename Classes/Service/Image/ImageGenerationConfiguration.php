<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Image;

use Netresearch\T3Cowriter\Service\CallerSource;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Extension settings `image.enable` and `image.model` (ext_conf_template.txt):
 * whether editors may generate an image from a prompt in the rich text editor,
 * and the OpenAI image model nr-llm's image service uses for it.
 *
 * Both come from the extension configuration only; a request never sets
 * either.
 *
 * @internal
 */
final readonly class ImageGenerationConfiguration
{
    /**
     * The model when neither the setting nor nr-llm's model registry names
     * one. gpt-image-2 is an OpenAI image model that is not scheduled for
     * shutdown and that nr-llm 0.41 prices, so a cost limit of the nr-llm
     * budget applies to it.
     */
    public const FALLBACK_MODEL = 'gpt-image-2';

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * Whether image generation is switched on. Off by default, and off when
     * the setting cannot be read. Only the string '1' counts as on.
     */
    public function isEnabled(): bool
    {
        return $this->setting('image/enable') === '1';
    }

    /**
     * The image model the administrator set; null when the setting is empty,
     * so nr-llm's model registry decides.
     */
    public function model(): ?string
    {
        $model = trim($this->setting('image/model'));

        return $model !== '' ? $model : null;
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
