<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

/**
 * A prompt as the dialog of one backend user sees it.
 *
 * @internal
 */
final readonly class SavedPrompt
{
    public function __construct(
        public int $uid,
        public string $title,
        public string $instruction,
        /** The viewing user saved the prompt. */
        public bool $own,
        public bool $shared,
        /** Shared, but other editors see it only after an administrator approves it. */
        public bool $awaitingApproval,
    ) {}

    /**
     * @return array{uid: int, title: string, instruction: string, own: bool, shared: bool, awaitingApproval: bool}
     */
    public function toArray(): array
    {
        return [
            'uid'              => $this->uid,
            'title'            => $this->title,
            'instruction'      => $this->instruction,
            'own'              => $this->own,
            'shared'           => $this->shared,
            'awaitingApproval' => $this->awaitingApproval,
        ];
    }
}
