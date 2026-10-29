<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\EventListener;

use Netresearch\T3Cowriter\Service\Image\ImageGenerationConfiguration;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\RteCKEditor\Form\Element\Event\AfterPrepareConfigurationForEditorEvent;

/**
 * Takes the "Generate image" button out of the CKEditor toolbar while the
 * setting `image.enable` is off, so editors do not see a button the route
 * refuses.
 *
 * The plugin registers the button in every case: a preset of its own that
 * lists `cowriterImage` must not make CKEditor warn about an unknown toolbar
 * item. The toolbar is either a list of items or `{items: [...]}`; a nested
 * group (`{label, items}`) is cleaned as well.
 */
#[AsEventListener(identifier: 'cowriter-remove-disabled-image-button')]
final readonly class RemoveDisabledImageButtonListener
{
    public const TOOLBAR_ITEM = 'cowriterImage';

    public function __construct(
        private ImageGenerationConfiguration $imageConfiguration,
    ) {}

    public function __invoke(AfterPrepareConfigurationForEditorEvent $event): void
    {
        if ($this->imageConfiguration->isEnabled()) {
            return;
        }

        $configuration = $event->getConfiguration();
        $toolbar       = $configuration['toolbar'] ?? null;
        if (!is_array($toolbar)) {
            return;
        }

        $configuration['toolbar'] = self::withoutButton($toolbar);
        $event->setConfiguration($configuration);
    }

    /**
     * @param array<mixed> $items
     *
     * @return array<mixed>
     */
    private static function withoutButton(array $items): array
    {
        $isList = array_is_list($items);
        foreach ($items as $key => $item) {
            if ($item === self::TOOLBAR_ITEM) {
                unset($items[$key]);
            } elseif (is_array($item) && is_array($item['items'] ?? null)) {
                $item['items'] = self::withoutButton($item['items']);
                $items[$key]   = $item;
            } elseif ($key === 'items' && is_array($item)) {
                $items[$key] = self::withoutButton($item);
            }
        }

        return $isList ? array_values($items) : $items;
    }
}
