<?php

/*
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceKeyword;
use TYPO3\CMS\Core\Type\Map;

/**
 * Content Security Policy configuration for t3_cowriter.
 *
 * Note: All LLM requests go through TYPO3 backend AJAX routes (not direct external API calls),
 * so no external connect-src is needed. The inline script allowance is required for the
 * InjectAjaxUrlsListener that injects AJAX URLs into the page.
 *
 * @see Netresearch\T3Cowriter\EventListener\InjectAjaxUrlsListener
 */
return Map::fromEntries([
    Scope::backend(),
    new MutationCollection(
        // Required for inline JavaScript configuration injection (AJAX URLs)
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
