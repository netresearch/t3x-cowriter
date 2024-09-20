<?php

/**
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use Netresearch\T3Cowriter\Service\ProgressService;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

call_user_func(static function () {
    // Add TypoScript automatically (to use it in backend modules)
    ExtensionManagementUtility::addTypoScript(
        't3_cowriter',
        'setup',
        '@import "EXT:t3_cowriter/Configuration/TypoScript/setup.typoscript"'
    );
});

$config = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('t3_cowriter');
$js     = 'globalThis._cowriterConfig = ' . json_encode($config) . ';';

$GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['security.backend.enforceContentSecurityPolicy'] = false;
$GLOBALS['TYPO3_CONF_VARS']['RTE']['Presets']['cowriter']
    = 'EXT:t3_cowriter/Configuration/RTE/Pluginv12.yaml';

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_pagerenderer.php']['render-preProcess'][] = function ($parameters, $pagerenderer) use ($js) {
    /** @var AssetCollector $assetCollector */
    $assetCollector = GeneralUtility::makeInstance(AssetCollector::class);

    // This sucks.
    $assetCollector->addInlineJavaScript('cowriter_config', $js);
};

$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][ProgressService::CACHE_IDENTIFIER] ??= [
    'frontend' => VariableFrontend::class,
    'backend'  => Typo3DatabaseBackend::class,
    'groups'   => ['system'],
    'options'  => [
        'defaultLifetime' => 600,
    ],
];
