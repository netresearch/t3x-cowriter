<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

/**
 * Turns the raw strings of the model answer into field values: single line,
 * no wrapping quotes, within the field's length limit, no duplicates.
 *
 * The length limit is enforced here rather than trusted from the prompt,
 * because a model does not count characters reliably.
 */
final class SuggestionNormalizer
{
    /**
     * @param list<mixed> $raw
     *
     * @return list<string>
     */
    public function normalize(array $raw, FieldProfile $profile, int $count): array
    {
        $result = [];
        $seen   = [];

        foreach ($raw as $value) {
            if (!is_string($value)) {
                continue;
            }

            $text = $profile->kind === FieldKind::Keywords
                ? $this->keywords($value, $profile->maxLength)
                : $this->truncate($this->clean($value), $profile->maxLength);

            $key = mb_strtolower($text, 'UTF-8');
            if ($text === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[]   = $text;

            if (count($result) === $count) {
                break;
            }
        }

        return $result;
    }

    /**
     * Collapse whitespace and strip quotes the model wraps around a value.
     */
    public function clean(string $value): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $value));

        // trim() takes its character list byte by byte, so multi-byte quotes in
        // it would also strip lead/trail bytes of Cyrillic, CJK or "€".
        return preg_replace('/^[\s"\'`\x{201C}\x{201D}\x{201E}\x{2018}\x{2019}\x{00AB}\x{00BB}]+|[\s"\'`\x{201C}\x{201D}\x{201E}\x{2018}\x{2019}\x{00AB}\x{00BB}]+$/u', '', $text) ?? $text;
    }

    /**
     * Cut to at most $maxLength characters, at a word boundary where one lies
     * in the second half of the allowed length.
     */
    public function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text, 'UTF-8') <= $maxLength) {
            return $text;
        }

        $cut = mb_substr($text, 0, $maxLength, 'UTF-8');
        // The cut already ends on a word boundary when the next character is a space.
        if (mb_substr($text, $maxLength, 1, 'UTF-8') !== ' ') {
            $lastSpace = mb_strrpos($cut, ' ', 0, 'UTF-8');
            if ($lastSpace !== false && $lastSpace >= intdiv($maxLength, 2)) {
                $cut = mb_substr($cut, 0, $lastSpace, 'UTF-8');
            }
        }

        // Unicode-aware for the same reason as clean(): rtrim() works on bytes.
        return preg_replace('/[\s,;:\-\x{2013}\x{2014}]+$/u', '', $cut) ?? $cut;
    }

    /**
     * A comma-separated keyword list: trimmed, de-duplicated, and shortened by
     * whole keywords until it fits.
     */
    public function keywords(string $value, int $maxLength): string
    {
        $keywords = [];
        $parts    = preg_split('/[,;\n]+/u', $value);
        foreach ($parts === false ? [] : $parts as $keyword) {
            $keyword = $this->clean($keyword);
            $key     = mb_strtolower($keyword, 'UTF-8');
            if ($keyword !== '' && !isset($keywords[$key])) {
                $keywords[$key] = $keyword;
            }
        }

        $list = array_values($keywords);
        while ($list !== [] && mb_strlen(implode(', ', $list), 'UTF-8') > $maxLength) {
            array_pop($list);
        }

        return implode(', ', $list);
    }
}
