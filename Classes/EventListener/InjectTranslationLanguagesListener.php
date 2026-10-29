<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\EventListener;

use Netresearch\T3Cowriter\Service\SiteTranslationLanguages;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\RteCKEditor\Form\Element\Event\AfterPrepareConfigurationForEditorEvent;

/**
 * Puts the languages of the edited record's site into the CKEditor
 * configuration, as `cowriter.translationLanguages`, for the translate menu.
 *
 * FormEngine resolves the site from the record's page before the RTE renders
 * (SiteResolving data provider, `site` in the form data), so every editor
 * instance gets the languages of its own record. Without a site the key stays
 * unset and the plugin keeps its fixed list. The "After" event is used
 * because the "Before" one runs every string through the LLL and EXT: path
 * replacement, which a site language title must not go through.
 */
#[AsEventListener(identifier: 'cowriter-inject-translation-languages')]
final readonly class InjectTranslationLanguagesListener
{
    public const CONFIGURATION_KEY = 'cowriter';

    public const LANGUAGES_KEY = 'translationLanguages';

    public function __construct(
        private SiteTranslationLanguages $siteTranslationLanguages = new SiteTranslationLanguages(),
    ) {}

    public function __invoke(AfterPrepareConfigurationForEditorEvent $event): void
    {
        $site = $event->getData()['site'] ?? null;
        if (!$site instanceof SiteInterface) {
            return;
        }

        $languages = $this->siteTranslationLanguages->forSite($site);
        if ($languages === []) {
            return;
        }

        $configuration = $event->getConfiguration();
        $cowriter      = $configuration[self::CONFIGURATION_KEY] ?? [];
        if (!is_array($cowriter)) {
            $cowriter = [];
        }

        $cowriter[self::LANGUAGES_KEY]          = $languages;
        $configuration[self::CONFIGURATION_KEY] = $cowriter;
        $event->setConfiguration($configuration);
    }
}
