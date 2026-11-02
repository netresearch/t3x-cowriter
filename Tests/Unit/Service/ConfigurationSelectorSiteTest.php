<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Site\SiteLlmSettings;
use Netresearch\T3Cowriter\Service\ConfigurationSelector;
use Netresearch\T3Cowriter\Tests\Support\ConfigurationAccessDouble;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The configuration a record's site names replaces the default, and only the
 * default (nr-llm ADR-244).
 */
#[CoversClass(ConfigurationSelector::class)]
final class ConfigurationSelectorSiteTest extends TestCase
{
    private LlmConfigurationRepository&Stub $repository;

    /** @var array<string, LlmConfiguration> */
    private array $byIdentifier = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createStub(LlmConfigurationRepository::class);
        $this->repository->method('findDefault')->willReturn($this->configuration('default'));
        $this->repository->method('findOneByIdentifier')->willReturnCallback(
            fn (string $identifier): ?LlmConfiguration => $this->byIdentifier[$identifier] ?? null,
        );
    }

    #[Test]
    public function theSitesConfigurationReplacesTheDefault(): void
    {
        $shop                              = $this->configuration('shop-writer');
        $this->byIdentifier['shop-writer'] = $shop;

        self::assertSame($shop, $this->selector()->select(null, null, 'shop'));
    }

    #[Test]
    public function theChosenAndTheTaskConfigurationStillWin(): void
    {
        $this->byIdentifier['shop-writer'] = $this->configuration('shop-writer');
        $chosen                            = $this->configuration('chosen');
        $this->byIdentifier['chosen']      = $chosen;
        $task                              = $this->configuration('task');

        self::assertSame($chosen, $this->selector()->select('chosen', $task, 'shop'));
        self::assertSame($task, $this->selector()->select(null, $task, 'shop'));
    }

    #[Test]
    public function aSiteThatNamesNoneAndNoSiteKeepTheDefault(): void
    {
        self::assertSame('default', $this->selector()->select(null, null, 'blog')->getIdentifier());
        self::assertSame('default', $this->selector()->select(null)->getIdentifier());
    }

    #[Test]
    public function aSiteConfigurationThatIsMissingOrInactiveIsRefusedWithItsOwnMessage(): void
    {
        $missing = $this->selector()->trySelect(null, null, 'shop');
        self::assertSame(['error.siteConfigurationUnusable', 409], [$missing->errorLabel, $missing->status]);

        $this->byIdentifier['shop-writer'] = $this->configuration('shop-writer', false);
        $inactive                          = $this->selector()->trySelect(null, null, 'shop');
        self::assertSame(['error.siteConfigurationUnusable', 409], [$inactive->errorLabel, $inactive->status]);
    }

    #[Test]
    public function aSiteConfigurationTheEditorMayNotUseIsDenied(): void
    {
        $this->byIdentifier['shop-writer'] = $this->configuration('shop-writer');

        $denied = $this->selector(['shop-writer'])->trySelect(null, null, 'shop');

        self::assertSame(['error.configurationDenied', 403], [$denied->errorLabel, $denied->status]);
    }

    /**
     * @param list<string> $deniedIdentifiers
     */
    private function selector(array $deniedIdentifiers = []): ConfigurationSelector
    {
        $finder = $this->createStub(SiteFinder::class);
        $finder->method('getSiteByIdentifier')->willReturnCallback(static function (string $identifier): Site {
            $settings = ['shop' => ['nrllm.configuration' => 'shop-writer'], 'blog' => []];
            if (!isset($settings[$identifier])) {
                throw new SiteNotFoundException('no site ' . $identifier, 1790100105);
            }

            return new Site($identifier, 1, [], SiteSettings::create(new Settings($settings[$identifier])));
        });

        return new ConfigurationSelector(
            $this->repository,
            new ConfigurationAccessDouble($deniedIdentifiers, null, $this->repository),
            new ConfigurationResolver($this->repository, new SiteLlmSettings($finder)),
        );
    }

    private function configuration(string $identifier, bool $active = true): LlmConfiguration&Stub
    {
        $configuration = $this->createStub(LlmConfiguration::class);
        $configuration->method('getIdentifier')->willReturn($identifier);
        $configuration->method('isActive')->willReturn($active);

        return $configuration;
    }
}
