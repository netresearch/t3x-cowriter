<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

use Netresearch\T3Cowriter\Service\Variant\VariantProposalService;

/**
 * Request DTO for creating an audience variant after the editor reviewed the
 * proposal: the element, the fingerprint of its stored text the proposal was
 * made for, the reviewed header and text, and the context (0 for none).
 *
 * @internal
 */
final readonly class VariantCreateRequest
{
    /**
     * Characters of reviewed text accepted at most: the proposal answers one
     * element whose stored text was at most VariantProposalService::MAX_TEXT_LENGTH.
     */
    public const MAX_BODYTEXT_LENGTH = 2 * VariantProposalService::MAX_TEXT_LENGTH;

    public function __construct(
        public int $uid,
        public string $fingerprint,
        public string $header,
        public string $bodytext,
        public int $context = 0,
    ) {}

    public function isValid(): bool
    {
        return $this->uid > 0
            && preg_match('/^[0-9a-f]{64}$/', $this->fingerprint) === 1
            && mb_strlen($this->header, 'UTF-8') <= VariantProposalService::MAX_HEADER_LENGTH
            && $this->context >= 0
            && trim($this->bodytext) !== ''
            && mb_strlen($this->bodytext, 'UTF-8') <= self::MAX_BODYTEXT_LENGTH;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            VariantRequestValue::uid($body['uid'] ?? null),
            VariantRequestValue::string($body['fingerprint'] ?? null),
            trim(VariantRequestValue::string($body['header'] ?? null)),
            VariantRequestValue::string($body['bodytext'] ?? null),
            VariantRequestValue::optionalUid($body['context'] ?? null),
        );
    }
}
