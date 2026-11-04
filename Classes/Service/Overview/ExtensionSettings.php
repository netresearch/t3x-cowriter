<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Overview;

use Netresearch\T3Cowriter\Service\CallerSource;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Every setting ext_conf_template.txt declares, with the value TYPO3 hands
 * the extension and whether it differs from the template's default.
 *
 * @internal
 */
final readonly class ExtensionSettings
{
    public const TEMPLATE = __DIR__ . '/../../../ext_conf_template.txt';

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
        private string $template = self::TEMPLATE,
    ) {}

    /**
     * @return list<ExtensionSetting>
     */
    public function all(): array
    {
        $settings = [];
        foreach ($this->declared() as $key => [$type, $default]) {
            $settings[] = new ExtensionSetting($key, $type, $default, $this->value($key, $default));
        }

        return $settings;
    }

    /**
     * @return array<string, array{string, string}> key => [type, default]
     */
    private function declared(): array
    {
        $lines = file($this->template, FILE_IGNORE_NEW_LINES);
        $lines = $lines !== false ? $lines : [];

        $declared = [];
        $type     = 'string';
        foreach ($lines as $line) {
            if (preg_match('/^#\s*cat=.*?type=([^;]+);/', $line, $match) === 1) {
                $type = trim($match[1]);

                continue;
            }

            if (preg_match('/^([A-Za-z0-9_.]+)\s*=\s*(.*)$/', $line, $match) === 1) {
                $declared[$match[1]] = [$type, trim($match[2])];
                $type                = 'string';
            }
        }

        return $declared;
    }

    private function value(string $key, string $default): string
    {
        try {
            $value = $this->extensionConfiguration->get(CallerSource::EXTENSION, str_replace('.', '/', $key));
        } catch (Throwable) {
            return $default;
        }

        return is_scalar($value) ? trim((string) $value) : $default;
    }
}
