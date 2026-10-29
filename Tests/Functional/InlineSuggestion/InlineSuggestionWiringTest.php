<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Functional\InlineSuggestion;

use Netresearch\T3Cowriter\Controller\AjaxController;
use Netresearch\T3Cowriter\Controller\InlineSuggestionController;
use Netresearch\T3Cowriter\EventListener\InlineSuggestionEditorListener;
use Netresearch\T3Cowriter\Service\InlineSuggestion\InlineSuggestionConfiguration;
use Netresearch\T3Cowriter\Service\RateLimiterService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionProperty;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\RteCKEditor\Form\Element\Event\AfterPrepareConfigurationForEditorEvent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Inline suggestions as the container builds them: the route, the
 * controller with a rate limiter of its own, the settings from the extension
 * configuration and the editor listener on TYPO3's event.
 */
#[CoversClass(InlineSuggestionConfiguration::class)]
#[CoversClass(InlineSuggestionEditorListener::class)]
final class InlineSuggestionWiringTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'netresearch/t3-cowriter',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            't3_cowriter' => [
                'inlineSuggestions' => [
                    'enable'        => '1',
                    'delay'         => '1500',
                    'minChars'      => '40',
                    'configuration' => 'fast',
                ],
            ],
        ],
    ];

    #[Test]
    public function theRouteAcceptsOnlyPost(): void
    {
        $route = $this->get(Router::class)->getRoute('ajax_tx_cowriter_inline_suggestion');

        self::assertNotNull($route);
        self::assertSame('/ajax/cowriter/inline-suggestion', $route->getPath());
        self::assertSame(['POST'], $route->getMethods());
        self::assertSame(InlineSuggestionController::class . '::suggestAction', $route->getOption('target'));
    }

    #[Test]
    public function theControllerHasARateLimiterOfItsOwn(): void
    {
        $inlineLimiter = (new ReflectionProperty(InlineSuggestionController::class, 'rateLimiter'))
            ->getValue($this->get(InlineSuggestionController::class));
        $sharedLimiter = (new ReflectionProperty(AjaxController::class, 'rateLimiter'))
            ->getValue($this->get(AjaxController::class));

        self::assertInstanceOf(RateLimiterService::class, $inlineLimiter);
        self::assertNotSame($sharedLimiter, $inlineLimiter);
        self::assertSame(12, (new ReflectionProperty(RateLimiterService::class, 'requestsPerMinute'))->getValue($inlineLimiter));
        self::assertSame(20, (new ReflectionProperty(RateLimiterService::class, 'requestsPerMinute'))->getValue($sharedLimiter));
    }

    #[Test]
    public function theControllerReadsTheSettings(): void
    {
        $configuration = (new ReflectionProperty(InlineSuggestionController::class, 'suggestionConfiguration'))
            ->getValue($this->get(InlineSuggestionController::class));

        self::assertInstanceOf(InlineSuggestionConfiguration::class, $configuration);
        self::assertTrue($configuration->isEnabled());
        self::assertSame(1500, $configuration->delay());
        self::assertSame(40, $configuration->minChars());
        self::assertSame('fast', $configuration->configuration());
    }

    #[Test]
    public function theEditorGetsTheSettingsThroughTheEvent(): void
    {
        $event = new AfterPrepareConfigurationForEditorEvent(['toolbar' => ['items' => ['cowriterInlineSuggestions']]], []);

        $this->get(EventDispatcherInterface::class)->dispatch($event);

        $configuration = $event->getConfiguration();
        self::assertSame(['items' => ['cowriterInlineSuggestions']], $configuration['toolbar']);
        self::assertIsArray($configuration['cowriter']);
        self::assertSame(
            ['enabled' => true, 'delay' => 1500, 'minChars' => 40, 'userId' => 0],
            $configuration['cowriter']['inlineSuggestions'],
        );
    }
}
