<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\EventListener;

use Netresearch\T3Cowriter\Service\BackendLabels;
use Netresearch\T3Cowriter\Service\Image\GeneratedImageStorageInterface;
use Netresearch\T3Cowriter\Service\Image\ImageGenerationConfiguration;
use TYPO3\CMS\Backend\Form\Event\CustomFileControlsEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Adds a "Generate image" control to FAL file fields (TCA type "file") in
 * FormEngine. The control asks for a description, calls the same route as the
 * rich text editor (tx_cowriter_image_generate) and hands the stored file to
 * the field the way core's "Add media by URL" does, so the field gets a new
 * file reference as if the editor had selected the file.
 *
 * {@see CustomFileControlsEvent} is the hook TYPO3 13.4 and 14.3 both offer;
 * its controls render below the file references. The control is left out
 * while image generation is switched off, when the editor has no folder the
 * route could store the image in, when the field is read-only, does not show
 * its add controls (appearance.showFileSelectors / fileUploadAllowed) or
 * does not accept PNG, the format the route gets from the model. A field
 * that is full gets the control hidden; the JavaScript shows it again when a
 * reference is removed, as core does with its own add controls.
 *
 * The route keeps all its checks; this listener only decides what to show.
 */
#[AsEventListener(identifier: 'cowriter-add-image-generation-file-control')]
final readonly class AddImageGenerationFileControlListener
{
    public const JAVASCRIPT_MODULE = '@netresearch/t3_cowriter/ImageFileControl';

    /**
     * Class the JavaScript module finds the control by.
     */
    public const CONTROL_CLASS = 't3js-cowriter-generate-image';

    /**
     * File extensions the route produces. The storage would accept JPEG and
     * WebP as well, but the route cannot ask for them: nr-llm 0.41's
     * ImageGenerationOptions has no output format, and OpenAI's image models
     * answer with PNG by default. A field that takes only JPEG would get a
     * paid image it cannot keep.
     */
    public const GENERATED_EXTENSIONS = ['png'];

    private const FILE_REFERENCE_TABLE = 'sys_file_reference';

    private const LABEL_PREFIX = 'fileControl.image.';

    /**
     * Texts the JavaScript module shows, passed as data-label-* attributes
     * (the module's name => the locallang_be.xlf id after LABEL_PREFIX).
     */
    public const JS_LABELS = [
        'promptHelp'  => 'dialog.promptHelp',
        'generating'  => 'dialog.generating',
        'fullTitle'   => 'full.title',
        'fullMessage' => 'full.message',
        'notAccepted' => 'notAccepted',
        'notAdded'    => 'notAdded',
        'doneTitle'   => 'done.title',
        'doneMessage' => 'done.message',
    ];

    public function __construct(
        private ImageGenerationConfiguration $imageConfiguration,
        private GeneratedImageStorageInterface $imageStorage,
        private IconFactory $iconFactory,
        private BackendLabels $labels = new BackendLabels(),
    ) {}

    public function __invoke(CustomFileControlsEvent $event): void
    {
        if (!$this->imageConfiguration->isEnabled()) {
            return;
        }

        $config = $event->getFieldConfig();
        if ((bool) ($config['readOnly'] ?? false)) {
            return;
        }

        // Read as core's FilesControlContainer reads them: showFileSelectors
        // only off when false, fileUploadAllowed cast to bool, so 0 and '0'
        // (from TSconfig) switch uploads off.
        $appearance = is_array($config['appearance'] ?? null) ? $config['appearance'] : [];
        if (($appearance['showFileSelectors'] ?? true) === false || !(bool) ($appearance['fileUploadAllowed'] ?? true)) {
            return;
        }

        $extensions = self::acceptedExtensions($config['allowed'] ?? null, $config['disallowed'] ?? null);
        if ($extensions === []) {
            return;
        }

        // The folder the route stores in, checked the same way; without one
        // the route refuses, so there is nothing to offer.
        if (!$this->imageStorage->targetFolder() instanceof Folder) {
            return;
        }

        $event->addControl($this->renderControl($event, $config, $extensions), 'cowriterGenerateImage');

        $resultArray                      = $event->getResultArray();
        $modules                          = is_array($resultArray['javaScriptModules'] ?? null) ? $resultArray['javaScriptModules'] : [];
        $modules[]                        = JavaScriptModuleInstruction::create(self::JAVASCRIPT_MODULE);
        $resultArray['javaScriptModules'] = $modules;
        $event->setResultArray($resultArray);
    }

    /**
     * Which of the extensions the route can store the field accepts, by the
     * field's "allowed" and "disallowed" lists (comma-separated, as TCA
     * preparation leaves them). An empty "allowed" list allows everything,
     * as in core's FileExtensionFilter.
     *
     * @return list<string>
     */
    public static function acceptedExtensions(mixed $allowed, mixed $disallowed): array
    {
        $allowedList    = self::extensionList($allowed);
        $disallowedList = self::extensionList($disallowed);

        return array_values(array_filter(
            self::GENERATED_EXTENSIONS,
            static fn (string $extension): bool => ($allowedList === [] || in_array($extension, $allowedList, true))
                && !in_array($extension, $disallowedList, true),
        ));
    }

    /**
     * A uid or pid as a string, '0' for anything that is none ("NEW…" ids of
     * unsaved records).
     */
    private function positiveNumber(mixed $value): string
    {
        return is_numeric($value) && (int) $value > 0 ? (string) (int) $value : '0';
    }

    /**
     * @return list<string>
     */
    private static function extensionList(mixed $value): array
    {
        if (is_array($value)) {
            $value = implode(',', array_filter($value, is_scalar(...)));
        }

        if (!is_string($value)) {
            return [];
        }

        return GeneralUtility::trimExplode(',', strtolower($value), true);
    }

    /**
     * @param array<array-key, mixed> $config
     * @param list<string>            $extensions
     */
    private function renderControl(CustomFileControlsEvent $event, array $config, array $extensions): string
    {
        $label    = $this->labels->get(self::LABEL_PREFIX . 'button');
        $row      = $event->getDatabaseRow();
        $inline   = is_array($config['inline'] ?? null) ? $config['inline'] : [];
        $maxItems = is_numeric($config['maxitems'] ?? null) ? (int) $config['maxitems'] : 0;

        $attributes = [
            'type'                    => 'button',
            'class'                   => 'btn btn-default ' . self::CONTROL_CLASS,
            'title'                   => $label,
            'data-file-irre-object'   => $event->getFormFieldIdentifier() . '-' . self::FILE_REFERENCE_TABLE,
            'data-form-field-id'      => $event->getFormFieldIdentifier(),
            'data-form-field'         => $event->getFormFieldName(),
            'data-max-items'          => (string) $maxItems,
            'data-allowed-extensions' => implode(',', $extensions),
            // The record the image is for: the dialog asks tx_cowriter_image_context
            // what it is for and proposes a description. A new record has no uid yet.
            'data-table' => $event->getTableName(),
            'data-field' => $event->getFieldName(),
            'data-uid'   => $this->positiveNumber($row['uid'] ?? null),
            'data-pid'   => $this->positiveNumber($row['pid'] ?? null),
        ];
        // Core sets this flag to false when the field is full (and when it is
        // read-only, which never gets here).
        if (($inline['showCreateNewRelationButton'] ?? true) === false) {
            $attributes['hidden'] = 'hidden';
        }

        foreach (self::JS_LABELS as $name => $id) {
            // data-label-prompt-help reaches the module as dataset.labelPromptHelp.
            $attributes['data-label-' . strtolower((string) preg_replace('/[A-Z]/', '-$0', $name))] = $this->labels->get(self::LABEL_PREFIX . $id);
        }

        return '<button ' . GeneralUtility::implodeAttributes($attributes, true) . '>'
            . $this->iconFactory->getIcon('actions-image', IconSize::SMALL)->render()
            . ' ' . htmlspecialchars($label)
            . '</button>';
    }
}
