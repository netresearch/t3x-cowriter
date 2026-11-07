<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Localization;

use Netresearch\T3Cowriter\Controller\Backend\OverviewController;
use Netresearch\T3Cowriter\Service\FieldSuggestion\FieldSuggestionConfiguration;
use Netresearch\T3Cowriter\Service\Image\ImageGenerationConfiguration;
use Netresearch\T3Cowriter\Service\InlineSuggestion\InlineSuggestionConfiguration;
use Netresearch\T3Cowriter\Service\Overview\EditorSurface;
use Netresearch\T3Cowriter\Service\Overview\EditorSurfaces;
use Netresearch\T3Cowriter\Service\Overview\ReadinessAction;
use Netresearch\T3Cowriter\Service\Overview\ReadinessReason;
use Netresearch\T3Cowriter\Service\Overview\ReadinessState;
use Netresearch\T3Cowriter\Service\Overview\UsageService;
use Netresearch\T3Cowriter\Service\Presentation\PresentationConfiguration;
use Netresearch\T3Cowriter\Service\Variant\VariantConfiguration;
use Netresearch\T3Cowriter\Tests\Support\XliffFile;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Every label a Fluid template of this extension names resolves in English and
 * in German. A key the template spells but the language file lacks renders as
 * an empty string, which no other test would notice.
 *
 * A key with a Fluid placeholder (`readiness.reason.{entry.row.reason.value}`)
 * is expanded with every value the code can put there, so a new readiness
 * reason, operation, editor surface or linked module without a label fails
 * here too.
 */
#[CoversNothing]
final class TemplateLabelsTest extends TestCase
{
    private const TEMPLATE_DIRECTORY = __DIR__ . '/../../../Resources/Private';

    private const OVERVIEW_LABELS = 'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_mod_status.xlf:';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function templateLabels(): iterable
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::TEMPLATE_DIRECTORY));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'html') {
                continue;
            }

            $template = (string) file_get_contents($file->getPathname());
            preg_match_all('/LLL:EXT:t3_cowriter\/Resources\/Private\/Language\/[A-Za-z_.]+\.xlf:[A-Za-z0-9_.{}]+/', $template, $matches);
            foreach (array_unique($matches[0]) as $reference) {
                foreach (self::expand($reference) as $label) {
                    foreach (['en', 'de'] as $language) {
                        yield basename($file->getPathname()) . ' ' . $label . ' ' . $language => [$label, $language];
                    }
                }
            }
        }
    }

    #[Test]
    #[DataProvider('templateLabels')]
    public function everyTemplateLabelResolves(string $reference, string $language): void
    {
        self::assertNotSame('', XliffFile::label($reference, $language), $reference . ' has no ' . $language . ' text');
    }

    /**
     * A placeholder this test does not know would be looked up literally and
     * pass for the wrong reason, or fail without saying why.
     */
    #[Test]
    public function everyPlaceholderIsExpanded(): void
    {
        foreach (array_column(iterator_to_array(self::templateLabels(), false), 0) as $label) {
            self::assertStringNotContainsString('{', $label);
        }
    }

    /**
     * The expansion reaches the overview's readiness reasons and operations,
     * so the check above is not passing on an empty set.
     */
    #[Test]
    public function theOverviewTemplateNamesEveryReadinessReasonAndOperation(): void
    {
        $labels = array_column(iterator_to_array(self::templateLabels(), false), 0);

        foreach (ReadinessReason::cases() as $reason) {
            self::assertContains(self::OVERVIEW_LABELS . 'readiness.reason.' . $reason->value, $labels);
        }

        foreach (UsageService::OPERATIONS as $operation) {
            self::assertContains(self::OVERVIEW_LABELS . 'usage.operation.' . $operation, $labels);
        }
    }

    /**
     * @return list<string>
     */
    private static function expand(string $reference): array
    {
        if (preg_match('/\{([^}]+)\}/', $reference, $placeholder) !== 1) {
            return [$reference];
        }

        $values = self::placeholderValues($placeholder[1]);
        if ($values === null) {
            // Left as is: everyPlaceholderIsExpanded() names it.
            return [$reference];
        }

        $expanded = [];
        foreach ($values as $value) {
            foreach (self::expand(str_replace($placeholder[0], $value, $reference)) as $label) {
                $expanded[] = $label;
            }
        }

        return $expanded;
    }

    /**
     * @return list<string>|null null for a placeholder this test does not know
     */
    private static function placeholderValues(string $placeholder): ?array
    {
        return match ($placeholder) {
            'surface.key'            => self::surfaceKeys(),
            'entry.row.action.value' => array_map(static fn (ReadinessAction $action): string => $action->value, ReadinessAction::cases()),
            'entry.row.state.value'  => array_map(static fn (ReadinessState $state): string => $state->value, ReadinessState::cases()),
            'entry.row.reason.value' => array_map(static fn (ReadinessReason $reason): string => $reason->value, ReadinessReason::cases()),
            // A task is shown as usage.operation.task, an empty name as usage.operation.unnamed.
            'operation.labelKey' => [...UsageService::OPERATIONS, 'task', 'unnamed'],
            'action.module'      => OverviewController::ACTION_MODULES,
            default              => null,
        };
    }

    /**
     * @return list<string>
     */
    private static function surfaceKeys(): array
    {
        // Unset settings: every surface is still listed, switched off or not.
        $configuration = new ExtensionConfiguration();
        $surfaces      = new EditorSurfaces(
            new ImageGenerationConfiguration($configuration),
            new InlineSuggestionConfiguration($configuration),
            new FieldSuggestionConfiguration($configuration),
            new PresentationConfiguration($configuration),
            new VariantConfiguration($configuration),
        );

        return array_map(static fn (EditorSurface $surface): string => $surface->key, $surfaces->all());
    }
}
