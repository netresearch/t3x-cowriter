<?php

$GLOBALS['TYPO3_CONF_VARS']['BE']['stylesheets']['t3_cowriter']
    = 'EXT:t3_cowriter/Resources/Public/Css/editor.css';

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
