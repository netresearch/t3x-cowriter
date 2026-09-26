<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\T3Cowriter\Service\Dto\ConfigurationSelection;

/**
 * Picks the LLM configuration a cowriter request runs on, and refuses one the
 * current backend user may not use.
 *
 * The order is: the configuration the editor chose, then the task's own
 * configuration, then the default. Whichever it is must pass nr-llm's access
 * rule (the configuration's backend groups, nr-llm ADR-070). nr-llm's chat
 * methods do not check that rule themselves, so a route that skips this class
 * lets any editor run a group-restricted configuration by sending its
 * identifier.
 *
 * @internal
 */
final readonly class ConfigurationSelector
{
    public function __construct(
        private LlmConfigurationRepository $configurationRepository,
        private LlmConfigurationServiceInterface $configurationAccess,
    ) {}

    /**
     * The active configurations the current backend user may use, for a
     * configuration picker.
     *
     * @return list<LlmConfiguration>
     */
    public function selectable(): array
    {
        $selectable = [];
        foreach ($this->configurationAccess->getAccessibleConfigurations() as $configuration) {
            if ($configuration->isActive()) {
                $selectable[] = $configuration;
            }
        }

        return $selectable;
    }

    /**
     * @param string|null           $identifier        the configuration the editor chose; null or '' for none
     * @param LlmConfiguration|null $taskConfiguration the task's own configuration, used when the editor chose none
     *
     * @throws ConfigurationNotFoundException when the chosen identifier is unknown or inactive, or when nothing
     *                                        was chosen and neither a task configuration nor a default exists
     * @throws AccessDeniedException          when the current backend user may not use the configuration
     */
    public function select(?string $identifier, ?LlmConfiguration $taskConfiguration = null): LlmConfiguration
    {
        if ($identifier !== null && $identifier !== '') {
            $configuration = $this->configurationRepository->findOneByIdentifier($identifier);
            if (!$configuration instanceof LlmConfiguration || !$configuration->isActive()) {
                throw new ConfigurationNotFoundException(
                    sprintf('LLM configuration "%s" not found.', $identifier),
                    1790100001,
                );
            }
        } else {
            $configuration = $taskConfiguration ?? $this->configurationRepository->findDefault();
            if (!$configuration instanceof LlmConfiguration) {
                throw new ConfigurationNotFoundException('No default LLM configuration.', 1790100002);
            }
        }

        if (!$this->configurationAccess->hasAccess($configuration)) {
            throw new AccessDeniedException(
                sprintf('Access denied to LLM configuration "%s".', $configuration->getIdentifier()),
                1790100003,
            );
        }

        return $configuration;
    }

    /**
     * {@see self::select()} for a route: a refusal becomes the label key and
     * HTTP status to answer with. An unknown chosen configuration and a missing
     * default are told apart, because only the second one is the operator's to
     * fix.
     */
    public function trySelect(?string $identifier, ?LlmConfiguration $taskConfiguration = null): ConfigurationSelection
    {
        try {
            return ConfigurationSelection::selected($this->select($identifier, $taskConfiguration));
        } catch (AccessDeniedException) {
            return ConfigurationSelection::refused('error.configurationDenied', 403);
        } catch (ConfigurationNotFoundException) {
            $chosen = $identifier !== null && $identifier !== '';

            return ConfigurationSelection::refused($chosen ? 'error.configurationNotFound' : 'error.noConfiguration', 404);
        }
    }
}
