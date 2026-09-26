<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\Configuration;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\T3Cowriter\Service\ConfigurationSelector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * nr-llm's own access rule decides which configuration an editor may choose:
 * a configuration restricted to a backend group is offered to and runs for a
 * member of that group, and is neither offered to nor run for anyone else.
 */
#[CoversClass(ConfigurationSelector::class)]
final class ConfigurationSelectorTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/Fixtures/configuration_access.csv');
    }

    #[Test]
    public function aGroupMemberIsOfferedAndRunsTheRestrictedConfiguration(): void
    {
        $this->setUpBackendUser(3);
        $selector = $this->selector();

        self::assertSame(['restricted', 'open'], $this->identifiers($selector->selectable()));
        self::assertSame('restricted', $selector->select('restricted')->getIdentifier());
    }

    #[Test]
    public function anotherEditorIsNeitherOfferedNorRunsTheRestrictedConfiguration(): void
    {
        $this->setUpBackendUser(4);
        $selector = $this->selector();

        self::assertSame(['open'], $this->identifiers($selector->selectable()));
        self::assertSame('open', $selector->select(null)->getIdentifier());

        $this->expectException(AccessDeniedException::class);
        $selector->select('restricted');
    }

    private function selector(): ConfigurationSelector
    {
        return new ConfigurationSelector(
            $this->get(LlmConfigurationRepository::class),
            $this->get(LlmConfigurationServiceInterface::class),
        );
    }

    /**
     * @param list<LlmConfiguration> $configurations
     *
     * @return list<string>
     */
    private function identifiers(array $configurations): array
    {
        return array_map(static fn (LlmConfiguration $configuration): string => $configuration->getIdentifier(), $configurations);
    }
}
