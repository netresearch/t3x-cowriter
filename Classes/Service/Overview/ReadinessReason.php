<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Overview;

/**
 * Why an action has its state. Each case is a label
 * (locallang_mod_status.xlf, readiness.reason.<value>).
 *
 * @internal
 */
enum ReadinessReason: string
{
    case ConfigurationReady        = 'configurationReady';
    case ConfigurationDenied       = 'configurationDenied';
    case NoConfiguration           = 'noConfiguration';
    case ConfigurationNotFound     = 'configurationNotFound';
    case SiteConfigurationUnusable = 'siteConfigurationUnusable';
    case ModelChosenPerRequest     = 'modelChosenPerRequest';
    case ModelInactive             = 'modelInactive';
    case ModelLacksVision          = 'modelLacksVision';
    case ProviderMissing           = 'providerMissing';
    case ProviderInactive          = 'providerInactive';
    case ProviderKeyMissing        = 'providerKeyMissing';
    case AdapterUnknown            = 'adapterUnknown';
    case NoGenericDefault          = 'noGenericDefault';
    case TranslatorAvailable       = 'translatorAvailable';
    case SwitchedOff               = 'switchedOff';
    case NoSuggestionFields        = 'noSuggestionFields';
    case ImageKeyMissing           = 'imageKeyMissing';
    case NoUploadFolder            = 'noUploadFolder';
    case ImageReady                = 'imageReady';
    case BootstrapPackageMissing   = 'bootstrapPackageMissing';

    /**
     * The backend module whose records or settings fix this reason; null when
     * none does (or the reason needs no fix).
     */
    public function fixRoute(): ?string
    {
        return match ($this) {
            self::ConfigurationDenied, self::NoConfiguration, self::ConfigurationNotFound,
            self::SiteConfigurationUnusable, self::NoGenericDefault                => 'nrllm_configurations',
            self::ModelInactive, self::ModelLacksVision, self::ProviderMissing     => 'nrllm_models',
            self::ProviderInactive, self::ProviderKeyMissing, self::AdapterUnknown => 'nrllm_providers',
            self::SwitchedOff, self::NoSuggestionFields, self::ImageKeyMissing     => 'tools_toolssettings',
            default                                                                => null,
        };
    }
}
