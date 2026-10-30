<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

use Netresearch\T3Cowriter\Service\Presentation\Layout;
use Netresearch\T3Cowriter\Service\Presentation\SectionBoundary;
use Netresearch\T3Cowriter\Service\Presentation\SectionPlanner;

/**
 * Request DTO for creating the new element: the element, the layout, the
 * fingerprint of the text the proposal was made for, and the sections as
 * start blocks and titles. It carries no markup; the bodies are cut from the
 * stored text on the server.
 *
 * @internal
 */
final readonly class PresentationCreateRequest
{
    /**
     * @param list<SectionBoundary> $sections
     */
    public function __construct(
        public int $uid,
        public ?Layout $layout,
        public string $fingerprint,
        public array $sections,
    ) {}

    /**
     * Shape only; whether the sections fit the text is the planner's call.
     */
    public function isValid(): bool
    {
        return $this->uid > 0
            && $this->layout instanceof Layout
            && preg_match('/^[0-9a-f]{64}$/', $this->fingerprint) === 1
            && $this->sections !== []
            && count($this->sections) <= SectionPlanner::MAX_SECTIONS;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $uid         = $body['uid'] ?? 0;
        $layout      = $body['layout'] ?? null;
        $fingerprint = $body['fingerprint'] ?? '';
        $rawSections = $body['sections'] ?? null;

        $sections = [];
        if (is_array($rawSections) && array_is_list($rawSections) && count($rawSections) <= SectionPlanner::MAX_SECTIONS) {
            foreach ($rawSections as $raw) {
                $section = is_array($raw) ? self::section($raw) : null;
                if (!$section instanceof SectionBoundary) {
                    // One malformed entry spoils the whole list.
                    $sections = [];

                    break;
                }

                $sections[] = $section;
            }
        }

        return new self(
            uid: is_int($uid) || (is_string($uid) && ctype_digit($uid)) ? (int) $uid : 0,
            layout: is_string($layout) ? Layout::tryFrom($layout) : null,
            fingerprint: is_string($fingerprint) ? $fingerprint : '',
            sections: $sections,
        );
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private static function section(array $raw): ?SectionBoundary
    {
        $title   = $raw['title'] ?? null;
        $start   = $raw['start'] ?? null;
        $heading = $raw['consumesHeading'] ?? false;

        if (!is_string($title) || !is_int($start) || !is_bool($heading)) {
            return null;
        }

        return new SectionBoundary(SectionPlanner::title($title), $start, $heading);
    }
}
