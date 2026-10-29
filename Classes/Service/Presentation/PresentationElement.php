<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Presentation;

/**
 * A text element the current user may present differently, as the user's
 * workspace shows it, with the layouts open to the user.
 *
 * @internal
 */
final readonly class PresentationElement
{
    /**
     * @param int          $uid      the live uid (FormEngine's uid in a workspace too)
     * @param list<Layout> $layouts  never empty
     * @param string       $bodytext the stored rich text
     */
    public function __construct(
        public int $uid,
        public int $pageUid,
        public int $languageId,
        public int $columnPosition,
        public string $header,
        public string $bodytext,
        public array $layouts,
    ) {}

    /**
     * Identifies the stored text a proposal was made for: the creation is
     * refused when the text changed in between, because the section starts
     * would point at other blocks.
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->bodytext);
    }

    public function allows(Layout $layout): bool
    {
        return in_array($layout, $this->layouts, true);
    }
}
