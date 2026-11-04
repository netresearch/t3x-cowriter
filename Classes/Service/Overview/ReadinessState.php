<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Overview;

/**
 * State of one cowriter action for the viewing backend user. A state that
 * was not measured is NotChecked, never Ready. Partly means the action runs
 * on some of its paths and fails on others; the reason names which.
 *
 * @internal
 */
enum ReadinessState: string
{
    case Ready      = 'ready';
    case NotReady   = 'notReady';
    case Partly     = 'partly';
    case Off        = 'off';
    case NotChecked = 'notChecked';

    /**
     * Core icon shown next to the state's text; the text alone carries the
     * meaning.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Ready      => 'actions-check-circle',
            self::NotReady   => 'actions-close',
            self::Partly     => 'actions-exclamation-triangle',
            self::Off        => 'actions-minus-circle',
            self::NotChecked => 'actions-question-circle',
        };
    }

    /**
     * Backend badge modifier.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Ready      => 'success',
            self::NotReady   => 'danger',
            self::Partly     => 'warning',
            self::Off        => 'secondary',
            self::NotChecked => 'info',
        };
    }
}
