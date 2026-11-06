<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Overview;

use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionConfiguration;
use Netresearch\T3Cowriter\Service\Image\ImageGenerationConfiguration;
use Netresearch\T3Cowriter\Service\InlineSuggestion\InlineSuggestionConfiguration;
use Netresearch\T3Cowriter\Service\Presentation\PresentationAccess;
use Netresearch\T3Cowriter\Service\Presentation\PresentationConfiguration;
use Netresearch\T3Cowriter\Service\Variant\VariantConfiguration;

/**
 * Where an editor finds the cowriter, each with the setting that decides
 * whether it is shown — the same settings the listeners that add them read.
 *
 * The rich text toolbar buttons are those of the cowriter RTE preset
 * (Configuration/RTE/Cowriter.yaml); whether a field uses that preset depends
 * on page TSconfig and is not measured here.
 *
 * @internal
 */
final readonly class EditorSurfaces
{
    /**
     * Toolbar buttons of the cowriter preset that no setting switches.
     */
    public const ALWAYS_ON_BUTTONS = ['cowriter', 'cowriterVision', 'cowriterTranslate', 'cowriterTemplates'];

    public function __construct(
        private ImageGenerationConfiguration $imageConfiguration,
        private InlineSuggestionConfiguration $inlineSuggestionConfiguration,
        private FieldSuggestionConfiguration $fieldSuggestionConfiguration,
        private PresentationConfiguration $presentationConfiguration,
        private VariantConfiguration $variantConfiguration,
    ) {}

    /**
     * @return list<EditorSurface>
     */
    public function all(): array
    {
        $surfaces = array_map(
            static fn (string $button): EditorSurface => new EditorSurface($button, true),
            self::ALWAYS_ON_BUTTONS,
        );

        $image  = $this->imageConfiguration->isEnabled();
        $fields = $this->fieldSuggestionConfiguration->fields() !== [];

        return [
            ...$surfaces,
            new EditorSurface('cowriterImage', $image, 'image.enable'),
            new EditorSurface('cowriterInlineSuggestions', $this->inlineSuggestionConfiguration->isEnabled(), 'inlineSuggestions.enable'),
            new EditorSurface('fieldSuggestions', $fields, 'fieldSuggestions.fields'),
            new EditorSurface('fieldOverview', $fields, 'fieldSuggestions.fields'),
            new EditorSurface('imageFieldControl', $image, 'image.enable'),
            new EditorSurface(
                'presentation',
                $this->presentationConfiguration->isEnabled() && PresentationAccess::layoutsInTca() !== [],
                'presentation.enable',
            ),
            new EditorSurface('variant', $this->variantConfiguration->isEnabled(), 'variant.enable'),
        ];
    }
}
