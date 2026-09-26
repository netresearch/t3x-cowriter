<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\EventListener;

use JsonException;
use Netresearch\T3Cowriter\Service\BackendLabels;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Page\Event\BeforeJavaScriptsRenderingEvent;

/**
 * Event listener to inject AJAX URLs and labels for the cowriter CKEditor plugin.
 *
 * Injects URLs as a JSON data element (not executable JavaScript) and loads
 * an external module to parse them. This approach is CSP-compliant and does
 * not require 'unsafe-inline' in the Content-Security-Policy. The plugin's
 * labels travel the same way, resolved from locallang_be.xlf in the backend
 * user's language; the JavaScript keeps its English texts as fallback.
 */
#[AsEventListener(identifier: 'cowriter-inject-ajax-urls')]
final readonly class InjectAjaxUrlsListener
{
    /**
     * locallang_be.xlf ids the CKEditor plugin and the Cowriter dialog read
     * (Labels.js); GermanTranslationTest keeps this list and the file in step.
     */
    public const LABEL_KEYS = [
        'ckeditor.button.complete',
        'ckeditor.button.vision',
        'ckeditor.button.translate',
        'ckeditor.button.tasks',
        'ckeditor.language.de',
        'ckeditor.language.en',
        'ckeditor.language.fr',
        'ckeditor.language.es',
        'ckeditor.language.it',
        'ckeditor.language.nl',
        'ckeditor.language.pt',
        'ckeditor.language.pl',
        'ckeditor.language.ja',
        'ckeditor.language.zh',
        'ckeditor.action.openLlmSettings',
        'ckeditor.unknownError',
        'ckeditor.vision.noImage.title',
        'ckeditor.vision.noImage.message',
        'ckeditor.vision.analyzing.title',
        'ckeditor.vision.analyzing.message',
        'ckeditor.vision.done',
        'ckeditor.vision.failed',
        'ckeditor.translate.noText.title',
        'ckeditor.translate.noText.message',
        'ckeditor.translate.running.title',
        'ckeditor.translate.running.message',
        'ckeditor.translate.done.title',
        'ckeditor.translate.done.message',
        'ckeditor.translate.failed',
        'ckeditor.tasks.none.title',
        'ckeditor.tasks.none.message',
        'ckeditor.tasks.loadFailed',
        'ckeditor.tasks.loadFailedWithReason',
        'ckeditor.tasks.failed',
        'ckeditor.dialog.noTasks.description',
        'ckeditor.dialog.noTasks.steps',
        'ckeditor.dialog.noTasks.step1',
        'ckeditor.dialog.noTasks.step1.path',
        'ckeditor.dialog.noTasks.step2',
        'ckeditor.dialog.noTasks.step3',
        'ckeditor.dialog.noTasks.step3.active',
        'ckeditor.dialog.button.close',
        'ckeditor.dialog.button.cancel',
        'ckeditor.dialog.button.reset',
        'ckeditor.dialog.button.execute',
        'ckeditor.dialog.button.insert',
        'ckeditor.dialog.generating',
        'ckeditor.dialog.streamIncomplete',
        'ckeditor.dialog.model',
        'ckeditor.dialog.tokens',
        'ckeditor.dialog.noContent',
        'ckeditor.dialog.error',
        'ckeditor.dialog.task',
        'ckeditor.dialog.customInstruction',
        'ckeditor.dialog.customInstruction.description',
        'ckeditor.dialog.editTasks',
        'ckeditor.dialog.editTasks.title',
        'ckeditor.dialog.configuration',
        'ckeditor.dialog.configuration.task',
        'ckeditor.dialog.configuration.default',
        'ckeditor.dialog.configuration.defaultMarker',
        'ckeditor.dialog.audience',
        'ckeditor.dialog.tone',
        'ckeditor.dialog.length',
        'ckeditor.dialog.styleDefault',
        'ckeditor.dialog.length.muchShorter',
        'ckeditor.dialog.length.shorter',
        'ckeditor.dialog.length.unchanged',
        'ckeditor.dialog.length.longer',
        'ckeditor.dialog.length.muchLonger',
        'ckeditor.dialog.wordsTarget',
        'ckeditor.dialog.contextScope',
        'ckeditor.dialog.scope.selection',
        'ckeditor.dialog.scope.text',
        'ckeditor.dialog.scope.element',
        'ckeditor.dialog.scope.page',
        'ckeditor.dialog.scope.ancestors_1',
        'ckeditor.dialog.scope.ancestors_2',
        'ckeditor.dialog.referencePages',
        'ckeditor.dialog.addReference',
        'ckeditor.dialog.relationPreset.referenceMaterial',
        'ckeditor.dialog.relationPreset.parentTopic',
        'ckeditor.dialog.relationPreset.styleGuide',
        'ckeditor.dialog.relationPreset.similarContent',
        'ckeditor.dialog.instruction',
        'ckeditor.dialog.instruction.placeholder',
        'ckeditor.dialog.result',
        'ckeditor.dialog.pageSearch.placeholder',
        'ckeditor.dialog.pageSearch.label',
        'ckeditor.dialog.relation.placeholder',
        'ckeditor.dialog.relation.label',
        'ckeditor.dialog.removeReference',
        'ckeditor.dialog.noPagesFound',
        'ckeditor.dialog.openSetupStatus',
        'ckeditor.dialog.debug.summary',
        'ckeditor.dialog.debug.providerError',
        'ckeditor.dialog.debug.unknown',
        'ckeditor.dialog.debug.finishReason',
        'ckeditor.dialog.debug.tokens',
        'ckeditor.dialog.debug.inputText',
        'ckeditor.dialog.debug.instructionSent',
        'ckeditor.dialog.debug.thinking',
        'ckeditor.dialog.debug.contentReturned',
        'ckeditor.dialog.copy',
        'ckeditor.dialog.copied',
    ];

    public function __construct(
        private BackendUriBuilder $backendUriBuilder,
        private LoggerInterface $logger,
        private BackendLabels $labels = new BackendLabels(),
    ) {}

    public function __invoke(BeforeJavaScriptsRenderingEvent $event): void
    {
        // Only inject for inline context (backend pages)
        if (!$event->isInline()) {
            return;
        }

        try {
            // Add JSON data element (type="application/json" is not executed)
            $event->getAssetCollector()->addInlineJavaScript(
                'cowriter-ajax-urls-data',
                $this->buildJsonData(),
                ['type' => 'application/json', 'id' => 'cowriter-ajax-urls-data'],
                ['priority' => true],
            );

            $event->getAssetCollector()->addInlineJavaScript(
                'cowriter-labels-data',
                $this->buildLabelData(),
                ['type' => 'application/json', 'id' => 'cowriter-labels-data'],
                ['priority' => true],
            );

            // Load the URL loader module that reads from the JSON data
            $event->getAssetCollector()->addJavaScript(
                'cowriter-url-loader',
                'EXT:t3_cowriter/Resources/Public/JavaScript/Ckeditor/UrlLoader.js',
                ['type' => 'module'],
                ['priority' => true],
            );
        } catch (JsonException|RouteNotFoundException $e) {
            // Graceful degradation: page renders but cowriter plugin won't function.
            $this->logger->error('Cowriter: Failed to inject AJAX URLs', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build JSON data containing AJAX URLs.
     *
     * This is NOT executable JavaScript - it's JSON data that will be
     * parsed by the UrlLoader.js module.
     */
    private function buildJsonData(): string
    {
        $urls = [
            'tx_cowriter_chat' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('ajax_tx_cowriter_chat'),
            'tx_cowriter_complete' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('ajax_tx_cowriter_complete'),
            'tx_cowriter_stream' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('ajax_tx_cowriter_stream'),
            'tx_cowriter_configurations' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('ajax_tx_cowriter_configurations'),
            'tx_cowriter_style_options' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('ajax_tx_cowriter_style_options'),
            'tx_cowriter_tasks' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('ajax_tx_cowriter_tasks'),
            'tx_cowriter_task_execute' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('ajax_tx_cowriter_task_execute'),
            'tx_cowriter_task_stream' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('ajax_tx_cowriter_task_stream'),
            'tx_cowriter_context' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('ajax_tx_cowriter_context'),
            'tx_cowriter_vision' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('ajax_tx_cowriter_vision'),
            'tx_cowriter_translate' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('ajax_tx_cowriter_translate'),
            'tx_cowriter_templates' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('ajax_tx_cowriter_templates'),
            'tx_cowriter_tools' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('ajax_tx_cowriter_tools'),
            'tx_cowriter_page_search' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('ajax_tx_cowriter_page_search'),
            'nrllm_tasks_module' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('nrllm_tasks'),
            'nrllm_module' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('nrllm_overview'),
            'cowriter_status' => (string) $this->backendUriBuilder
                ->buildUriFromRoute('cowriter_status'),
        ];

        return json_encode($urls, JSON_THROW_ON_ERROR);
    }

    /**
     * The plugin's labels in the backend user's language, keyed by xlf id.
     * JSON_HEX_TAG keeps a "</script>" in a label from closing the element.
     */
    private function buildLabelData(): string
    {
        $languageService = $this->labels->languageService();
        $labels          = [];
        foreach (self::LABEL_KEYS as $key) {
            $labels[$key] = $languageService->sL(BackendLabels::LOCALLANG_BE . $key);
        }

        return json_encode($labels, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}
