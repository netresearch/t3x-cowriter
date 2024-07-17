<?php

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || exit('Access denied.');

call_user_func(function (): void {
    $extensionKey = 't3_cowriter';

    ExtensionManagementUtility::addStaticFile(
        $extensionKey,
        'Configuration/TypoScript/',
        'CKEditor plugin: cowriter'
    );
});
