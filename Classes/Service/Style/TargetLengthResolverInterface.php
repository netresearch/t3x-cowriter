<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Style;

/**
 * The number of words a content element's type should have, as the site sets
 * it.
 *
 * @internal
 */
interface TargetLengthResolverInterface
{
    /**
     * @param array{table: string, uid: int, field: string}|null $recordContext the record the editor works on
     *
     * @return int|null words, or null when the record's type has no target
     */
    public function targetWords(?array $recordContext): ?int;
}
