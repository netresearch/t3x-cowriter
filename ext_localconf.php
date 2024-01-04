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

defined('TYPO3') or die('Access denied.');


if (empty($GLOBALS['TYPO3_CONF_VARS']['RTE']['Presets']['cowriter'])) {
    $GLOBALS['TYPO3_CONF_VARS']['RTE']['Presets']['cowriter'] = 'EXT:t3_cowriter/Configuration/RTE/Plugin.yaml';
}


// Make the extension configuration accessible
$extensionConfiguration = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
    \TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class
);

if (TYPO3 === 'BE') {
    $config = $extensionConfiguration->get('t3_cowriter');
    $renderer = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Page\PageRenderer::class);
    $renderer->addJsInlineCode('cowriterkey', 'const OPENAI_KEY = "' . $config['openaiKey'] . '"', false, true);
    $renderer->addJsInlineCode('cowriterorg', 'const OPENAI_ORG = "' . $config['openaiOrg'] . '"', false, true);
}
