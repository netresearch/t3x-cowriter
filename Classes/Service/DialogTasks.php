<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;

/**
 * The tasks the cowriter dialog offers: nr-llm's active tasks of the
 * "content" category.
 *
 * @internal
 */
final class DialogTasks
{
    public const CATEGORY = 'content';

    /**
     * @return list<Task>
     */
    public static function active(TaskRepository $repository): array
    {
        $tasks = [];
        foreach ($repository->findByCategory(self::CATEGORY) as $task) {
            if ($task instanceof Task && $task->isActive()) {
                $tasks[] = $task;
            }
        }

        return $tasks;
    }
}
