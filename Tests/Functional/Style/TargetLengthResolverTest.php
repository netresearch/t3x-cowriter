<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Style;

use Netresearch\T3Cowriter\Service\Style\TargetLengthResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The target length comes from the Page TSconfig of the element's page, keyed
 * by its content type, and is inherited by subpages.
 */
#[CoversClass(TargetLengthResolver::class)]
final class TargetLengthResolverTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/Fixtures/target_length.csv');
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function theElementsTypeGetsItsPagesTarget(): void
    {
        self::assertSame(150, (new TargetLengthResolver())->targetWords($this->element(10)));
    }

    #[Test]
    public function aSubpageInheritsTheTarget(): void
    {
        self::assertSame(150, (new TargetLengthResolver())->targetWords($this->element(20)));
    }

    #[Test]
    public function aTypeWithoutATargetHasNone(): void
    {
        self::assertNull((new TargetLengthResolver())->targetWords($this->element(11)));
    }

    #[Test]
    public function aPageRecordOrNoRecordHasNone(): void
    {
        $resolver = new TargetLengthResolver();

        self::assertNull($resolver->targetWords(['table' => 'pages', 'uid' => 1, 'field' => 'description']));
        self::assertNull($resolver->targetWords(null));
        self::assertNull($resolver->targetWords($this->element(999)));
    }

    /**
     * @return array{table: string, uid: int, field: string}
     */
    private function element(int $uid): array
    {
        return ['table' => 'tt_content', 'uid' => $uid, 'field' => 'bodytext'];
    }
}
