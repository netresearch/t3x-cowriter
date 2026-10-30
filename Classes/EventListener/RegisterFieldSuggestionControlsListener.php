<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\EventListener;

use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionConfiguration;
use Netresearch\T3Cowriter\Service\FieldSuggestion\Tca;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Configuration\Event\AfterTcaCompilationEvent;

/**
 * Adds the "Suggest" field control to the configured fields.
 *
 * Runs after the complete TCA is compiled, so a field added by another
 * extension (pages.seo_title from EXT:seo) is seen regardless of the loading
 * order, and a configured field that does not exist is simply skipped.
 */
#[AsEventListener(identifier: 'cowriter-register-field-suggestion-controls')]
final readonly class RegisterFieldSuggestionControlsListener
{
    /**
     * Key of the control in `config.fieldControl`.
     */
    public const CONTROL_NAME = 'cowriterSuggestions';

    /**
     * FormEngine node name (renderType) of the control.
     */
    public const NODE_NAME = 'cowriterFieldSuggestions';

    /**
     * Field types that hold plain text the model can suggest.
     */
    private const SUPPORTED_TYPES = ['input', 'text', 'slug'];

    /**
     * eval rules of identifiers, numbers and codes (pages.cache_tags has
     * `is_in`), not of prose.
     */
    private const NON_PROSE_EVALS = [
        'alpha', 'alphanum', 'alphanum_x', 'domainname', 'double2', 'int', 'is_in',
        'lower', 'md5', 'nospace', 'num', 'password', 'saltedPassword', 'upper',
    ];

    public function __construct(
        private FieldSuggestionConfiguration $configuration,
    ) {}

    public function __invoke(AfterTcaCompilationEvent $event): void
    {
        $event->setTca($this->register($event->getTca(), $this->configuration->fields(), $this->configuration->count()));
    }

    /**
     * @param array<array-key, mixed>           $tca
     * @param list<array{0: string, 1: string}> $fields
     *
     * @return array<array-key, mixed>
     */
    public function register(array $tca, array $fields, int $count): array
    {
        foreach ($fields as [$table, $field]) {
            $column = Tca::column($table, $field, $tca);
            /** @var array<array-key, mixed> $tableTca Tca::column() found the table when it found a column */
            $tableTca = $column === null ? [] : $tca[$table];
            if ($column === null || !$this->isSupported($tableTca, $field, $column)) {
                continue;
            }

            $config       = Tca::config($column);
            $fieldControl = is_array($config['fieldControl'] ?? null) ? $config['fieldControl'] : [];

            // A control an integrator configured himself stays untouched.
            if (isset($fieldControl[self::CONTROL_NAME])) {
                continue;
            }

            $fieldControl[self::CONTROL_NAME] = [
                'renderType' => self::NODE_NAME,
                'options'    => ['count' => $count],
            ];
            $config['fieldControl'] = $fieldControl;
            $column['config']       = $config;

            /** @var array<array-key, mixed> $columns */
            $columns             = $tableTca['columns'];
            $columns[$field]     = $column;
            $tableTca['columns'] = $columns;
            $tca[$table]         = $tableTca;
        }

        return $tca;
    }

    /**
     * Whether the column holds free text a suggestion makes sense for.
     *
     * Rich text fields have the cowriter toolbar in CKEditor instead — also
     * when only a record type switches the editor on (tt_content.bodytext is
     * plain text in its column and rich text through columnsOverrides). A
     * renderType turns a text field into something else (code editor, table
     * wizard), and an eval rule, a value picker or readOnly mark values that
     * are identifiers, numbers or choices rather than prose.
     *
     * @param array<array-key, mixed> $tableTca
     * @param array<array-key, mixed> $column
     */
    public function isSupported(array $tableTca, string $field, array $column): bool
    {
        $config = Tca::config($column);
        if (!in_array($config['type'] ?? null, self::SUPPORTED_TYPES, true)) {
            return false;
        }

        if ((bool) ($config['enableRichtext'] ?? false) || $this->hasOtherRenderType($config)
            || $this->anyTypeTurnsItIntoNonProse($tableTca, $field)
        ) {
            return false;
        }

        if ((bool) ($config['readOnly'] ?? false) || isset($config['valuePicker'])) {
            return false;
        }

        $evals = is_string($config['eval'] ?? null) ? array_map(trim(...), explode(',', $config['eval'])) : [];

        return array_intersect($evals, self::NON_PROSE_EVALS) === [];
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private function hasOtherRenderType(array $config): bool
    {
        $renderType = $config['renderType'] ?? '';

        return $renderType !== '' && $renderType !== 'default';
    }

    /**
     * Whether a record type's columnsOverrides switch the field to rich text
     * or to another renderType. The control is added to the column, so it
     * would show in that type too.
     *
     * A readOnly override needs no check here: FormEngine merges the
     * overrides before rendering, and core's input, text and slug elements
     * render no field control for a read-only field.
     *
     * @param array<array-key, mixed> $tableTca
     */
    private function anyTypeTurnsItIntoNonProse(array $tableTca, string $field): bool
    {
        $types = is_array($tableTca['types'] ?? null) ? $tableTca['types'] : [];
        foreach ($types as $type) {
            $overrides = is_array($type) && is_array($type['columnsOverrides'] ?? null) ? $type['columnsOverrides'] : [];
            $override  = Tca::config(is_array($overrides[$field] ?? null) ? $overrides[$field] : []);
            if ((bool) ($override['enableRichtext'] ?? false) || $this->hasOtherRenderType($override)) {
                return true;
            }
        }

        return false;
    }
}
