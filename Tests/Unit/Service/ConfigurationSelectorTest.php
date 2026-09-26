<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\T3Cowriter\Service\ConfigurationSelector;
use Netresearch\T3Cowriter\Tests\Support\ConfigurationAccessDouble;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigurationSelector::class)]
final class ConfigurationSelectorTest extends TestCase
{
    private LlmConfigurationRepository&Stub $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createStub(LlmConfigurationRepository::class);
    }

    #[Test]
    public function theChosenConfigurationWinsOverTheTaskConfiguration(): void
    {
        $chosen = $this->configuration('chosen');
        $this->repository->method('findOneByIdentifier')->willReturnMap([['chosen', $chosen]]);

        self::assertSame($chosen, $this->selector()->select('chosen', $this->configuration('task')));
    }

    #[Test]
    public function theTaskConfigurationWinsOverTheDefault(): void
    {
        $task = $this->configuration('task');
        $this->repository->method('findDefault')->willReturn($this->configuration('default'));

        self::assertSame($task, $this->selector()->select(null, $task));
        self::assertSame($task, $this->selector()->select('', $task));
    }

    #[Test]
    public function withoutChoiceAndTaskTheDefaultIsUsed(): void
    {
        $default = $this->configuration('default');
        $this->repository->method('findDefault')->willReturn($default);

        self::assertSame($default, $this->selector()->select(null));
    }

    #[Test]
    public function aPermittedRestrictedConfigurationIsSelected(): void
    {
        $restricted = $this->configuration('restricted');
        $this->repository->method('findOneByIdentifier')->willReturn($restricted);

        self::assertSame($restricted, $this->selector(['someone-else'])->select('restricted'));
    }

    #[Test]
    public function aDeniedChosenConfigurationIsRefused(): void
    {
        $this->repository->method('findOneByIdentifier')->willReturn($this->configuration('restricted'));

        $this->expectException(AccessDeniedException::class);
        $this->selector(['restricted'])->select('restricted');
    }

    #[Test]
    public function aDeniedTaskConfigurationIsRefused(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->selector(['task'])->select(null, $this->configuration('task'));
    }

    #[Test]
    public function aDeniedDefaultIsRefused(): void
    {
        $this->repository->method('findDefault')->willReturn($this->configuration('default'));

        $this->expectException(AccessDeniedException::class);
        $this->selector(['default'])->select(null);
    }

    #[Test]
    public function anUnknownChosenConfigurationIsNotReplacedByTheDefault(): void
    {
        $this->repository->method('findOneByIdentifier')->willReturn(null);
        $this->repository->method('findDefault')->willReturn($this->configuration('default'));

        $this->expectException(ConfigurationNotFoundException::class);
        $this->selector()->select('ghost');
    }

    #[Test]
    public function anInactiveChosenConfigurationIsRefused(): void
    {
        $this->repository->method('findOneByIdentifier')->willReturn($this->configuration('dormant', false));

        $this->expectException(ConfigurationNotFoundException::class);
        $this->selector()->select('dormant');
    }

    #[Test]
    public function noDefaultIsReportedAsNotFound(): void
    {
        $this->repository->method('findDefault')->willReturn(null);

        $this->expectException(ConfigurationNotFoundException::class);
        $this->selector()->select(null);
    }

    #[Test]
    public function trySelectNamesTheLabelAndStatusOfEachRefusal(): void
    {
        $this->repository->method('findOneByIdentifier')->willReturnMap([
            ['restricted', $this->configuration('restricted')],
            ['ghost', null],
        ]);
        $this->repository->method('findDefault')->willReturn(null);
        $selector = $this->selector(['restricted']);

        $denied = $selector->trySelect('restricted');
        self::assertNull($denied->configuration);
        self::assertSame(['error.configurationDenied', 403], [$denied->errorLabel, $denied->status]);

        $unknown = $selector->trySelect('ghost');
        self::assertSame(['error.configurationNotFound', 404], [$unknown->errorLabel, $unknown->status]);

        $none = $selector->trySelect(null);
        self::assertSame(['error.noConfiguration', 404], [$none->errorLabel, $none->status]);
    }

    #[Test]
    public function trySelectReturnsTheSelectedConfiguration(): void
    {
        $default = $this->configuration('default');
        $this->repository->method('findDefault')->willReturn($default);

        $selection = $this->selector()->trySelect(null);

        self::assertSame($default, $selection->configuration);
        self::assertSame(200, $selection->status);
    }

    #[Test]
    public function selectableListsTheAccessibleActiveConfigurationsInOrder(): void
    {
        $first    = $this->configuration('first');
        $inactive = $this->configuration('inactive', false);
        $second   = $this->configuration('second');
        $selector = new ConfigurationSelector($this->repository, new ConfigurationAccessDouble([], [$first, $inactive, $second]));

        self::assertSame([$first, $second], $selector->selectable());
    }

    /**
     * @param list<string> $deniedIdentifiers
     */
    private function selector(array $deniedIdentifiers = []): ConfigurationSelector
    {
        return ConfigurationAccessDouble::selector($this->repository, $deniedIdentifiers);
    }

    private function configuration(string $identifier, bool $active = true): LlmConfiguration&Stub
    {
        $configuration = $this->createStub(LlmConfiguration::class);
        $configuration->method('getIdentifier')->willReturn($identifier);
        $configuration->method('isActive')->willReturn($active);

        return $configuration;
    }
}
