<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Exception\ConfigurationInactiveException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\T3Cowriter\Service\Dto\ConfigurationSelection;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Picks the LLM configuration a cowriter request runs on, and refuses one the
 * current backend user may not use.
 *
 * The order is: the configuration the editor chose, then the task's own
 * configuration, then the one the record's site names in its settings
 * (nr-llm ADR-244), then the default. Whichever it is must pass nr-llm's access
 * rule (the configuration's backend groups, nr-llm ADR-070). nr-llm's chat
 * methods do not check that rule themselves, so a route that skips this class
 * lets any editor run a group-restricted configuration by sending its
 * identifier.
 *
 * @internal
 */
final readonly class ConfigurationSelector
{
    /** Code of the refusal when the site names a configuration that cannot be used. */
    public const SITE_CONFIGURATION_UNUSABLE = 1790100005;

    public function __construct(
        private LlmConfigurationRepository $configurationRepository,
        private LlmConfigurationServiceInterface $configurationAccess,
        // Optional so constructions without sites keep their behaviour; the
        // container injects it.
        private ?ConfigurationResolver $configurationResolver = null,
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
     * @param string|null           $siteIdentifier    the site of the record the request is about; its configuration
     *                                                 replaces the default
     *
     * @throws ConfigurationNotFoundException when the chosen identifier is unknown or inactive, or when nothing
     *                                        was chosen and neither a task configuration nor a default exists
     * @throws ConfigurationInactiveException when nothing was chosen and the task's own configuration is inactive
     * @throws AccessDeniedException          when the current backend user may not use the configuration
     * @throws ConfigurationNotFoundException with code SITE_CONFIGURATION_UNUSABLE when the site names a
     *                                        configuration that does not exist or is inactive
     */
    public function select(?string $identifier, ?LlmConfiguration $taskConfiguration = null, ?string $siteIdentifier = null): LlmConfiguration
    {
        if ($identifier !== null && $identifier !== '') {
            $configuration = $this->configurationRepository->findOneByIdentifier($identifier);
            if (!$configuration instanceof LlmConfiguration || !$configuration->isActive()) {
                throw new ConfigurationNotFoundException(
                    sprintf('LLM configuration "%s" not found.', $identifier),
                    1790100001,
                );
            }
        } elseif ($taskConfiguration instanceof LlmConfiguration) {
            // Not replaced by the default: that would run the task with another
            // persona and model than its author chose, without anyone noticing.
            if (!$taskConfiguration->isActive()) {
                throw new ConfigurationInactiveException(
                    sprintf('The task\'s LLM configuration "%s" is inactive.', $taskConfiguration->getIdentifier()),
                    1790100004,
                );
            }

            $configuration = $taskConfiguration;
        } else {
            $configuration = $this->siteConfiguration($siteIdentifier) ?? $this->configurationRepository->findDefault();
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
     * The configuration the site names, or null when it names none. One it
     * names but that does not exist or is inactive is a refusal, not a fall
     * back to the default: the site was set up not to use the default. A
     * group-restricted one is checked against the current backend user here
     * and again by the access rule in {@see self::select()}.
     *
     * @throws ConfigurationNotFoundException
     * @throws AccessDeniedException
     */
    private function siteConfiguration(?string $siteIdentifier): ?LlmConfiguration
    {
        if (!$this->configurationResolver instanceof ConfigurationResolver || $siteIdentifier === null || $siteIdentifier === '') {
            return null;
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $actor       = $backendUser instanceof BackendUserAuthentication ? AiActorContext::fromBackendUser($backendUser) : null;

        try {
            return $this->configurationResolver->resolveForSite($siteIdentifier, $actor);
        } catch (ConfigurationNotFoundException|ConfigurationInactiveException $e) {
            throw new ConfigurationNotFoundException(
                sprintf('The LLM configuration of site "%s" cannot be used: %s', $siteIdentifier, $e->getMessage()),
                self::SITE_CONFIGURATION_UNUSABLE,
                $e,
            );
        }
    }

    /**
     * {@see self::select()} for a route: a refusal becomes the label key and
     * HTTP status to answer with. An unknown chosen configuration and a missing
     * default are told apart, because only the second one is the operator's to
     * fix.
     */
    public function trySelect(?string $identifier, ?LlmConfiguration $taskConfiguration = null, ?string $siteIdentifier = null): ConfigurationSelection
    {
        try {
            return ConfigurationSelection::selected($this->select($identifier, $taskConfiguration, $siteIdentifier));
        } catch (AccessDeniedException) {
            return ConfigurationSelection::refused('error.configurationDenied', 403);
        } catch (ConfigurationInactiveException) {
            return ConfigurationSelection::refused('error.taskConfigurationInactive', 409);
        } catch (ConfigurationNotFoundException $e) {
            if ($e->getCode() === self::SITE_CONFIGURATION_UNUSABLE) {
                return ConfigurationSelection::refused('error.siteConfigurationUnusable', 409);
            }

            $chosen = $identifier !== null && $identifier !== '';

            return ConfigurationSelection::refused($chosen ? 'error.configurationNotFound' : 'error.noConfiguration', 404);
        }
    }
}
