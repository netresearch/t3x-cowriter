<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\EventListener;

use Netresearch\T3Cowriter\EventListener\InlineSuggestionEditorListener;
use Netresearch\T3Cowriter\Service\InlineSuggestion\InlineSuggestionConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\RteCKEditor\Form\Element\Event\AfterPrepareConfigurationForEditorEvent;

#[CoversClass(InlineSuggestionEditorListener::class)]
#[CoversClass(InlineSuggestionConfiguration::class)]
final class InlineSuggestionEditorListenerTest extends TestCase
{
    #[Test]
    public function handsTheSettingsToThePluginWhileSwitchedOn(): void
    {
        $configuration = $this->dispatch(
            ['toolbar' => ['items' => ['bold', 'cowriterInlineSuggestions']], 'cowriter' => ['translationLanguages' => [['code' => 'de']]]],
            ['enable' => '1', 'delay' => '1500', 'minChars' => '40'],
        );

        self::assertSame(['items' => ['bold', 'cowriterInlineSuggestions']], $configuration['toolbar']);
        self::assertSame([
            'translationLanguages' => [['code' => 'de']],
            'inlineSuggestions'    => ['enabled' => true, 'delay' => 1500, 'minChars' => 40, 'userId' => 7],
        ], $configuration['cowriter']);
    }

    #[Test]
    public function replacesACowriterKeyThatIsNoArray(): void
    {
        $configuration = $this->dispatch(['cowriter' => 'x'], ['enable' => '1']);

        self::assertSame(
            ['inlineSuggestions' => ['enabled' => true, 'delay' => 1200, 'minChars' => 30, 'userId' => 7]],
            $configuration['cowriter'],
        );
    }

    #[Test]
    public function whileSwitchedOffThePluginGetsNoSettingsAndTheToggleIsRemoved(): void
    {
        $configuration = $this->dispatch(
            ['toolbar' => ['items' => ['bold', 'cowriterInlineSuggestions', 'cowriterTranslate'], 'shouldNotGroupWhenFull' => true]],
            ['enable' => '0'],
        );

        self::assertSame(
            ['toolbar' => ['items' => ['bold', 'cowriterTranslate'], 'shouldNotGroupWhenFull' => true]],
            $configuration,
        );
    }

    #[Test]
    public function removesTheToggleFromAPlainListAndFromGroups(): void
    {
        self::assertSame(
            ['toolbar' => ['bold', '|', 'cowriterTranslate']],
            $this->dispatch(['toolbar' => ['bold', '|', 'cowriterInlineSuggestions', 'cowriterTranslate']], []),
        );
        self::assertSame(
            ['toolbar' => ['items' => ['bold', ['label' => 'AI', 'items' => ['cowriter']]]]],
            $this->dispatch(['toolbar' => ['items' => ['bold', ['label' => 'AI', 'items' => ['cowriter', 'cowriterInlineSuggestions']]]]], []),
        );
    }

    #[Test]
    public function leavesAConfigurationWithoutToolbarAlone(): void
    {
        self::assertSame(['removeItems' => ['cowriterInlineSuggestions']], $this->dispatch(['removeItems' => ['cowriterInlineSuggestions']], []));
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed> $settings
     *
     * @return array<mixed>
     */
    private function dispatch(array $configuration, array $settings): array
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnCallback(
            static fn (string $extension, string $path = ''): mixed => $settings[substr($path, strlen('inlineSuggestions/'))] ?? null,
        );
        $context = $this->createStub(Context::class);
        $context->method('getPropertyFromAspect')->willReturn(7);

        $event = new AfterPrepareConfigurationForEditorEvent($configuration, []);
        (new InlineSuggestionEditorListener(new InlineSuggestionConfiguration($extensionConfiguration), $context))($event);

        return $event->getConfiguration();
    }
}
