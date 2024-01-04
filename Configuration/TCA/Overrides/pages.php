<?php
defined('TYPO3') or die();

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::registerPageTSConfigFile(
    't3_cowriter',
    'Configuration/TsConfig/page.tsconfig',
    'EXT:t3_cowriter - Cowriter for TYPO3'
);
