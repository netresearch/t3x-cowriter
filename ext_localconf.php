<?php

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

defined('TYPO3_MODE') or die('Access denied.');

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addPageTSConfig('<INCLUDE_TYPOSCRIPT: source="FILE:EXT:t3_cowriter/Configuration/TsConfig/page.tsconfig">');

if (empty($GLOBALS['TYPO3_CONF_VARS']['RTE']['Presets']['cowriter'])) {
    $GLOBALS['TYPO3_CONF_VARS']['RTE']['Presets']['cowriter'] = 'EXT:t3_cowriter/Configuration/RTE/Plugin.yaml';
}

