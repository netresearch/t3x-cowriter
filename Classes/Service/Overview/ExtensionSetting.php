<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Overview;

/**
 * One key of ext_conf_template.txt with its effective value.
 *
 * @internal
 */
final readonly class ExtensionSetting
{
    public function __construct(
        public string $key,
        public string $type,
        public string $default,
        public string $value,
    ) {}

    /**
     * Not named isDefault(): Fluid would answer {setting.default} with it.
     */
    public function isUnchanged(): bool
    {
        return $this->value === $this->default;
    }

    public function isBoolean(): bool
    {
        return $this->type === 'boolean';
    }

    public function isOn(): bool
    {
        return $this->value === '1';
    }
}
