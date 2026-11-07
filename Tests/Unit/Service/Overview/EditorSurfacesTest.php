<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Overview;

use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionConfiguration;
use Netresearch\T3Cowriter\Service\Image\ImageGenerationConfiguration;
use Netresearch\T3Cowriter\Service\InlineSuggestion\InlineSuggestionConfiguration;
use Netresearch\T3Cowriter\Service\Overview\EditorSurface;
use Netresearch\T3Cowriter\Service\Overview\EditorSurfaces;
use Netresearch\T3Cowriter\Service\Presentation\PresentationConfiguration;
use Netresearch\T3Cowriter\Service\Variant\VariantConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(EditorSurfaces::class)]
#[CoversClass(EditorSurface::class)]
#[CoversClass(PresentationConfiguration::class)]
#[CoversClass(VariantConfiguration::class)]
final class EditorSurfacesTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
        parent::tearDown();
    }

    /**
     * The overview lists exactly the cowriter buttons the preset's toolbar has.
     */
    #[Test]
    public function theToolbarButtonsAreThoseOfTheCowriterPreset(): void
    {
        $preset = Yaml::parseFile(__DIR__ . '/../../../../Configuration/RTE/Cowriter.yaml');
        $items  = $preset['editor']['config']['toolbar']['items'] ?? [];
        self::assertIsArray($items);
        $cowriterItems = array_values(array_filter($items, static fn (mixed $item): bool => is_string($item) && str_starts_with($item, 'cowriter')));

        $listed = array_values(array_filter(
            array_map(static fn (EditorSurface $surface): string => $surface->key, $this->surfaces([])->all()),
            static fn (string $key): bool => str_starts_with($key, 'cowriter'),
        ));

        self::assertSame($cowriterItems, $listed);
    }

    #[Test]
    public function everythingASettingSwitchesIsOffByDefault(): void
    {
        $GLOBALS['TCA'] = [];

        self::assertSame(
            [
                'cowriter'      => true, 'cowriterVision' => true, 'cowriterTranslate' => true, 'cowriterTemplates' => true,
                'cowriterImage' => false, 'cowriterInlineSuggestions' => false, 'fieldSuggestions' => false,
                'fieldOverview' => false, 'imageFieldControl' => false, 'presentation' => false,
                'variant'       => false,
            ],
            $this->states($this->surfaces(['fieldSuggestions/fields' => ''])),
        );
    }

    #[Test]
    public function eachSettingSwitchesItsSurfaces(): void
    {
        $GLOBALS['TCA'] = [];

        $states = $this->states($this->surfaces([
            'image/enable'             => '1',
            'inlineSuggestions/enable' => '1',
            'fieldSuggestions/fields'  => 'default',
            'presentation/enable'      => '1',
            'variant/enable'           => '1',
        ]));

        self::assertTrue($states['cowriterImage']);
        self::assertTrue($states['imageFieldControl']);
        self::assertTrue($states['cowriterInlineSuggestions']);
        self::assertTrue($states['fieldSuggestions']);
        self::assertTrue($states['fieldOverview']);
        // Switched on, but bootstrap_package's elements are not in the TCA.
        self::assertFalse($states['presentation']);
        self::assertTrue($states['variant']);
    }

    /**
     * @param array<string, string> $settings
     */
    private function surfaces(array $settings): EditorSurfaces
    {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturnCallback(static fn (string $extension, string $path = ''): mixed => $settings[$path] ?? null);

        return new EditorSurfaces(
            new ImageGenerationConfiguration($configuration),
            new InlineSuggestionConfiguration($configuration),
            new FieldSuggestionConfiguration($configuration),
            new PresentationConfiguration($configuration),
            new VariantConfiguration($configuration),
        );
    }

    /**
     * @return array<string, bool>
     */
    private function states(EditorSurfaces $surfaces): array
    {
        $states = [];
        foreach ($surfaces->all() as $surface) {
            $states[$surface->key] = $surface->enabled;
        }

        return $states;
    }
}
