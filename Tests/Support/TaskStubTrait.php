<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Support;

use Netresearch\NrLlm\Domain\Model\Task;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Shared factory for nr-llm Task stubs, used by the TemplateController test
 * suites (unit / integration / e2e) so the identical four-getter stub lives in
 * one place instead of being copied per file.
 *
 * @mixin \PHPUnit\Framework\TestCase
 */
trait TaskStubTrait
{
    /**
     * @return Task&MockObject
     */
    private function createTaskStub(
        string $identifier,
        string $name,
        string $description,
        string $category,
    ): Task {
        $task = $this->createMock(Task::class);
        $task->method('getIdentifier')->willReturn($identifier);
        $task->method('getName')->willReturn($name);
        $task->method('getDescription')->willReturn($description);
        $task->method('getCategory')->willReturn($category);

        return $task;
    }
}
