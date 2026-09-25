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
            if ($column === null || !$this->isSupported($column)) {
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

            /** @var array<array-key, mixed> $tableTca Tca::column() found the table */
            $tableTca = $tca[$table];
            /** @var array<array-key, mixed> $columns */
            $columns             = $tableTca['columns'];
            $columns[$field]     = $column;
            $tableTca['columns'] = $columns;
            $tca[$table]         = $tableTca;
        }

        return $tca;
    }

    /**
     * @param array<array-key, mixed> $column
     */
    private function isSupported(array $column): bool
    {
        $config = Tca::config($column);
        if (!in_array($config['type'] ?? null, self::SUPPORTED_TYPES, true)) {
            return false;
        }

        // Rich text fields have the cowriter toolbar in CKEditor instead.
        return !(bool) ($config['enableRichtext'] ?? false);
    }
}
