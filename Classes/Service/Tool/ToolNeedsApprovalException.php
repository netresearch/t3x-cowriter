<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Tool;

use RuntimeException;

/**
 * A tool asked for an approval during a Cowriter task. The dialog cannot
 * give one, so the task fails; the unattended filter should have kept such
 * a tool out.
 *
 * @internal
 */
final class ToolNeedsApprovalException extends RuntimeException {}
