<?php

/**
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceKeyword;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceScheme;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Type\Map;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$config = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('t3_cowriter');

return Map::fromEntries([
    Scope::backend(),
    new MutationCollection(
        new Mutation(
            MutationMode::Extend,
            Directive::ConnectSrc,
            SourceScheme::data,
            new UriValue($config['apiUrl'])
        ),
        // FIXME: Remove this, figure out a proper way to get around the CSP check
        new Mutation(
            MutationMode::Reduce,
            Directive::ScriptSrc,
            SourceKeyword::nonceProxy,
        ),
        new Mutation(
            MutationMode::Extend,
            Directive::ScriptSrc,
            SourceKeyword::unsafeInline,
        ),
    ),
]);
