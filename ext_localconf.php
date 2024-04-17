<?php
// vim: ts=4 sw=4 expandtab

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Page\AssetCollector;

$config = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('t3_cowriter');
$js = 'globalThis._cowriterConfig = ' . json_encode($config) . ';';

$GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['security.backend.enforceContentSecurityPolicy'] = false;
$GLOBALS['TYPO3_CONF_VARS']['RTE']['Presets']['cowriter']
    = 'EXT:t3_cowriter/Configuration/RTE/Pluginv12.yaml';

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_pagerenderer.php']['render-preProcess'][] = function($parameters, $pagerenderer) use ($js) {
    /** @var AssetCollector $assetCollector */
    $assetCollector = GeneralUtility::makeInstance(AssetCollector::class);

    // This sucks.
    $assetCollector->addInlineJavaScript('cowriter_config', $js);
};