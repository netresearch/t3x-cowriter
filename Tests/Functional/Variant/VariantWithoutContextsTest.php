<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Variant;

use Netresearch\T3Cowriter\EventListener\RegisterVariantControlListener;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordFinder;
use Netresearch\T3Cowriter\Service\Variant\AudienceContexts;
use Netresearch\T3Cowriter\Service\Variant\VariantElementReader;
use Netresearch\T3Cowriter\Service\Variant\VariantWriter;
use Netresearch\T3Cowriter\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A TYPO3 without EXT:contexts: the control is still registered, no context
 * is offered or accepted, and the variant is created hidden.
 */
#[CoversClass(RegisterVariantControlListener::class)]
#[CoversClass(AudienceContexts::class)]
#[CoversClass(VariantWriter::class)]
final class VariantWithoutContextsTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/Fixtures/without_contexts.csv');
    }

    private function contexts(): AudienceContexts
    {
        return new AudienceContexts(GeneralUtility::makeInstance(ConnectionPool::class));
    }

    #[Test]
    public function theControlDoesNotDependOnContexts(): void
    {
        self::assertSame(
            ['renderType' => RegisterVariantControlListener::NODE_NAME],
            $GLOBALS['TCA']['tt_content']['types']['text']['columnsOverrides']['bodytext']['config']['fieldControl'][RegisterVariantControlListener::CONTROL_NAME] ?? null,
        );
    }

    #[Test]
    public function noContextIsOfferedOrAssignable(): void
    {
        $backendUser = $this->setUpBackendUser(1);

        self::assertFalse($this->contexts()->isInstalled());
        self::assertFalse($this->contexts()->mayAssign($backendUser));
        self::assertSame([], $this->contexts()->available());
        self::assertNull($this->contexts()->title(5));
    }

    #[Test]
    public function theVariantIsCreatedHidden(): void
    {
        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $finder          = new RecordFinder(GeneralUtility::makeInstance(ConnectionPool::class));
        $element         = (new VariantElementReader($finder))->read(10, $backendUser);

        $uid = (new VariantWriter($finder, $this->contexts(), new RecordingLogger()))
            ->create($element, 'Chairs for students', '<p>Cheap.</p>', 0, $backendUser);

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder->select('hidden', 'header')->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchAssociative();

        self::assertSame(['hidden' => 1, 'header' => 'Chairs for students'], ['hidden' => (int) ($row['hidden'] ?? 0), 'header' => $row['header'] ?? null]);
    }
}
