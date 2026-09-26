<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Service\Tool\UnattendedToolFilterInterface;
use Netresearch\T3Cowriter\Service\Tool\ToolNeedsApprovalException;
use Netresearch\T3Cowriter\Service\Tool\UnattendedToolRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

#[CoversClass(UnattendedToolRunner::class)]
final class UnattendedToolRunnerTest extends TestCase
{
    private const MESSAGES = [['role' => 'user', 'content' => 'Which pages mention the fair?']];

    #[Test]
    public function theLoopGetsTheOfferableToolsNarrowedToTheUnattendedOnes(): void
    {
        $configuration = new LlmConfiguration();
        $user          = $this->user();

        $policy = $this->createMock(ToolCallPolicyInterface::class);
        $policy->expects(self::once())->method('filterOfferable')
            ->with(null, $configuration, $user)
            ->willReturn(['search_content', 'update_record', 'read_page']);
        $filter = $this->createMock(UnattendedToolFilterInterface::class);
        $filter->expects(self::once())->method('unattended')
            ->with(['search_content', 'update_record', 'read_page'])
            ->willReturn(['search_content', 'read_page']);

        $calls = [];
        $loop  = $this->createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturnCallback(
            function (array $messages, LlmConfiguration $config, ToolExecutionContext $context, ?array $tools) use (&$calls): ToolLoopResult {
                $calls[] = [$messages, $tools, $context->backendUser];

                return new ToolLoopResult('<p>Two pages.</p>', [], 2, false, new UsageStatistics(10, 5, 15));
            },
        );

        $result = (new UnattendedToolRunner($loop, $policy, $filter))->run(self::MESSAGES, $configuration, $user);

        self::assertSame('<p>Two pages.</p>', $result?->finalContent);
        self::assertSame([[self::MESSAGES, ['search_content', 'read_page'], $user]], $calls);
    }

    #[Test]
    public function withoutAnUnattendedToolTheLoopDoesNotRun(): void
    {
        $policy = $this->createStub(ToolCallPolicyInterface::class);
        $policy->method('filterOfferable')->willReturn(['update_record']);
        $filter = $this->createStub(UnattendedToolFilterInterface::class);
        $filter->method('unattended')->willReturn([]);
        $loop = $this->createMock(ToolLoopServiceInterface::class);
        $loop->expects(self::never())->method('runLoop');

        self::assertNull((new UnattendedToolRunner($loop, $policy, $filter))->run(self::MESSAGES, new LlmConfiguration(), $this->user()));
    }

    #[Test]
    public function aToolThatAsksForApprovalFailsTheRun(): void
    {
        $policy = $this->createStub(ToolCallPolicyInterface::class);
        $policy->method('filterOfferable')->willReturn(['remote_tool']);
        $filter = $this->createStub(UnattendedToolFilterInterface::class);
        $filter->method('unattended')->willReturn(['remote_tool']);
        $suspended = (new ReflectionClass(SuspendedRunState::class))->newInstanceWithoutConstructor();
        $loop      = $this->createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willThrowException(ToolApprovalRequiredException::fromState($suspended));

        $this->expectException(ToolNeedsApprovalException::class);

        (new UnattendedToolRunner($loop, $policy, $filter))->run(self::MESSAGES, new LlmConfiguration(), $this->user());
    }

    private function user(): BackendUserAuthentication
    {
        $user = $this->createStub(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->user = ['uid' => 7];

        return $user;
    }
}
