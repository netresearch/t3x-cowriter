<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Reads the extension's XLIFF 1.2 language files the way the tests need them:
 * the trans-units of one file, and the text an `LLL:EXT:t3_cowriter/…` label
 * resolves to in English or German.
 */
final class XliffFile
{
    public const LANGUAGE_DIRECTORY = __DIR__ . '/../../Resources/Private/Language';

    private const LABEL_PREFIX = 'LLL:EXT:t3_cowriter/Resources/Private/Language/';

    private const NAMESPACE_URI = 'urn:oasis:names:tc:xliff:document:1.2';

    /**
     * @return array<string, array{source: string, target: string|null}> keyed by trans-unit id, in file order
     */
    public static function units(string $path): array
    {
        $xpath = self::xpath($path);
        $units = [];
        foreach ($xpath->query('//x:trans-unit') ?: [] as $unit) {
            if (!$unit instanceof DOMElement) {
                continue;
            }
            $id = $unit->getAttribute('id');
            if (array_key_exists($id, $units)) {
                throw new RuntimeException(sprintf('Duplicate trans-unit id "%s" in %s', $id, $path));
            }
            $source     = $xpath->query('x:source', $unit)?->item(0);
            $target     = $xpath->query('x:target', $unit)?->item(0);
            $units[$id] = [
                'source' => $source?->textContent ?? '',
                'target' => $target?->textContent,
            ];
        }

        return $units;
    }

    /**
     * An attribute of the `<file>` element, '' when it is missing.
     */
    public static function fileAttribute(string $path, string $name): string
    {
        $file = self::xpath($path)->query('/x:xliff/x:file')?->item(0);

        return $file instanceof DOMElement ? $file->getAttribute($name) : '';
    }

    /**
     * What LanguageService::sL() would return for an extension label: the
     * English source for 'en', the target of the `<language>.` file otherwise,
     * '' for an unknown label.
     */
    public static function label(string $reference, string $language): string
    {
        if (!str_starts_with($reference, self::LABEL_PREFIX)) {
            return '';
        }
        [$fileName, $id] = explode(':', substr($reference, strlen(self::LABEL_PREFIX)), 2) + ['', ''];
        $path            = self::LANGUAGE_DIRECTORY . '/' . ($language === 'en' ? '' : $language . '.') . $fileName;
        if (!is_file($path)) {
            return '';
        }
        $unit = self::units($path)[$id] ?? null;
        if ($unit === null) {
            return '';
        }

        return $language === 'en' ? $unit['source'] : ($unit['target'] ?? '');
    }

    private static function xpath(string $path): DOMXPath
    {
        $document = new DOMDocument();
        if (!is_file($path) || !$document->load($path)) {
            throw new RuntimeException(sprintf('Cannot read XLIFF file %s', $path));
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', self::NAMESPACE_URI);

        return $xpath;
    }
}
