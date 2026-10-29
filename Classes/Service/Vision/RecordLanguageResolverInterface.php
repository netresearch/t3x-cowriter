<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Vision;

/**
 * The language a record is written in, named for a language model.
 *
 * @internal
 */
interface RecordLanguageResolverInterface
{
    /**
     * The English name of the record's site language, such as
     * "German (Germany)"; null when the language cannot be resolved or the
     * current backend user may not read the record.
     */
    public function languageName(string $table, int $uid): ?string;
}
