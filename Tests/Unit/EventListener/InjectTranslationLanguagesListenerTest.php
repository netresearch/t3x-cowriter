<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\EventListener;

use Netresearch\T3Cowriter\EventListener\InjectTranslationLanguagesListener;
use Netresearch\T3Cowriter\Service\SiteTranslationLanguages;
use Netresearch\T3Cowriter\Tests\Unit\Service\SiteTranslationLanguagesTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\RteCKEditor\Form\Element\Event\AfterPrepareConfigurationForEditorEvent;

#[CoversClass(InjectTranslationLanguagesListener::class)]
#[UsesClass(SiteTranslationLanguages::class)]
final class InjectTranslationLanguagesListenerTest extends TestCase
{
    private const BASE_CONFIGURATION = ['toolbar' => ['items' => ['cowriterTranslate']], 'language' => ['ui' => 'de']];

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $configuration
     */
    private function dispatch(array $data, array $configuration = self::BASE_CONFIGURATION): AfterPrepareConfigurationForEditorEvent
    {
        $event = new AfterPrepareConfigurationForEditorEvent($configuration, $data);
        (new InjectTranslationLanguagesListener())($event);

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    private function siteData(): array
    {
        return [
            'tableName' => 'tt_content',
            'site'      => SiteTranslationLanguagesTest::site([
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
                ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/', 'cowriterFormality' => 'formal'],
            ]),
        ];
    }

    #[Test]
    public function theLanguagesOfTheRecordsSiteGoIntoTheEditorConfiguration(): void
    {
        $configuration = $this->dispatch($this->siteData())->getConfiguration();

        self::assertSame(
            self::BASE_CONFIGURATION + [
                'cowriter' => [
                    'translationLanguages' => [
                        ['code' => 'en-US', 'label' => 'English', 'formality' => 'default'],
                        ['code' => 'de', 'label' => 'Deutsch', 'formality' => 'formal'],
                    ],
                ],
            ],
            $configuration,
        );
    }

    #[Test]
    public function otherCowriterSettingsInThePresetAreKept(): void
    {
        $configuration = $this->dispatch(
            $this->siteData(),
            self::BASE_CONFIGURATION + ['cowriter' => ['somethingElse' => 1]],
        )->getConfiguration();

        self::assertSame(1, $configuration['cowriter']['somethingElse'] ?? null);
        self::assertCount(2, $configuration['cowriter']['translationLanguages'] ?? []);
    }

    #[Test]
    public function aCowriterKeyThatIsNoArrayIsReplaced(): void
    {
        $configuration = $this->dispatch(
            $this->siteData(),
            self::BASE_CONFIGURATION + ['cowriter' => 'on'],
        )->getConfiguration();

        self::assertSame(['translationLanguages'], array_keys($configuration['cowriter']));
    }

    #[Test]
    public function aRecordOutsideEverySiteLeavesTheConfigurationUnchanged(): void
    {
        self::assertSame(
            self::BASE_CONFIGURATION,
            $this->dispatch(['tableName' => 'tt_content', 'site' => new NullSite()])->getConfiguration(),
        );
    }

    #[Test]
    public function formDataWithoutASiteLeavesTheConfigurationUnchanged(): void
    {
        self::assertSame(self::BASE_CONFIGURATION, $this->dispatch(['tableName' => 'tt_content'])->getConfiguration());
        self::assertSame(self::BASE_CONFIGURATION, $this->dispatch(['site' => 'main'])->getConfiguration());
    }

    /**
     * The Visual Editor passes the raw record row (Record::getRawRecord()->toArray())
     * as data: no `site`, but the record's `pid`.
     */
    #[Test]
    public function theVisualEditorsRawRecordGetsTheSiteOfItsPage(): void
    {
        $sites = $this->createMock(SiteFinder::class);
        $sites->expects(self::once())->method('getSiteByPageId')->with(42)->willReturn($this->siteData()['site']);

        $event = new AfterPrepareConfigurationForEditorEvent(
            self::BASE_CONFIGURATION,
            ['uid' => 7, 'pid' => 42, 'CType' => 'text', 'bodytext' => '<p>Hello</p>'],
        );
        (new InjectTranslationLanguagesListener(new SiteTranslationLanguages(), $sites))($event);

        self::assertSame(
            [
                ['code' => 'en-US', 'label' => 'English', 'formality' => 'default'],
                ['code' => 'de', 'label' => 'Deutsch', 'formality' => 'formal'],
            ],
            $event->getConfiguration()['cowriter']['translationLanguages'] ?? null,
        );
    }

    #[Test]
    public function aRawRecordOutsideEverySiteLeavesTheConfigurationUnchanged(): void
    {
        $sites = $this->createStub(SiteFinder::class);
        $sites->method('getSiteByPageId')->willThrowException(new SiteNotFoundException('none', 1));

        $event = new AfterPrepareConfigurationForEditorEvent(self::BASE_CONFIGURATION, ['uid' => 7, 'pid' => 42]);
        (new InjectTranslationLanguagesListener(new SiteTranslationLanguages(), $sites))($event);

        self::assertSame(self::BASE_CONFIGURATION, $event->getConfiguration());
    }

    #[Test]
    public function aRawRecordWithoutAPageIsNotLookedUp(): void
    {
        $sites = $this->createMock(SiteFinder::class);
        $sites->expects(self::never())->method('getSiteByPageId');
        $listener = new InjectTranslationLanguagesListener(new SiteTranslationLanguages(), $sites);

        foreach ([['uid' => 7, 'pid' => 0], ['uid' => 7, 'pid' => -1], ['uid' => 7], ['uid' => 7, 'pid' => 'x']] as $data) {
            $event = new AfterPrepareConfigurationForEditorEvent(self::BASE_CONFIGURATION, $data);
            $listener($event);
            self::assertSame(self::BASE_CONFIGURATION, $event->getConfiguration());
        }
    }

    #[Test]
    public function formDataKeepsTheSiteFormEngineResolved(): void
    {
        $sites = $this->createMock(SiteFinder::class);
        $sites->expects(self::never())->method('getSiteByPageId');

        $event = new AfterPrepareConfigurationForEditorEvent(
            self::BASE_CONFIGURATION,
            ['pid' => 42, 'site' => 'main'],
        );
        (new InjectTranslationLanguagesListener(new SiteTranslationLanguages(), $sites))($event);

        self::assertSame(self::BASE_CONFIGURATION, $event->getConfiguration());
    }
}
