<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Presentation;

use Netresearch\T3Cowriter\EventListener\RegisterPresentationControlListener;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\Presentation\PresentationAccess;
use Netresearch\T3Cowriter\Service\Presentation\PresentationElementReader;
use Netresearch\T3Cowriter\Service\Presentation\PresentationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A TYPO3 without bootstrap_package: no layout exists, no text element gets
 * the control, and the routes refuse even an administrator.
 */
#[CoversClass(RegisterPresentationControlListener::class)]
#[CoversClass(PresentationAccess::class)]
#[CoversClass(PresentationElementReader::class)]
final class PresentationWithoutBootstrapPackageTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            't3_cowriter' => [
                'presentation' => ['enable' => '1'],
            ],
        ],
    ];

    #[Test]
    public function noLayoutExistsAndNoTextElementCarriesTheControl(): void
    {
        self::assertSame([], PresentationAccess::layoutsInTca());

        foreach (['text', 'textpic', 'textmedia'] as $ctype) {
            self::assertArrayNotHasKey(
                RegisterPresentationControlListener::CONTROL_NAME,
                $GLOBALS['TCA']['tt_content']['types'][$ctype]['columnsOverrides']['bodytext']['config']['fieldControl'] ?? [],
                $ctype,
            );
        }
    }

    #[Test]
    public function evenAnAdministratorIsRefused(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/plain_text_element.csv');
        $backendUser = $this->setUpBackendUser(1);

        $this->expectExceptionObject(PresentationException::notAvailable());

        (new PresentationElementReader(new RecordFinder(GeneralUtility::makeInstance(ConnectionPool::class))))->read(10, $backendUser);
    }
}
