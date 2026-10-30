<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Presentation;

use Netresearch\T3Cowriter\Service\Presentation\PresentationSwitcher;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * What the frontend of a translated page shows when an element is hidden:
 * the reason why {@see PresentationSwitcher} refuses to hide an original with
 * translations or a connected translation. Element 10 is in the default
 * language, element 11 its connected translation; the page lists the headers
 * of its content elements.
 */
#[CoversNothing]
final class TranslatedContentFrontendTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
    ];

    private function site(string $fallbackType): void
    {
        $directory = Environment::getConfigPath() . '/sites/main';
        GeneralUtility::mkdir_deep($directory);
        GeneralUtility::writeFile($directory . '/config.yaml', Yaml::dump([
            'rootPageId' => 1,
            'base'       => 'http://localhost/',
            'languages'  => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
                [
                    'languageId'   => 1,
                    'title'        => 'Deutsch',
                    'locale'       => 'de_DE.UTF-8',
                    'base'         => '/de/',
                    'fallbackType' => $fallbackType,
                    'fallbacks'    => '0',
                ],
            ],
        ], 99, 2));

        $this->importCSVDataSet(__DIR__ . '/Fixtures/frontend.csv');
        $this->setUpFrontendRootPage(1);
        $this->addTypoScriptToTemplateRecord(1, implode("\n", [
            'config.disableAllHeaderCode = 1',
            'page = PAGE',
            'page.10 = CONTENT',
            'page.10.table = tt_content',
            'page.10.select.orderBy = sorting',
            'page.10.renderObj = TEXT',
            'page.10.renderObj.field = header',
            'page.10.renderObj.wrap = [|]',
        ]));
    }

    private function germanPage(): string
    {
        return (string) $this->executeFrontendSubRequest(new InternalRequest('http://localhost/de/'))->getBody();
    }

    /**
     * @param array<string, int> $values
     */
    private function update(int $uid, array $values): void
    {
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update('tt_content', $values, ['uid' => $uid]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function overlayModes(): iterable
    {
        yield 'fallback' => ['fallback'];
        yield 'strict' => ['strict'];
    }

    #[Test]
    #[DataProvider('overlayModes')]
    public function hidingTheDefaultLanguageElementHidesItsTranslation(string $fallbackType): void
    {
        $this->site($fallbackType);
        self::assertStringContainsString('[Translation]', $this->germanPage());

        $this->update(10, ['hidden' => 1]);

        self::assertStringNotContainsString('[Translation]', $this->germanPage());
    }

    /**
     * With fallback the default-language element comes back: TYPO3 14.3
     * shows it next to the free element, 13.4 shows no free element at all
     * with fallback (only strict includes them there).
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function afterHidingAConnectedTranslation(): iterable
    {
        yield 'fallback' => ['fallback', '[Original]', '[Original][New]'];
        yield 'strict' => ['strict', '[New]', '[New]'];
    }

    #[Test]
    #[DataProvider('afterHidingAConnectedTranslation')]
    public function hidingAConnectedTranslationNextToAFreeElement(string $fallbackType, string $expectedOn13, string $expectedOn14): void
    {
        $this->site($fallbackType);
        // What a switch would leave: the connected translation hidden, a
        // free element of that language visible after it.
        $this->getConnectionPool()->getConnectionForTable('tt_content')->insert('tt_content', [
            'pid' => 1, 'header' => 'New', 'CType' => 'text', 'colPos' => 0, 'sys_language_uid' => 1, 'l18n_parent' => 0, 'sorting' => 384,
        ]);
        $this->update(11, ['hidden' => 1]);

        $expected = (new Typo3Version())->getMajorVersion() >= 14 ? $expectedOn14 : $expectedOn13;
        self::assertSame($expected, trim($this->germanPage()));
    }
}
