<?php

/*
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

// vim: ts=4 sw=4 expandtab

declare(strict_types=1);

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/** @var array{apiUrl: string} $config */
$config = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('t3_cowriter');
$js     = 'globalThis._cowriterConfig = ' . json_encode($config) . ';';

$GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['security.backend.enforceContentSecurityPolicy'] = false;
$GLOBALS['TYPO3_CONF_VARS']['RTE']['Presets']['cowriter']
    = 'EXT:t3_cowriter/Configuration/RTE/Pluginv12.yaml';

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_pagerenderer.php']['render-preProcess'][] = function ($parameters, $pagerenderer) use ($js): void {
    /** @var AssetCollector $assetCollector */
    $assetCollector = GeneralUtility::makeInstance(AssetCollector::class);

    // This sucks.
    $assetCollector->addInlineJavaScript('cowriter_config', $js);
};
