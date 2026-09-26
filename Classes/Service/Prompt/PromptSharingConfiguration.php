<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Prompt;

use Netresearch\T3Cowriter\Service\CallerSource;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Extension setting `prompts.sharedNeedApproval` (ext_conf_template.txt).
 */
final readonly class PromptSharingConfiguration
{
    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * Whether a shared prompt reaches other editors only after an
     * administrator approved it. On by default, and on when the setting
     * cannot be read.
     */
    public function sharedNeedApproval(): bool
    {
        try {
            $value = $this->extensionConfiguration->get(CallerSource::EXTENSION, 'prompts/sharedNeedApproval');
        } catch (Throwable) {
            return true;
        }

        return !is_scalar($value) || (string) $value !== '0';
    }
}
