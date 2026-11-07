<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\EventListener;

/**
 * Adds a field control to the bodytext of content types, in each type's
 * columnsOverrides, not onto the shared bodytext column, so no other content
 * type shows it. A control an integrator configured himself under the same
 * key stays untouched. Shared by the listeners of the tabs-or-accordion and
 * the audience variant control.
 *
 * @internal
 */
final class BodytextFieldControl
{
    /**
     * @param array<array-key, mixed> $tca    the compiled TCA
     * @param list<string>            $ctypes content types whose bodytext gets the control
     *
     * @return array<array-key, mixed>
     */
    public static function register(array $tca, array $ctypes, string $controlName, string $nodeName): array
    {
        $content = is_array($tca['tt_content'] ?? null) ? $tca['tt_content'] : null;
        if ($content === null) {
            return $tca;
        }

        $types = is_array($content['types'] ?? null) ? $content['types'] : [];
        foreach ($ctypes as $ctype) {
            if (!is_array($types[$ctype] ?? null)) {
                continue;
            }

            $type         = $types[$ctype];
            $overrides    = self::arrayAt($type, 'columnsOverrides');
            $bodytext     = self::arrayAt($overrides, 'bodytext');
            $config       = self::arrayAt($bodytext, 'config');
            $fieldControl = self::arrayAt($config, 'fieldControl');

            if (isset($fieldControl[$controlName])) {
                continue;
            }

            $fieldControl[$controlName] = ['renderType' => $nodeName];
            $config['fieldControl']     = $fieldControl;
            $bodytext['config']         = $config;
            $overrides['bodytext']      = $bodytext;
            $type['columnsOverrides']   = $overrides;
            $types[$ctype]              = $type;
        }

        $content['types']  = $types;
        $tca['tt_content'] = $content;

        return $tca;
    }

    /**
     * @param array<array-key, mixed> $array
     *
     * @return array<array-key, mixed>
     */
    private static function arrayAt(array $array, string $key): array
    {
        return is_array($array[$key] ?? null) ? $array[$key] : [];
    }
}
