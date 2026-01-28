<?php

/*
 * This file is part of the package netresearch/t3-cowriter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

use JsonSerializable;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;

/**
 * Token usage statistics DTO for API responses.
 *
 * Wraps nr-llm UsageStatistics for JSON serialization.
 *
 * @internal
 */
final readonly class UsageData implements JsonSerializable
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
        public ?float $estimatedCost = null,
    ) {}

    /**
     * Create from nr-llm UsageStatistics.
     */
    public static function fromUsageStatistics(UsageStatistics $usage): self
    {
        return new self(
            promptTokens: $usage->promptTokens,
            completionTokens: $usage->completionTokens,
            totalTokens: $usage->totalTokens,
            estimatedCost: $usage->estimatedCost,
        );
    }

    /**
     * @return array{promptTokens: int, completionTokens: int, totalTokens: int, estimatedCost: float|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'promptTokens'     => $this->promptTokens,
            'completionTokens' => $this->completionTokens,
            'totalTokens'      => $this->totalTokens,
            'estimatedCost'    => $this->estimatedCost,
        ];
    }
}
