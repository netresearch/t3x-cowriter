<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Form\FieldControl;

use Netresearch\T3Cowriter\Service\Presentation\Layout;
use Netresearch\T3Cowriter\Service\Presentation\PresentationConfiguration;
use Netresearch\T3Cowriter\Service\Presentation\PresentationElementReader;
use Throwable;
use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * The "Present as tabs or accordion" button next to the rich text of a saved
 * text element. Rendered only when the setting is on and the editor may
 * create at least one of the layouts in place of this element — the same
 * check the routes repeat on every request.
 */
final class PresentationControl extends AbstractNode
{
    public const JAVASCRIPT_MODULE = '@netresearch/t3_cowriter/AlternativePresentation';

    private const LABEL_PREFIX = 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_be.xlf:presentation.';

    /**
     * Labels the JavaScript module shows, passed as data attributes.
     */
    private const JS_LABELS = [
        'heading', 'loading', 'proposedHeadings', 'proposedModel', 'layout', 'layoutTab', 'layoutAccordion',
        'sectionTitle', 'noText', 'original', 'create', 'creating', 'created', 'open', 'cancel', 'error',
        'titleRequired', 'switch', 'switching', 'switched', 'openPage', 'imagesTaken',
        'warning.translations', 'warning.connectedTranslation', 'warning.imagesNotTaken',
    ];

    public function __construct(
        private readonly UriBuilder $uriBuilder,
        private readonly PresentationConfiguration $configuration,
        private readonly PresentationElementReader $elementReader,
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
            $element    = $this->elementReader->read((int) $rawUid, $backendUser);
            $proposeUrl = (string) $this->uriBuilder->buildUriFromRoute('ajax_tx_cowriter_presentation_propose');
            $createUrl  = (string) $this->uriBuilder->buildUriFromRoute('ajax_tx_cowriter_presentation_create');
            $switchUrl  = (string) $this->uriBuilder->buildUriFromRoute('ajax_tx_cowriter_presentation_switch');
        } catch (Throwable) {
            // A refusal (PresentationException), or no route the button could call.
            return [];
        }

        $id             = StringUtility::getUniqueId('t3js-cowriter-presentation-');
        $linkAttributes = [
            'id'               => $id,
            'role'             => 'button',
            'aria-expanded'    => 'false',
            'aria-controls'    => $id . '-panel',
            'data-propose-url' => $proposeUrl,
            'data-create-url'  => $createUrl,
            'data-switch-url'  => $switchUrl,
            'data-uid'         => (string) $element->uid,
            'data-layouts'     => implode(',', array_map(static fn (Layout $layout): string => $layout->value, $element->layouts)),
        ];

        $languageService = $this->getLanguageService();
        foreach (self::JS_LABELS as $label) {
            // data-label-layout-tab reaches the module as dataset.labelLayoutTab.
            $attribute                  = 'data-label-' . strtolower((string) preg_replace('/[A-Z]/', '-$0', str_replace('.', '-', $label)));
            $linkAttributes[$attribute] = $languageService->sL(self::LABEL_PREFIX . $label);
        }

        return [
            'iconIdentifier'    => 'content-tab',
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
