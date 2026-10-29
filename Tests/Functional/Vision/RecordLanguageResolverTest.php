<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Vision;

use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\Vision\RecordLanguageResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The language of a record comes from its language field and the site of its
 * page, and only for a user who may read the record.
 */
#[CoversClass(RecordLanguageResolver::class)]
final class RecordLanguageResolverTest extends FunctionalTestCase
{
    private const ENGLISH = 'English (United States)';

    private const GERMAN = 'German (Germany)';

    protected array $coreExtensionsToLoad = ['rte_ckeditor', 'workspaces'];

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
                ['languageId' => 0, 'title' => 'Main', 'locale' => 'en_US.UTF-8', 'base' => '/'],
                ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/'],
            ],
        ], 99, 2));

        $this->importCSVDataSet(__DIR__ . '/Fixtures/record_languages.csv');
    }

    #[Test]
    public function aContentElementInTheDefaultLanguageGetsTheSitesDefaultLanguage(): void
    {
        $this->setUpBackendUser(1);

        self::assertSame(self::ENGLISH, $this->resolver()->languageName('tt_content', 10));
    }

    #[Test]
    public function aContentElementInTheSecondLanguageGetsThatLanguage(): void
    {
        $this->setUpBackendUser(1);

        self::assertSame(self::GERMAN, $this->resolver()->languageName('tt_content', 11));
    }

    #[Test]
    public function aPageTranslationGetsItsLanguageFromTheSiteOfItsOriginal(): void
    {
        // The translation row denies everything; the permissions of its original apply.
        $this->setUpBackendUser(4);

        self::assertSame(self::GERMAN, $this->resolver()->languageName('pages', 3));
        self::assertSame(self::ENGLISH, $this->resolver()->languageName('pages', 2));
    }

    #[Test]
    public function anEditorGetsTheLanguageOfAnElementTheyMayRead(): void
    {
        $this->setUpBackendUser(2);

        self::assertSame(self::ENGLISH, $this->resolver()->languageName('tt_content', 10));
    }

    #[Test]
    public function anEditorGetsNoLanguageForAnElementInALanguageTheyMayNotRead(): void
    {
        $this->setUpBackendUser(2);

        self::assertNull($this->resolver()->languageName('tt_content', 11));
    }

    #[Test]
    public function anEditorGetsNoLanguageForAnElementOnAPageTheyMayNotSee(): void
    {
        $this->setUpBackendUser(2);

        self::assertNull($this->resolver()->languageName('tt_content', 16));
    }

    #[Test]
    public function aUserWithoutReadAccessToTheTableGetsNoLanguage(): void
    {
        $this->setUpBackendUser(3);

        self::assertNull($this->resolver()->languageName('tt_content', 10));
    }

    #[Test]
    public function noLanguageForARecordThatCannotBeResolved(): void
    {
        $this->setUpBackendUser(1);
        $resolver = $this->resolver();

        self::assertNull($resolver->languageName('tt_content', 999), 'record does not exist');
        self::assertNull($resolver->languageName('tt_content', 12), 'root level');
        self::assertNull($resolver->languageName('tt_content', 13), 'all languages');
        self::assertNull($resolver->languageName('tt_content', 14), 'language the site does not have');
        self::assertNull($resolver->languageName('tt_content', 15), 'page outside every site');
    }

    #[Test]
    public function aRecordNewInTheUsersWorkspaceGetsItsLanguage(): void
    {
        // Element 17 and its page 6 exist only in workspace 1.
        $backendUser            = $this->setUpBackendUser(5);
        $backendUser->workspace = 1;

        self::assertSame(self::GERMAN, $this->resolver()->languageName('tt_content', 17));
    }

    #[Test]
    public function aRecordOfAnotherWorkspaceGetsNoLanguageInLive(): void
    {
        $backendUser            = $this->setUpBackendUser(4);
        $backendUser->workspace = 0;

        self::assertNull($this->resolver()->languageName('tt_content', 17));
    }

    #[Test]
    public function withoutABackendUserThereIsNoLanguage(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertNull($this->resolver()->languageName('tt_content', 10));
    }

    #[Test]
    public function aTableWithoutLanguageFieldOrTcaGivesNoLanguage(): void
    {
        $this->setUpBackendUser(1);
        $resolver = $this->resolver();

        self::assertNull($resolver->languageName('be_users', 1));
        self::assertNull($resolver->languageName('tx_not_a_table', 1));
    }

    private function resolver(): RecordLanguageResolver
    {
        return new RecordLanguageResolver(
            new RecordFinder($this->get(ConnectionPool::class)),
            $this->get(SiteFinder::class),
        );
    }
}
