<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Site;

use Netresearch\T3Cowriter\EventListener\InjectTranslationLanguagesListener;
use Netresearch\T3Cowriter\Service\SiteTranslationLanguages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Backend\Configuration\SiteTcaConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\RteCKEditor\Form\Element\Event\AfterPrepareConfigurationForEditorEvent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A site configuration file as TYPO3 reads it: the custom formality key on a
 * language reaches the RTE configuration through the registered listener, and
 * the Sites module knows the key as a field, so saving there keeps it.
 */
#[CoversClass(InjectTranslationLanguagesListener::class)]
#[CoversClass(SiteTranslationLanguages::class)]
final class SiteTranslationLanguagesTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $directory = Environment::getConfigPath() . '/sites/main';
        GeneralUtility::mkdir_deep($directory);
        GeneralUtility::writeFile($directory . '/config.yaml', Yaml::dump([
            'rootPageId' => 1,
            'base'       => 'https://example.org/',
            'languages'  => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
                ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/', 'cowriterFormality' => 'formal'],
            ],
        ], 99, 2));
    }

    #[Test]
    public function theSiteConfigurationReachesTheEditorConfiguration(): void
    {
        $site  = $this->get(SiteFinder::class)->getSiteByIdentifier('main');
        $event = new AfterPrepareConfigurationForEditorEvent(['toolbar' => ['items' => ['cowriterTranslate']]], ['site' => $site]);

        $this->get(EventDispatcherInterface::class)->dispatch($event);

        self::assertSame(
            [
                ['code' => 'en-US', 'label' => 'English', 'formality' => 'default'],
                ['code' => 'de', 'label' => 'Deutsch', 'formality' => 'formal'],
            ],
            $event->getConfiguration()['cowriter']['translationLanguages'] ?? null,
        );
    }

    #[Test]
    public function theSitesModuleShowsTheFormalityAsAField(): void
    {
        $siteLanguage = $this->get(SiteTcaConfiguration::class)->getTca()['site_language'] ?? [];
        $column       = $siteLanguage['columns'][SiteTranslationLanguages::FORMALITY_KEY] ?? null;

        self::assertIsArray($column);
        self::assertSame(
            SiteTranslationLanguages::FORMALITIES,
            array_column($column['config']['items'] ?? [], 'value'),
        );
        self::assertStringContainsString(
            SiteTranslationLanguages::FORMALITY_KEY,
            $siteLanguage['types']['1']['showitem'] ?? '',
        );
    }
}
