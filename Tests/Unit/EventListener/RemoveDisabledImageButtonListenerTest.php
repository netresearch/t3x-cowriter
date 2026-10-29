<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\EventListener;

use Netresearch\T3Cowriter\EventListener\RemoveDisabledImageButtonListener;
use Netresearch\T3Cowriter\Service\Image\ImageGenerationConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\RteCKEditor\Form\Element\Event\AfterPrepareConfigurationForEditorEvent;

#[CoversClass(RemoveDisabledImageButtonListener::class)]
#[CoversClass(ImageGenerationConfiguration::class)]
final class RemoveDisabledImageButtonListenerTest extends TestCase
{
    #[Test]
    public function theButtonStaysWhileTheSettingIsOn(): void
    {
        $configuration = ['toolbar' => ['items' => ['bold', 'cowriterImage', 'cowriterTranslate']]];

        self::assertSame($configuration, $this->dispatch($configuration, '1'));
    }

    #[Test]
    public function theButtonIsRemovedFromAnItemsToolbarWhileTheSettingIsOff(): void
    {
        $result = $this->dispatch(
            ['toolbar' => ['items' => ['bold', 'cowriterImage', 'cowriterTranslate'], 'shouldNotGroupWhenFull' => true]],
            '0',
        );

        self::assertSame(
            ['toolbar' => ['items' => ['bold', 'cowriterTranslate'], 'shouldNotGroupWhenFull' => true]],
            $result,
        );
    }

    #[Test]
    public function theButtonIsRemovedFromAListToolbar(): void
    {
        self::assertSame(
            ['toolbar' => ['bold', '|', 'cowriterTranslate']],
            $this->dispatch(['toolbar' => ['bold', '|', 'cowriterImage', 'cowriterTranslate']], ''),
        );
    }

    #[Test]
    public function theButtonIsRemovedFromAGroup(): void
    {
        $result = $this->dispatch(
            ['toolbar' => ['items' => ['bold', ['label' => 'AI', 'items' => ['cowriter', 'cowriterImage']]]]],
            '0',
        );

        self::assertSame(['toolbar' => ['items' => ['bold', ['label' => 'AI', 'items' => ['cowriter']]]]], $result);
    }

    #[Test]
    public function otherToolbarSettingsAreLeftAlone(): void
    {
        $configuration = ['toolbar' => ['items' => ['bold'], 'removeItems' => ['cowriterImage']]];

        self::assertSame($configuration, $this->dispatch($configuration, '0'));
    }

    #[Test]
    public function aConfigurationWithoutToolbarIsLeftAlone(): void
    {
        self::assertSame(['height' => 300], $this->dispatch(['height' => 300], '0'));
    }

    /**
     * @param array<string, mixed> $configuration
     *
     * @return array<string, mixed>
     */
    private function dispatch(array $configuration, string $enable): array
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnMap([['t3_cowriter', 'image/enable', $enable]]);

        $event = new AfterPrepareConfigurationForEditorEvent($configuration, []);
        (new RemoveDisabledImageButtonListener(new ImageGenerationConfiguration($extensionConfiguration)))($event);

        return $event->getConfiguration();
    }
}
