<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

/**
 * The body of a request that saves a prompt from the Cowriter dialog.
 *
 * @internal
 */
final readonly class SavePromptRequest
{
    public const MAX_TITLE_LENGTH = 255;

    /** Same limit as the instruction of {@see ExecuteTaskRequest}. */
    public const MAX_INSTRUCTION_LENGTH = 32768;

    public function __construct(
        public string $title,
        public string $instruction,
        public bool $shared,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $title       = $data['title'] ?? '';
        $instruction = $data['instruction'] ?? '';

        return new self(
            is_string($title) ? trim($title) : '',
            is_string($instruction) ? trim($instruction) : '',
            ($data['shared'] ?? false) === true,
        );
    }

    public function isValid(): bool
    {
        return $this->title !== ''
            && $this->instruction !== ''
            && mb_strlen($this->title, 'UTF-8') <= self::MAX_TITLE_LENGTH
            && mb_strlen($this->instruction, 'UTF-8') <= self::MAX_INSTRUCTION_LENGTH;
    }
}
