<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Form\FieldControl;

use Netresearch\T3Cowriter\Domain\DTO\FieldSuggestionRequest;
use Throwable;
use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * The "Suggest" button next to a text field. It only renders the button and
 * the data the JavaScript module needs to ask for suggestions; the server
 * re-checks every permission when the button is used.
 */
final class FieldSuggestionsControl extends AbstractNode
{
    public const JAVASCRIPT_MODULE = '@netresearch/t3_cowriter/FieldSuggestions';

    private const LABEL_PREFIX = 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_be.xlf:fieldSuggestions.';

    /**
     * Labels the JavaScript module shows, passed as data attributes.
     */
    private const JS_LABELS = ['heading', 'loading', 'loaded', 'loadedSingular', 'empty', 'error', 'inserted', 'close'];

    public function __construct(
        private readonly UriBuilder $uriBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        $renderData = is_array($this->data['renderData'] ?? null) ? $this->data['renderData'] : [];
        $options    = is_array($renderData['fieldControlOptions'] ?? null) ? $renderData['fieldControlOptions'] : [];
        $count      = is_numeric($options['count'] ?? null) ? (int) $options['count'] : FieldSuggestionRequest::DEFAULT_COUNT;

        try {
            $url = (string) $this->uriBuilder->buildUriFromRoute('ajax_tx_cowriter_suggestions');
        } catch (Throwable) {
            // Without the route there is nothing the button could call.
            return [];
        }

        $databaseRow    = is_array($this->data['databaseRow'] ?? null) ? $this->data['databaseRow'] : [];
        $parameterArray = is_array($this->data['parameterArray'] ?? null) ? $this->data['parameterArray'] : [];
        $rawUid         = $databaseRow['uid'] ?? 0;
        $id             = StringUtility::getUniqueId('t3js-cowriter-suggestions-');

        $linkAttributes = [
            'id'             => $id,
            'role'           => 'button',
            'aria-expanded'  => 'false',
            'aria-controls'  => $id . '-panel',
            'data-url'       => $url,
            'data-item-name' => $this->stringValue($parameterArray['itemFormElName'] ?? ''),
            'data-table'     => $this->stringValue($this->data['tableName'] ?? ''),
            'data-field'     => $this->stringValue($this->data['fieldName'] ?? ''),
            // A new record carries "NEW…" here; the server treats it as uid 0.
            'data-uid'   => is_numeric($rawUid) ? (string) (int) $rawUid : '0',
            'data-pid'   => (string) (is_numeric($this->data['effectivePid'] ?? null) ? (int) $this->data['effectivePid'] : 0),
            'data-count' => (string) max(FieldSuggestionRequest::MIN_COUNT, min(FieldSuggestionRequest::MAX_COUNT, $count)),
        ];

        $languageService = $this->getLanguageService();
        foreach (self::JS_LABELS as $label) {
            // data-label-loaded-singular reaches the module as dataset.labelLoadedSingular.
            $attribute                  = 'data-label-' . strtolower((string) preg_replace('/[A-Z]/', '-$0', $label));
            $linkAttributes[$attribute] = $languageService->sL(self::LABEL_PREFIX . $label);
        }

        return [
            'iconIdentifier'    => 'actions-lightbulb-on',
            'title'             => self::LABEL_PREFIX . 'button',
            'linkAttributes'    => $linkAttributes,
            'javaScriptModules' => [
                JavaScriptModuleInstruction::create(self::JAVASCRIPT_MODULE)->instance($id),
            ],
        ];
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function getLanguageService(): LanguageService
    {
        /** @var LanguageService $languageService */
        $languageService = $GLOBALS['LANG'];

        return $languageService;
    }
}
