<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Style;

use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Reads the target length of a content element's type from Page TSconfig:
 *
 *     tx_cowriter.targetLength.text = 150
 *     tx_cowriter.targetLength.textmedia = 120
 *
 * The page is the element's own page, so a site or a section can set its own
 * lengths. Only tt_content records have a type here; a page record or a
 * missing record has no target.
 *
 * @internal
 */
final class TargetLengthResolver implements TargetLengthResolverInterface
{
    public function targetWords(?array $recordContext): ?int
    {
        if ($recordContext === null || $recordContext['table'] !== 'tt_content' || $recordContext['uid'] < 1) {
            return null;
        }

        $record = BackendUtility::getRecordWSOL('tt_content', $recordContext['uid'], 'pid,CType');
        if (!is_array($record)) {
            return null;
        }

        $pid   = is_numeric($record['pid'] ?? null) ? (int) $record['pid'] : 0;
        $cType = is_string($record['CType'] ?? null) ? $record['CType'] : '';
        if ($pid < 1 || $cType === '') {
            return null;
        }

        $cowriter = BackendUtility::getPagesTSconfig($pid)['tx_cowriter.'] ?? null;
        $lengths  = is_array($cowriter) ? ($cowriter['targetLength.'] ?? null) : null;
        $words    = is_array($lengths) ? ($lengths[$cType] ?? null) : null;

        return is_numeric($words) && (int) $words > 0 ? (int) $words : null;
    }
}
