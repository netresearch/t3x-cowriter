<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Presentation;

/**
 * Decides where a text is cut into sections and cuts it.
 *
 * Every body is cut from the stored blocks: neither the model nor the request
 * supplies markup. They only name where a section starts and how it is
 * titled.
 *
 * @internal
 */
final readonly class SectionPlanner
{
    public const MIN_SECTIONS = 2;

    public const MAX_SECTIONS = 12;

    public const MAX_TITLE_LENGTH = 100;

    /**
     * Heading levels a text is cut at. The highest of them present wins;
     * lower headings stay inside the bodies.
     */
    private const SPLIT_LEVELS = [2, 3];

    /**
     * Sections from the h2 headings of the text, or its h3 headings when it
     * has no h2. Content before the first of them is a section of its own,
     * titled $introTitle. Empty when that gives fewer than two or more than
     * MAX_SECTIONS sections.
     *
     * @param list<Block> $blocks
     *
     * @return list<SectionBoundary>
     */
    public function fromHeadings(array $blocks, string $introTitle): array
    {
        $levels = [];
        foreach ($blocks as $block) {
            if (in_array($block->headingLevel, self::SPLIT_LEVELS, true) && $block->text !== '') {
                $levels[] = $block->headingLevel;
            }
        }

        if ($levels === []) {
            return [];
        }

        // A heading without text is no title, and a heading with nothing
        // below it before the next one (or the end) would make an empty
        // section: neither is a place to cut, both stay in the text.
        $level      = min($levels);
        $candidates = [];
        foreach ($blocks as $index => $block) {
            if ($block->headingLevel === $level && $block->text !== '') {
                $candidates[$index] = true;
            }
        }

        $boundaries = [];
        foreach (array_keys($candidates) as $index) {
            if (!isset($blocks[$index + 1]) || isset($candidates[$index + 1])) {
                continue;
            }

            if ($boundaries === [] && $index > 0) {
                $boundaries[] = new SectionBoundary(self::title($introTitle), 0);
            }

            $boundaries[] = new SectionBoundary(self::title($blocks[$index]->text), $index, true);
        }

        $count = count($boundaries);

        return $count >= self::MIN_SECTIONS && $count <= self::MAX_SECTIONS ? $boundaries : [];
    }

    /**
     * Sections from the model's answer: entries without a usable title or
     * start block are dropped, the rest ordered by start block. The first
     * section always starts at the first block, so no text is lost. Empty
     * when fewer than two sections remain.
     *
     * @param list<Block> $blocks
     *
     * @return list<SectionBoundary>
     */
    public function fromModel(array $blocks, mixed $sections): array
    {
        if (!is_array($sections) || $blocks === []) {
            return [];
        }

        $byStart = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $title = is_string($section['title'] ?? null) ? self::title($section['title']) : '';
            $start = $section['start'] ?? null;
            if ($title === '' || !is_int($start) || $start < 0 || $start >= count($blocks) || isset($byStart[$start])) {
                continue;
            }

            $byStart[$start] = $title;
        }

        ksort($byStart);
        $boundaries = [];
        foreach ($byStart as $start => $title) {
            $boundaries[] = new SectionBoundary($title, $boundaries === [] ? 0 : $start);
        }

        return count($boundaries) >= self::MIN_SECTIONS ? array_slice($boundaries, 0, self::MAX_SECTIONS) : [];
    }

    /**
     * Whether sections a request sent back fit the blocks: two to
     * MAX_SECTIONS sections, the first at block 0, start blocks strictly
     * increasing and inside the text, every title non-empty, and a heading
     * consumed only where the section starts with one.
     *
     * @param list<Block>           $blocks
     * @param list<SectionBoundary> $boundaries
     */
    public function fits(array $blocks, array $boundaries): bool
    {
        $count = count($boundaries);
        if ($count < self::MIN_SECTIONS || $count > self::MAX_SECTIONS || $boundaries[0]->start !== 0) {
            return false;
        }

        $previous = -1;
        foreach ($boundaries as $boundary) {
            $block = $blocks[$boundary->start] ?? null;
            if (!$block instanceof Block
                || $boundary->start <= $previous
                || $boundary->title === ''
                || ($boundary->consumesHeading && !$block->isHeading())
            ) {
                return false;
            }

            $previous = $boundary->start;
        }

        return true;
    }

    /**
     * The sections with their bodies: the stored markup of their blocks.
     *
     * @param list<Block>           $blocks
     * @param list<SectionBoundary> $boundaries ordered, as fits() accepts them
     *
     * @return list<SectionContent>
     */
    public function contents(array $blocks, array $boundaries): array
    {
        $contents = [];
        foreach ($boundaries as $index => $boundary) {
            $from = $boundary->start + ($boundary->consumesHeading ? 1 : 0);
            $to   = isset($boundaries[$index + 1]) ? $boundaries[$index + 1]->start : count($blocks);
            // Start blocks strictly increase, so $to is never below $from.
            $slice = array_slice($blocks, $from, $to - $from);

            $contents[] = new SectionContent(
                $boundary->title,
                implode("\n", array_map(static fn (Block $block): string => $block->html, $slice)),
                BlockParser::plainText(implode(' ', array_map(static fn (Block $block): string => $block->text, $slice))),
            );
        }

        return $contents;
    }

    /**
     * A title on one line, at most MAX_TITLE_LENGTH characters. It is plain
     * text already (a heading's text, the model's or the editor's words) and
     * is stored in a plain text field, so nothing in it is markup: "The <p>
     * element" stays as it is. Control characters become spaces.
     */
    public static function title(string $text): string
    {
        $plain = BlockParser::plainText((string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', ' ', $text));

        return trim(mb_substr($plain, 0, self::MAX_TITLE_LENGTH, 'UTF-8'));
    }
}
