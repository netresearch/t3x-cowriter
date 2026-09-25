<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\FieldSuggestion;

/**
 * Fences record data for the prompt so it reads as data, not as instructions.
 *
 * The data is placed between fixed BEGIN/END markers that the system prompt
 * names. Anything inside the data that looks like one of the markers — any
 * case, any spacing, two or more angle brackets — is defused first, so page
 * content cannot close the fence early and continue as instructions. This is
 * the approach of nr-llm's FetchExternalUrlTool (its helper is private).
 */
final class UntrustedDataFence
{
    public const BEGIN_MARKER = '<<<BEGIN UNTRUSTED PAGE DATA>>>';

    public const END_MARKER = '<<<END UNTRUSTED PAGE DATA>>>';

    private const MARKER_LIKE = '/<{2,}\s*(BEGIN|END)\s+UNTRUSTED\s+PAGE\s+DATA/iu';

    /**
     * $data between the markers, with marker look-alikes inside it defused.
     */
    public function wrap(string $data): string
    {
        return self::BEGIN_MARKER . "\n" . $this->neutralize($data) . "\n" . self::END_MARKER;
    }

    /**
     * Replace every marker look-alike by a bracketed lower-case form.
     */
    public function neutralize(string $data): string
    {
        return preg_replace_callback(
            self::MARKER_LIKE,
            static fn (array $match): string => '[' . strtolower($match[1]) . ' untrusted page data',
            $data,
        ) ?? '(the page data could not be made safe to show and was withheld)';
    }
}
