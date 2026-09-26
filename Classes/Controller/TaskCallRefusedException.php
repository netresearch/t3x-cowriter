<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Controller;

use RuntimeException;

/**
 * A task call refused before the LLM is asked; the message is the text the
 * editor sees and the code the HTTP status of the JSON response.
 *
 * @internal thrown and caught inside {@see AjaxController}
 */
final class TaskCallRefusedException extends RuntimeException {}
