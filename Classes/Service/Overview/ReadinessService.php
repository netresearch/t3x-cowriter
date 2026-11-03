<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Overview;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Model\AdapterType;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Feature\TranslationServiceInterface;
use Netresearch\NrLlm\Specialized\Image\DallEImageService;
use Netresearch\NrLlm\Specialized\Translation\LlmTranslator;
use Netresearch\T3Cowriter\Service\ConfigurationSelector;
use Netresearch\T3Cowriter\Service\Dto\ConfigurationSelection;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionConfiguration;
use Netresearch\T3Cowriter\Service\Image\GeneratedImageStorageInterface;
use Netresearch\T3Cowriter\Service\Image\ImageGenerationConfiguration;
use Netresearch\T3Cowriter\Service\InlineSuggestion\InlineSuggestionConfiguration;
use Netresearch\T3Cowriter\Service\Presentation\PresentationAccess;
use Netresearch\T3Cowriter\Service\Presentation\PresentationConfiguration;
use Throwable;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Whether each cowriter action can run for the viewing backend user, decided
 * along the path the action takes at runtime:
 *
 * - writing, field suggestions, presentation and inline suggestions pick their
 *   configuration through {@see ConfigurationSelector} (with the viewer's
 *   group access), exactly as their controllers do;
 * - image description and the language-model translation run through nr-llm's
 *   generic call, which takes the default configuration only when it has a
 *   model and no group restriction ({@see ConfigurationResolver::resolveDefaultConfiguration()});
 * - translation prefers an available specialized translator such as DeepL;
 * - image generation needs its switch, nr-llm's OpenAI key and an upload folder.
 *
 * Nothing here calls a provider. What cannot be decided without a call (a
 * model chosen per request, an unknown adapter) is NotChecked, never Ready.
 *
 * @internal
 */
