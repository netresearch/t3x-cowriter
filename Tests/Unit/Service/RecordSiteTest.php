<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service;

use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\RecordSite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

#[CoversClass(RecordSite::class)]
final class RecordSiteTest extends TestCase
{
    #[Test]
    public function aPageBelongsToItsOwnSite(): void
    {
        self::assertSame('shop', $this->subject()->identifierFor(['table' => 'pages', 'uid' => 10, 'field' => 'title']));
    }

    #[Test]
    public function aContentElementBelongsToTheSiteOfItsPage(): void
    {
        self::assertSame('shop', $this->subject()->identifierFor(['table' => 'tt_content', 'uid' => 5, 'field' => 'bodytext']));
    }

    #[Test]
    public function aTranslatedPageBelongsToTheSiteOfItsDefaultLanguagePage(): void
    {
        self::assertSame('shop', $this->subject()->identifierFor(['table' => 'pages', 'uid' => 11, 'field' => 'title']));
    }

    #[Test]
    public function noRecordAnUnreadableRecordAndAPageOutsideEverySiteHaveNoSite(): void
    {
        $subject = $this->subject();

        self::assertNull($subject->identifierFor(null));
        self::assertNull($subject->identifierFor(['table' => 'tt_content', 'uid' => 404, 'field' => 'bodytext']));
        self::assertNull($subject->identifierFor(['table' => 'tt_content', 'uid' => 6, 'field' => 'bodytext']));
        self::assertNull($subject->identifierFor(['table' => 'tt_content', 'uid' => 7, 'field' => 'bodytext']));
    }

    private function subject(): RecordSite
    {
        $rows = [
            'pages:10' => ['uid' => 10, 'pid' => 1],
            // The German translation of the site root: a root itself.
            'pages:11'     => ['uid' => 11, 'pid' => 0, 'l10n_parent' => 10],
            'tt_content:5' => ['uid' => 5, 'pid' => 10],
            'tt_content:6' => ['uid' => 6, 'pid' => 99],
            'tt_content:7' => ['uid' => 7, 'pid' => 0],
        ];
        $finder = $this->createStub(RecordFinder::class);
        $finder->method('findRecord')->willReturnCallback(
            static fn (string $table, int $uid): ?array => $rows[$table . ':' . $uid] ?? null,
        );

        $sites = $this->createStub(SiteFinder::class);
        $sites->method('getSiteByPageId')->willReturnCallback(static function (int $page): Site {
            if ($page !== 10) {
                throw new SiteNotFoundException('no site for page ' . $page, 1790100106);
            }

            return new Site('shop', 1, []);
        });

        return new RecordSite($finder, $sites);
    }
}
