<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Variant;

/**
 * A text element the current user may create an audience variant of, as the
 * user's workspace shows it.
 *
 * @internal
 */
final readonly class VariantElement
{
    /**
     * @param int                  $uid      always the live uid; the reader refuses a version uid
     * @param string               $bodytext the stored rich text
     * @param array<string, mixed> $row      the stored record, flat context columns included
     */
    public function __construct(
        public int $uid,
        public int $pageUid,
        public int $languageId,
        public string $header,
        public string $bodytext,
        public array $row = [],
    ) {}

    /**
     * Identifies the stored text a proposal was made for: the creation is
     * refused when it changed in between, because the variant would rewrite
     * a text the editor no longer has.
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->header . "\0" . $this->bodytext);
    }
}
