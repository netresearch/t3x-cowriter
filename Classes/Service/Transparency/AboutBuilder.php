<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Transparency;

use Netresearch\T3Cowriter\Service\BackendLabels;
use Netresearch\T3Cowriter\Service\FieldSuggestion\ContextComposer;
use Netresearch\T3Cowriter\Service\FieldSuggestion\RecordContextReader;
use Netresearch\T3Cowriter\Service\Presentation\SectionPlanner;
use Netresearch\T3Cowriter\Service\Presentation\SectionProposalService;
use Throwable;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The "About this suggestion" section of the Cowriter dialogs that call a
 * model: which extension and feature answered, the nr-llm configuration and
 * the model, which sources of context went out (names and counts, never the
 * data), the current settings of the feature, and for users who may open
 * them the links to the settings.
 *
 * Everything in it is either already visible to the editor (the
 * configuration he may use, the fields he may edit) or a setting of the
 * feature: no provider endpoint, no key, no other configuration. The links
 * are added only when the user passes the check of their target: the
 * Settings module's own module access (system maintainers), and for the
 * nr-llm configuration record the administrator check record editing on the
 * root level needs.
 *
 * @internal
 */
final readonly class AboutBuilder
{
    /**
     * The extension configuration lives in the Settings module: "system_settings"
     * on TYPO3 14.3, "tools_toolssettings" on 13.4 (an alias on 14.3).
     */
    private const SETTINGS_MODULES = ['system_settings', 'tools_toolssettings'];

    private const CONFIGURATION_TABLE = 'tx_nrllm_configuration';

    /**
     * nr-llm's list of LLM configurations (nr-llm 0.41.14 and later).
     */
    private const CONFIGURATIONS_MODULE = 'nrllm_configurations';

    public function __construct(
        private UriBuilder $uriBuilder,
        private ModuleProvider $moduleProvider,
        private BackendLabels $labels = new BackendLabels(),
    ) {}

    /**
     * @param array<string, string> $fields the fields of the table with the button the user may edit, name => label
     *
     * @return array<string, mixed>
     */
    public function fieldSuggestions(ModelUse $modelUse, ContextSources $sources, array $fields, int $count, int $rateLimit, BackendUserAuthentication $backendUser): array
    {
        return $this->payload('about.feature.fieldSuggestions', $modelUse, $sources->labels($this->labels), [
            $this->row('about.setting.fields', implode(', ', $fields)),
            $this->row('about.setting.count', (string) $count),
            $this->row('about.setting.limits', $this->labels->get(
                'about.limits.fieldSuggestions',
                $rateLimit,
                RecordContextReader::MAX_PAGE_CONTENT_LENGTH,
                RecordContextReader::MAX_SIBLING_PAGES,
                ContextComposer::MAX_DATA_LENGTH,
            )),
        ], $backendUser);
    }

    /**
     * @param array<string, string> $fields the fields of the table with the button the user may edit, name => label
     *
     * @return array<string, mixed>
     */
    public function fieldOverview(ModelUse $modelUse, ContextSources $sources, array $fields, int $rateLimit, BackendUserAuthentication $backendUser): array
    {
        return $this->payload('about.feature.fieldOverview', $modelUse, $sources->labels($this->labels), [
            $this->row('about.setting.fields', implode(', ', $fields)),
            $this->row('about.setting.limits', $this->labels->get(
                'about.limits.fieldSuggestions',
                $rateLimit,
                RecordContextReader::MAX_PAGE_CONTENT_LENGTH,
                RecordContextReader::MAX_SIBLING_PAGES,
                ContextComposer::MAX_DATA_LENGTH,
            )),
        ], $backendUser);
    }

    /**
     * @param bool $modelFromSetting whether the model is the one set in image.model (else nr-llm's default image model)
     *
     * @return array<string, mixed>
     */
    public function imageGeneration(ModelUse $modelUse, bool $modelFromSetting, int $maxPromptLength, BackendUserAuthentication $backendUser): array
    {
        return $this->payload('about.feature.image', $modelUse, [$this->labels->get('about.source.imagePrompt')], [
            $this->row('about.setting.imageModel', $this->labels->get(
                $modelFromSetting ? 'about.imageModel.setting' : 'about.imageModel.default',
                $modelUse->model,
            )),
            $this->row('about.setting.limits', $this->labels->get('about.limits.image', $maxPromptLength)),
        ], $backendUser);
    }

    /**
     * @param ModelUse|null $modelUse null when the sections came from the headings and no model was asked
     *
     * @return array<string, mixed>
     */
    public function presentation(?ModelUse $modelUse, int $blocks, BackendUserAuthentication $backendUser): array
    {
        $sources = $modelUse instanceof ModelUse
            ? [$this->labels->get('about.source.elementHeader'), $this->labels->get('about.source.elementBlocks', $blocks)]
            : [];

        $payload = $this->payload('about.feature.presentation', $modelUse ?? new ModelUse('', '', 0, '', ''), $sources, [
            $this->row('about.setting.limits', $this->labels->get(
                'about.limits.presentation',
                SectionPlanner::MIN_SECTIONS,
                SectionPlanner::MAX_SECTIONS,
                SectionProposalService::MAX_BLOCKS,
            )),
        ], $backendUser, $modelUse instanceof ModelUse);

        if (!$modelUse instanceof ModelUse) {
            $payload['note'] = $this->labels->get('about.note.noModel');
        }

        return $payload;
    }

    /**
     * @param list<string>                              $sources
     * @param list<array{label: string, value: string}> $settings
     *
     * @return array<string, mixed>
     */
    private function payload(string $featureKey, ModelUse $modelUse, array $sources, array $settings, BackendUserAuthentication $backendUser, bool $usesModel = true): array
    {
        $feature = $this->labels->get($featureKey);

        return [
            'origin'        => $this->labels->get('about.origin', $feature),
            'feature'       => $feature,
            'configuration' => $this->configuration($modelUse),
            'model'         => $this->model($modelUse, $usesModel),
            'note'          => '',
            'sources'       => $sources,
            'settings'      => [
                $this->row('about.setting.feature', $this->labels->get('about.setting.featureOn', $feature)),
                ...array_values(array_filter($settings, static fn (array $row): bool => $row['value'] !== '')),
            ],
            'links' => $this->links($modelUse, $backendUser),
        ];
    }

    private function configuration(ModelUse $modelUse): string
    {
        if ($modelUse->configurationName === '' && $modelUse->configurationIdentifier === '') {
            return '';
        }

        if ($modelUse->configurationName === '' || $modelUse->configurationName === $modelUse->configurationIdentifier) {
            return $modelUse->configurationIdentifier !== '' ? $modelUse->configurationIdentifier : $modelUse->configurationName;
        }

        return $modelUse->configurationIdentifier === ''
            ? $modelUse->configurationName
            : sprintf('%s (%s)', $modelUse->configurationName, $modelUse->configurationIdentifier);
    }

    private function model(ModelUse $modelUse, bool $usesModel): string
    {
        if (!$usesModel) {
            return '';
        }

        if ($modelUse->model === '') {
            // nr-llm did not report it.
            return $this->labels->get('about.model.unknown');
        }

        return $modelUse->provider === '' ? $modelUse->model : sprintf('%s (%s)', $modelUse->model, $modelUse->provider);
    }

    /**
     * @return array{label: string, value: string}
     */
    private function row(string $labelKey, string $value): array
    {
        return ['label' => $this->labels->get($labelKey), 'value' => $value];
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function links(ModelUse $modelUse, BackendUserAuthentication $backendUser): array
    {
        $links = [];

        $settingsUrl = $this->settingsModuleUrl($backendUser);
        if ($settingsUrl !== '') {
            $links[] = ['label' => $this->labels->get('about.link.extensionConfiguration'), 'url' => $settingsUrl];
        }

        if ($modelUse->configurationUid > 0 && $backendUser->isAdmin() && $backendUser->check('tables_modify', self::CONFIGURATION_TABLE)) {
            // The link opens in a new window: after "Close" the form returns to
            // nr-llm's list of configurations rather than to an empty frame.
            $parameters = ['edit' => [self::CONFIGURATION_TABLE => [$modelUse->configurationUid => 'edit']]];
            $returnUrl  = $this->moduleProvider->isModuleRegistered(self::CONFIGURATIONS_MODULE) ? $this->url(self::CONFIGURATIONS_MODULE) : '';
            if ($returnUrl !== '') {
                $parameters['returnUrl'] = $returnUrl;
            }

            $url = $this->url('record_edit', $parameters);
            if ($url !== '') {
                $links[] = ['label' => $this->labels->get('about.link.configuration'), 'url' => $url];
            }
        }

        return $links;
    }

    /**
     * The Settings module, where "Configure extensions" holds the Cowriter's
     * extension configuration; '' when the user may not open it.
     */
    private function settingsModuleUrl(BackendUserAuthentication $backendUser): string
    {
        foreach (self::SETTINGS_MODULES as $identifier) {
            if ($this->moduleProvider->isModuleRegistered($identifier)) {
                return $this->moduleProvider->accessGranted($identifier, $backendUser) ? $this->url($identifier) : '';
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function url(string $route, array $parameters = []): string
    {
        try {
            return (string) $this->uriBuilder->buildUriFromRoute($route, $parameters);
        } catch (Throwable) {
            return '';
        }
    }
}
