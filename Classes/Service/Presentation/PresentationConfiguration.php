<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Presentation;

use Netresearch\T3Cowriter\Service\CallerSource;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Extension setting `presentation.enable` (ext_conf_template.txt): whether
 * editors may present a text element as tabs or as an accordion. Off by
 * default; a request never switches it.
 *
 * @internal
 */
final readonly class PresentationConfiguration
{
    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * Only the string '1' counts as on; an unreadable setting is off.
     */
    public function isEnabled(): bool
    {
        try {
            $value = $this->extensionConfiguration->get(CallerSource::EXTENSION, 'presentation/enable');
        } catch (Throwable) {
            return false;
        }

        return is_scalar($value) && (string) $value === '1';
    }
}
