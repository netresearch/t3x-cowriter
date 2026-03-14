<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Dto;

enum Severity: string
{
    case Ok      = 'ok';
    case Warning = 'warning';
    case Error   = 'error';
}
