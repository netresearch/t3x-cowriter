<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Tool;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Service\Tool\UnattendedToolFilterInterface;
use Netresearch\T3Cowriter\Service\CallerSource;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Runs a Cowriter task through nr-llm's tool loop with only the tools that
 * need no approval, because the dialog has no step to approve one
 * (nr-llm ADR-210).
 *
 * The tools offered are those the tool policy lets this user and this
 * configuration use, narrowed to the ones that run unattended.
 *
 * @internal
 */
final readonly class UnattendedToolRunner
{
    public function __construct(
        private ToolLoopServiceInterface $toolLoop,
        private ToolCallPolicyInterface $toolPolicy,
        private UnattendedToolFilterInterface $unattendedTools,
    ) {}

    /**
     * The tools the model may call for this user and configuration.
     *
     * @return list<string>
     */
    public function offerable(LlmConfiguration $configuration, BackendUserAuthentication $user): array
    {
        return $this->unattendedTools->unattended(
            $this->toolPolicy->filterOfferable(null, $configuration, $user),
        );
    }

    /**
     * Runs the messages with the offerable tools; null when no tool is
     * offerable, so the caller answers without tools.
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @throws ToolNeedsApprovalException when a tool asks for an approval all the same
     */
    public function run(array $messages, LlmConfiguration $configuration, BackendUserAuthentication $user): ?ToolLoopResult
    {
        $tools = $this->offerable($configuration, $user);
        if ($tools === []) {
            return null;
        }

        try {
            return $this->toolLoop->runLoop(
                $messages,
                $configuration,
                ToolExecutionContext::fromBackendUser($user),
                $tools,
                ToolOptions::auto()->withCallerSource(CallerSource::EXTENSION, 'taskTools'),
            );
        } catch (ToolApprovalRequiredException $e) {
            throw new ToolNeedsApprovalException('A tool asked for an approval.', 1790200001, $e);
        }
    }
}
