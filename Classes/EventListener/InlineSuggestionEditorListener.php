<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\EventListener;

use Netresearch\T3Cowriter\Service\InlineSuggestion\InlineSuggestionConfiguration;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\RteCKEditor\Form\Element\Event\AfterPrepareConfigurationForEditorEvent;

/**
 * Hands the settings of inline suggestions to the CKEditor plugin, as
 * `cowriter.inlineSuggestions`, while the setting `inlineSuggestions.enable`
 * is on. While it is off, the key stays unset, so the plugin adds no
 * listeners and sends no request, and the toolbar toggle is taken out of the
 * toolbar.
 *
 * The plugin registers the toggle in every case: a preset of its own that
 * lists `cowriterInlineSuggestions` must not make CKEditor warn about an
 * unknown toolbar item. The toolbar is either a list of items or
 * `{items: [...]}`; a nested group (`{label, items}`) is cleaned as well.
 */
#[AsEventListener(identifier: 'cowriter-inline-suggestions')]
final readonly class InlineSuggestionEditorListener
{
    public const CONFIGURATION_KEY = 'cowriter';

    public const SETTINGS_KEY = 'inlineSuggestions';

    public const TOOLBAR_ITEM = 'cowriterInlineSuggestions';

    public function __construct(
        private InlineSuggestionConfiguration $suggestionConfiguration,
        private Context $context,
    ) {}

    public function __invoke(AfterPrepareConfigurationForEditorEvent $event): void
    {
        $configuration = $event->getConfiguration();

        if (!$this->suggestionConfiguration->isEnabled()) {
            $toolbar = $configuration['toolbar'] ?? null;
            if (is_array($toolbar)) {
                $configuration['toolbar'] = self::withoutToggle($toolbar);
                $event->setConfiguration($configuration);
            }

            return;
        }

        $cowriter = $configuration[self::CONFIGURATION_KEY] ?? [];
        if (!is_array($cowriter)) {
            $cowriter = [];
        }

        /** @var int|string $userId */
        $userId = $this->context->getPropertyFromAspect('backend.user', 'id', 0);

        $cowriter[self::SETTINGS_KEY] = [
            'enabled'  => true,
            'delay'    => $this->suggestionConfiguration->delay(),
            'minChars' => $this->suggestionConfiguration->minChars(),
            // Keys the editor's own on/off choice in the browser storage, which
            // belongs to the browser profile, not to the backend user.
            'userId' => (int) $userId,
        ];
        $configuration[self::CONFIGURATION_KEY] = $cowriter;
        $event->setConfiguration($configuration);
    }

    /**
     * @param array<mixed> $items
     *
     * @return array<mixed>
     */
    private static function withoutToggle(array $items): array
    {
        $isList = array_is_list($items);
        foreach ($items as $key => $item) {
            if ($item === self::TOOLBAR_ITEM) {
                unset($items[$key]);
            } elseif (is_array($item) && is_array($item['items'] ?? null)) {
                $item['items'] = self::withoutToggle($item['items']);
                $items[$key]   = $item;
            } elseif ($key === 'items' && is_array($item)) {
                $items[$key] = self::withoutToggle($item);
            }
        }

        return $isList ? array_values($items) : $items;
    }
}
