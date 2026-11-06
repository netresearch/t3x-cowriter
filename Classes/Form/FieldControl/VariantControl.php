<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Form\FieldControl;

use Netresearch\T3Cowriter\Service\Presentation\PresentationAccess;
use Netresearch\T3Cowriter\Service\Style\StyleInstructionBuilder;
use Netresearch\T3Cowriter\Service\Variant\VariantConfiguration;
use Netresearch\T3Cowriter\Service\Variant\VariantElementReader;
use Throwable;
use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * The "Create an audience variant" button next to the rich text of a saved
 * text element. Rendered only when the setting is on, the operator defined at
 * least one audience snippet, and the editor may create the variant — the
 * same check the routes repeat on every request.
 */
final class VariantControl extends AbstractNode
{
    public const JAVASCRIPT_MODULE = '@netresearch/t3_cowriter/AudienceVariant';

    private const LABEL_PREFIX = 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_be.xlf:variant.';

    /**
     * Labels the JavaScript module shows, passed as data attributes.
     */
    private const JS_LABELS = [
        'heading', 'audience', 'tone', 'toneNone', 'propose', 'loading', 'proposed', 'header', 'bodytext',
        'context', 'contextNone', 'original', 'create', 'creating', 'created', 'open', 'openPage', 'cancel',
        'error', 'retry', 'hideOriginal', 'show', 'showing', 'shown', 'shownOriginalVisible',
    ];

    public function __construct(
        private readonly UriBuilder $uriBuilder,
        private readonly VariantConfiguration $configuration,
        private readonly VariantElementReader $elementReader,
        private readonly StyleInstructionBuilder $styleInstructions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        $databaseRow = is_array($this->data['databaseRow'] ?? null) ? $this->data['databaseRow'] : [];
        $rawUid      = $databaseRow['uid'] ?? null;
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        // A new record has no saved text yet ("NEW…" uid).
        if (!$this->configuration->isEnabled() || !is_numeric($rawUid) || !$backendUser instanceof BackendUserAuthentication) {
            return [];
        }

        try {
            $options = $this->styleInstructions->options();
            if ($options['audiences'] === []) {
                return [];
            }

            $element    = $this->elementReader->read((int) $rawUid, $backendUser);
            $proposeUrl = (string) $this->uriBuilder->buildUriFromRoute('ajax_tx_cowriter_variant_propose');
            $createUrl  = (string) $this->uriBuilder->buildUriFromRoute('ajax_tx_cowriter_variant_create');
            $showUrl    = (string) $this->uriBuilder->buildUriFromRoute('ajax_tx_cowriter_variant_show');
        } catch (Throwable) {
            // A refusal (VariantException), no snippet table, or no route the button could call.
            return [];
        }

        $id             = StringUtility::getUniqueId('t3js-cowriter-variant-');
        $linkAttributes = [
            'id'                => $id,
            'role'              => 'button',
            'aria-expanded'     => 'false',
            'aria-controls'     => $id . '-panel',
            'data-propose-url'  => $proposeUrl,
            'data-create-url'   => $createUrl,
            'data-show-url'     => $showUrl,
            'data-uid'          => (string) $element->uid,
            'data-hidden-field' => (string) PresentationAccess::hiddenField(),
            'data-options'      => json_encode($options, JSON_THROW_ON_ERROR),
        ];

        $languageService = $this->getLanguageService();
        foreach (self::JS_LABELS as $label) {
            // data-label-tone-none reaches the module as dataset.labelToneNone.
            $attribute                  = 'data-label-' . strtolower((string) preg_replace('/[A-Z]/', '-$0', $label));
            $linkAttributes[$attribute] = $languageService->sL(self::LABEL_PREFIX . $label);
        }

        return [
            'iconIdentifier'    => 'actions-duplicate',
            'title'             => self::LABEL_PREFIX . 'button',
            'linkAttributes'    => $linkAttributes,
            'javaScriptModules' => [
                JavaScriptModuleInstruction::create(self::JAVASCRIPT_MODULE)->instance($id),
            ],
        ];
    }

    private function getLanguageService(): LanguageService
    {
        /** @var LanguageService $languageService */
        $languageService = $GLOBALS['LANG'];

        return $languageService;
    }
}
