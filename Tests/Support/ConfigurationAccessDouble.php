<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Support;

use LogicException;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\T3Cowriter\Service\ConfigurationSelector;

/**
 * nr-llm's configuration access rule for tests: every configuration is usable
 * except the identifiers listed as denied. The accessible list is the one
 * passed in, or else what the repository's findActive() returns minus the
 * denied ones, which is what nr-llm answers an administrator. The management
 * methods are not used by cowriter and throw.
 */
final class ConfigurationAccessDouble implements LlmConfigurationServiceInterface
{
    /**
     * @param list<string>                $deniedIdentifiers
     * @param list<LlmConfiguration>|null $accessible        null: derive from $repository
     */
    public function __construct(
        private readonly array $deniedIdentifiers = [],
        private readonly ?array $accessible = null,
        private readonly ?LlmConfigurationRepository $repository = null,
    ) {}

    /**
     * A selector over $repository that lets the current user use every
     * configuration except $deniedIdentifiers.
     *
     * @param list<string> $deniedIdentifiers
     */
    public static function selector(LlmConfigurationRepository $repository, array $deniedIdentifiers = []): ConfigurationSelector
    {
        return new ConfigurationSelector($repository, new self($deniedIdentifiers, null, $repository));
    }

    public function getAccessibleConfigurations(): array
    {
        if ($this->accessible !== null) {
            return $this->accessible;
        }

        $accessible = [];
        foreach ($this->repository?->findActive() ?? [] as $configuration) {
            if ($configuration instanceof LlmConfiguration && $this->hasAccess($configuration)) {
                $accessible[] = $configuration;
            }
        }

        return $accessible;
    }

    public function hasAccess(LlmConfiguration $configuration): bool
    {
        return !in_array($configuration->getIdentifier(), $this->deniedIdentifiers, true);
    }

    public function checkAccess(LlmConfiguration $configuration): void
    {
        throw new LogicException('Not used by cowriter.', 1790100101);
    }

    public function getConfiguration(string $identifier): LlmConfiguration
    {
        throw new LogicException('Not used by cowriter.', 1790100102);
    }

    public function getDefaultConfiguration(): LlmConfiguration
    {
        throw new LogicException('Not used by cowriter.', 1790100103);
    }

    public function setAsDefault(LlmConfiguration $configuration): void
    {
        throw new LogicException('Not used by cowriter.', 1790100104);
    }

    public function toggleActive(LlmConfiguration $configuration): void
    {
        throw new LogicException('Not used by cowriter.', 1790100105);
    }

    public function create(LlmConfiguration $configuration): void
    {
        throw new LogicException('Not used by cowriter.', 1790100106);
    }

    public function update(LlmConfiguration $configuration): void
    {
        throw new LogicException('Not used by cowriter.', 1790100107);
    }

    public function delete(LlmConfiguration $configuration): void
    {
        throw new LogicException('Not used by cowriter.', 1790100108);
    }

    public function isIdentifierAvailable(string $identifier, ?int $excludeUid = null): bool
    {
        throw new LogicException('Not used by cowriter.', 1790100109);
    }
}
