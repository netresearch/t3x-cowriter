<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Localization;

use Netresearch\T3Cowriter\Tests\Support\XliffFile;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every label a Fluid template of this extension names resolves in English and
 * in German. A key the template spells but the language file lacks renders as
 * an empty string, which no other test would notice.
 *
 * A key with a Fluid placeholder (`status.check.{check.key}`) is expanded with
 * the check keys DiagnosticService produces, so a new check without a label
 * fails here too.
 */
#[CoversNothing]
final class TemplateLabelsTest extends TestCase
{
    private const TEMPLATE_DIRECTORY = __DIR__ . '/../../../Resources/Private';

    private const DIAGNOSTIC_SERVICE = __DIR__ . '/../../../Classes/Service/DiagnosticService.php';

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

    #[Test]
    public function theStatusTemplateNamesTheDiagnosticChecks(): void
    {
        $labels = array_column(iterator_to_array(self::templateLabels(), false), 0);

        self::assertContains(
            'LLL:EXT:t3_cowriter/Resources/Private/Language/locallang_mod_status.xlf:status.check.provider_exists',
            $labels,
        );
    }

    /**
     * @return list<string>
     */
    private static function expand(string $reference): array
    {
        if (!str_contains($reference, '{check.key}')) {
            return [$reference];
        }
        preg_match_all("/key:\\s*'([a-z_]+)'/", (string) file_get_contents(self::DIAGNOSTIC_SERVICE), $keys);

        return array_map(
            static fn (string $key): string => str_replace('{check.key}', $key, $reference),
            array_values(array_unique($keys[1])),
        );
    }
}
