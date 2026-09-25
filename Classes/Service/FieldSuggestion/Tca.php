<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

/**
 * Typed reads from a TCA array (by default $GLOBALS['TCA']).
 */
final class Tca
{
    /**
     * @param array<array-key, mixed>|null $tca defaults to $GLOBALS['TCA']
     *
     * @return array<array-key, mixed>|null
     */
    public static function column(string $table, string $field, ?array $tca = null): ?array
    {
        $columns = self::section($table, 'columns', $tca);
        $column  = $columns[$field] ?? null;

        return is_array($column) ? $column : null;
    }

    /**
     * @param array<array-key, mixed> $column
     *
     * @return array<array-key, mixed>
     */
    public static function config(array $column): array
    {
        return is_array($column['config'] ?? null) ? $column['config'] : [];
    }

    public static function hasTable(string $table): bool
    {
        return is_array(self::all()[$table] ?? null);
    }

    public static function languageField(string $table): ?string
    {
        $field = self::section($table, 'ctrl')['languageField'] ?? null;

        return is_string($field) && $field !== '' ? $field : null;
    }

    public static function transOrigPointerField(string $table): ?string
    {
        $field = self::section($table, 'ctrl')['transOrigPointerField'] ?? null;

        return is_string($field) && $field !== '' ? $field : null;
    }

    /**
     * @param array<array-key, mixed>|null $tca
     *
     * @return array<array-key, mixed>
     */
    private static function section(string $table, string $section, ?array $tca = null): array
    {
        $tableTca = ($tca ?? self::all())[$table] ?? null;
        if (!is_array($tableTca) || !is_array($tableTca[$section] ?? null)) {
            return [];
        }

        return $tableTca[$section];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function all(): array
    {
        $tca = $GLOBALS['TCA'] ?? null;

        return is_array($tca) ? $tca : [];
    }
}
