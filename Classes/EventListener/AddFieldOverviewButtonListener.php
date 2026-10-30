<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\EventListener;

use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Adds "Suggest values for all fields" to the document header of the record
 * form, the place TYPO3 keeps record-level actions (save, close, view).
 *
 * The server cannot know which fields the form will show: that depends on
 * the record type, the user's field permissions and read-only settings, and
 * is decided while FormEngine renders. So the button is rendered hidden
 * whenever the edited table has any field with the suggestion control, and
 * the FieldSuggestions module, which loads only when FormEngine renders at
 * least one such control, reveals it. Without a control in the form the
 * button stays hidden and inert.
 *
 * Only for a form with a single record: with several records in one form a
 * single button could not say which record it works on.
 */
#[AsEventListener(identifier: 'cowriter-add-field-overview-button')]
final readonly class AddFieldOverviewButtonListener
{
    public const BUTTON_CLASS = 't3js-cowriter-suggest-all';

    private const LABEL_PREFIX = 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_be.xlf:fieldOverview.';

    /**
     * Labels the JavaScript module shows, passed as data attributes.
     */
    private const JS_LABELS = [
        'title', 'loading', 'loaded', 'error', 'empty', 'caption', 'columnApply', 'columnField', 'columnCurrent',
        'columnSuggestion', 'columnReason', 'keep', 'emptyValue', 'apply', 'cancel', 'applied', 'appliedSingular',
        'nothingSelected', 'appliedTitle', 'applyRow', 'suggestionFor',
    ];

    public function __construct(
        private UriBuilder $uriBuilder,
        private IconFactory $iconFactory,
    ) {}

    public function __invoke(ModifyButtonBarEvent $event): void
    {
        // ModifyButtonBarEvent::getRequest() exists since TYPO3 14.
        // @phpstan-ignore function.alreadyNarrowedType, function.impossibleType (always true against v14, always false against v13.4)
        $request = method_exists($event, 'getRequest') ? $event->getRequest() : ($GLOBALS['TYPO3_REQUEST'] ?? null);
        if (!$request instanceof ServerRequestInterface) {
            return;
        }

        $table = $this->editedTable($request);
        if ($table === null || !$this->hasSuggestionField($table)) {
            return;
        }

        try {
            $url = (string) $this->uriBuilder->buildUriFromRoute('ajax_tx_cowriter_suggestions_all');
        } catch (Throwable) {
            return;
        }

        $buttons                                        = $event->getButtons();
        $buttons[ButtonBar::BUTTON_POSITION_LEFT][90][] = $this->button($table, $url);
        $event->setButtons($buttons);
    }

    /**
     * The table of the single record the form edits, if the request is the
     * record form and edits exactly one record.
     */
    public function editedTable(ServerRequestInterface $request): ?string
    {
        // The backend router sets "route"; the instanceof check below narrows it.
        // @phpstan-ignore phpstanTypo3.requestAttributeValidation
        $route = $request->getAttribute('route');
        if (!$route instanceof Route || $route->getOption('_identifier') !== 'record_edit') {
            return null;
        }

        $parsedBody = $request->getParsedBody();
        $edit       = $request->getQueryParams()['edit'] ?? (is_array($parsedBody) ? ($parsedBody['edit'] ?? null) : null);
        if (!is_array($edit) || count($edit) !== 1) {
            return null;
        }

        $table   = array_key_first($edit);
        $records = $edit[$table];
        if (!is_string($table) || !is_array($records) || count($records) !== 1) {
            return null;
        }

        // "12,13" in one key edits two records.
        return str_contains((string) array_key_first($records), ',') ? null : $table;
    }

    private function hasSuggestionField(string $table): bool
    {
        foreach (Tca::columns($table) as $column) {
            $fieldControl = is_array($column) ? (Tca::config($column)['fieldControl'] ?? null) : null;
            if (is_array($fieldControl) && is_array($fieldControl[RegisterFieldSuggestionControlsListener::CONTROL_NAME] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function button(string $table, string $url): LinkButton
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        $label           = static fn (string $key): string => $languageService instanceof LanguageService
            ? $languageService->sL(self::LABEL_PREFIX . $key)
            : '';

        $dataAttributes = ['url' => $url, 'table' => $table];
        foreach (self::JS_LABELS as $key) {
            // data-label-column-apply reaches the module as dataset.labelColumnApply.
            $dataAttributes['label-' . strtolower((string) preg_replace('/[A-Z]/', '-$0', $key))] = $label($key);
        }

        $button = GeneralUtility::makeInstance(LinkButton::class);
        $button
            ->setHref('#')
            // A LinkButton without a title is not rendered.
            ->setTitle($label('button') !== '' ? $label('button') : 'Suggest values for all fields with AI')
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-lightbulb-on', IconSize::SMALL))
            // Hidden until the FieldSuggestions module finds a control in the form.
            ->setClasses(self::BUTTON_CLASS . ' d-none')
            ->setDataAttributes($dataAttributes);

        return $button;
    }
}