final readonly class ReadinessService
{
    public function __construct(
        private ConfigurationSelector $configurationSelector,
        private ConfigurationResolver $configurationResolver,
        private TranslationServiceInterface $translationService,
        private DallEImageService $imageService,
        private GeneratedImageStorageInterface $imageStorage,
        private ImageGenerationConfiguration $imageConfiguration,
        private InlineSuggestionConfiguration $inlineSuggestionConfiguration,
        private FieldSuggestionConfiguration $fieldSuggestionConfiguration,
        private PresentationConfiguration $presentationConfiguration,
        private SiteFinder $siteFinder,
    ) {}

    /**
     * One row per action, in the order of {@see ReadinessAction}.
     *
     * @return list<ReadinessRow>
     */
    public function rows(): array
    {
        return array_map($this->row(...), ReadinessAction::cases());
    }

    public function row(ReadinessAction $action): ReadinessRow
    {
        return match ($action) {
            ReadinessAction::Writing           => $this->selectedRow($action, $this->configurationSelector->trySelect(null)),
            ReadinessAction::Vision            => $this->genericRow($action, ModelCapability::VISION),
            ReadinessAction::Translation       => $this->translationRow(),
            ReadinessAction::Image             => $this->imageRow(),
            ReadinessAction::InlineSuggestions => $this->inlineSuggestionsRow(),
            ReadinessAction::FieldSuggestions  => $this->fieldSuggestionsRow(),
            ReadinessAction::Presentation      => $this->presentationRow(),
        };
    }

    /**
     * The task path once more for every site that names its own configuration
     * (nr-llm site setting), for an administrator. Only a task run passes the
     * record's site to {@see ConfigurationSelector}; the dialog's own writing
     * and the field suggestions take the installation default.
     *
     * @return list<ReadinessRow>
     */
    public function siteRows(): array
    {
        $rows = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $identifier = $site->getIdentifier();
            if (!$this->namesConfiguration($identifier)) {
                continue;
            }

            $row    = $this->selectedRow(ReadinessAction::Writing, $this->configurationSelector->trySelect(null, null, $identifier));
            $rows[] = new ReadinessRow($row->action, $row->state, $row->reason, $row->arguments, $identifier);
        }

        return $rows;
    }

    /**
     * Whether the site's settings name a configuration: nr-llm answers null
     * only when they name none. One it names but that cannot be used throws,
     * and that site gets its row too.
     */
    private function namesConfiguration(string $siteIdentifier): bool
    {
        try {
            return $this->configurationResolver->resolveForSite($siteIdentifier) instanceof LlmConfiguration;
        } catch (Throwable) {
            return true;
        }
    }

    private function translationRow(): ReadinessRow
    {
        $translators = [];
        foreach ($this->translationService->getAvailableTranslators() as $identifier => $info) {
            if ($identifier === LlmTranslator::IDENTIFIER || !$info['available']) {
                continue;
            }

            $translators[] = $info['name'] !== '' ? $info['name'] : $identifier;
        }

        if ($translators !== []) {
            return new ReadinessRow(ReadinessAction::Translation, ReadinessState::Ready, ReadinessReason::TranslatorAvailable, [implode(', ', $translators)]);
        }

        return $this->genericRow(ReadinessAction::Translation, null);
    }

    private function imageRow(): ReadinessRow
    {
        if (!$this->imageConfiguration->isEnabled()) {
            return new ReadinessRow(ReadinessAction::Image, ReadinessState::Off, ReadinessReason::SwitchedOff);
        }

        if (!$this->imageService->isAvailable()) {
            return new ReadinessRow(ReadinessAction::Image, ReadinessState::NotReady, ReadinessReason::ImageKeyMissing);
        }

        if (!$this->imageStorage->targetFolder() instanceof Folder) {
            return new ReadinessRow(ReadinessAction::Image, ReadinessState::NotReady, ReadinessReason::NoUploadFolder);
        }

        $model = $this->imageConfiguration->model()
            ?? $this->imageService->resolveDefaultModel(ImageGenerationConfiguration::FALLBACK_MODEL);

        return new ReadinessRow(ReadinessAction::Image, ReadinessState::Ready, ReadinessReason::ImageReady, [$model]);
    }

    private function inlineSuggestionsRow(): ReadinessRow
    {
        if (!$this->inlineSuggestionConfiguration->isEnabled()) {
            return new ReadinessRow(ReadinessAction::InlineSuggestions, ReadinessState::Off, ReadinessReason::SwitchedOff);
        }

        $identifier = $this->inlineSuggestionConfiguration->configuration();

        return $this->selectedRow(
            ReadinessAction::InlineSuggestions,
            $this->configurationSelector->trySelect($identifier),
            $identifier ?? '',
        );
    }

    private function fieldSuggestionsRow(): ReadinessRow
    {
        if ($this->fieldSuggestionConfiguration->fields() === []) {
            return new ReadinessRow(ReadinessAction::FieldSuggestions, ReadinessState::Off, ReadinessReason::NoSuggestionFields);
        }

        return $this->selectedRow(ReadinessAction::FieldSuggestions, $this->configurationSelector->trySelect(null));
    }

    private function presentationRow(): ReadinessRow
    {
        if (!$this->presentationConfiguration->isEnabled()) {
            return new ReadinessRow(ReadinessAction::Presentation, ReadinessState::Off, ReadinessReason::SwitchedOff);
        }

        // The same test that decides whether the control is registered at all.
        if (PresentationAccess::layoutsInTca() === []) {
            return new ReadinessRow(ReadinessAction::Presentation, ReadinessState::NotReady, ReadinessReason::BootstrapPackageMissing);
        }

        return $this->selectedRow(ReadinessAction::Presentation, $this->configurationSelector->trySelect(null));
    }

    /**
     * A path that picks its configuration through ConfigurationSelector.
     */
    private function selectedRow(ReadinessAction $action, ConfigurationSelection $selection, string $identifier = ''): ReadinessRow
    {
        $configuration = $selection->configuration;
        if (!$configuration instanceof LlmConfiguration) {
            $reason = match ($selection->errorLabel) {
                'error.configurationDenied'       => ReadinessReason::ConfigurationDenied,
                'error.configurationNotFound'     => ReadinessReason::ConfigurationNotFound,
                'error.siteConfigurationUnusable' => ReadinessReason::SiteConfigurationUnusable,
                default                           => ReadinessReason::NoConfiguration,
            };

            return new ReadinessRow($action, ReadinessState::NotReady, $reason, $reason === ReadinessReason::ConfigurationNotFound ? [$identifier] : []);
        }

        return $this->chainRow($action, $configuration, null);
    }

    /**
     * A path through nr-llm's generic call: it runs on the default
     * configuration only when that has a model and no group restriction.
     * Without such a default the call names no provider, and nr-llm refuses
     * it (KeyedProviderRegistry::getProvider(null) throws), so the action
     * cannot run.
     */
    private function genericRow(ReadinessAction $action, ?ModelCapability $capability): ReadinessRow
    {
        try {
            $configuration = $this->configurationResolver->resolveDefaultConfiguration(null);
        } catch (Throwable) {
            $configuration = null;
        }

        if (!$configuration instanceof LlmConfiguration) {
            return new ReadinessRow($action, ReadinessState::NotReady, ReadinessReason::NoGenericDefault);
        }

        return $this->chainRow($action, $configuration, $capability);
    }

    /**
     * Model and provider behind a configuration.
     */
    private function chainRow(ReadinessAction $action, LlmConfiguration $configuration, ?ModelCapability $capability): ReadinessRow
    {
        $model = $configuration->getLlmModel();
        if ($configuration->usesCriteriaSelection() || !$model instanceof Model) {
            return new ReadinessRow($action, ReadinessState::NotChecked, ReadinessReason::ModelChosenPerRequest, [$configuration->getName()]);
        }

        if (!$model->isActive()) {
            return new ReadinessRow($action, ReadinessState::NotReady, ReadinessReason::ModelInactive, [$model->getName()]);
        }

        if ($capability instanceof ModelCapability && !$model->getCapabilitySet()->has($capability)) {
            return new ReadinessRow($action, ReadinessState::NotReady, ReadinessReason::ModelLacksVision, [$model->getName()]);
        }

        $provider = $model->getProvider();
        if (!$provider instanceof Provider) {
            return new ReadinessRow($action, ReadinessState::NotReady, ReadinessReason::ProviderMissing, [$model->getName()]);
        }

        if (!$provider->isActive()) {
            return new ReadinessRow($action, ReadinessState::NotReady, ReadinessReason::ProviderInactive, [$provider->getName()]);
        }

        $adapter = $provider->getAdapterTypeEnum();
        if (!$adapter instanceof AdapterType) {
            return new ReadinessRow($action, ReadinessState::NotChecked, ReadinessReason::AdapterUnknown, [$provider->getName()]);
        }

        if ($adapter->requiresApiKey() && !$provider->hasApiKey()) {
            return new ReadinessRow($action, ReadinessState::NotReady, ReadinessReason::ProviderKeyMissing, [$provider->getName()]);
        }

        return new ReadinessRow(
            $action,
            ReadinessState::Ready,
            ReadinessReason::ConfigurationReady,
            [$configuration->getName(), $model->getName(), $provider->getName()],
        );
    }
}
