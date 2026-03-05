<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Domain\DTO;

use JsonSerializable;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;

/**
 * Response DTO for completion AJAX endpoint.
 *
 * Returns raw data in JSON responses — no server-side HTML escaping.
 * The frontend sanitizes content via DOMParser before DOM insertion.
 *
 * @internal
 */
final readonly class CompleteResponse implements JsonSerializable
{
    private function __construct(
        public bool $success,
        public ?string $content,
        public ?string $model,
        public ?string $finishReason,
        public ?UsageData $usage,
        public bool $wasTruncated,
        public bool $wasFiltered,
        public ?string $error,
        public ?int $retryAfter,
        public ?string $thinking = null,
    ) {}

    /**
     * Minimum content length to consider a response substantive.
     *
     * If the content is shorter than this and thinking exists, the model
     * likely put its actual answer inside <think> tags.
     */
    private const MIN_SUBSTANTIVE_CONTENT_LENGTH = 10;

    /**
     * Create a successful response from nr-llm CompletionResponse.
     *
     * Returns raw content without server-side HTML escaping.
     * The frontend sanitizes content via DOMParser before DOM insertion.
     *
     * If the response content is nearly empty but thinking content exists,
     * the model likely placed its answer inside <think> tags. In that case,
     * the thinking content is used as the response content to avoid returning
     * fragments like "</".
     */
    public static function success(CompletionResponse $response): self
    {
        $content  = $response->content ?? '';
        $thinking = $response->thinking;

        // Fallback: if content is empty/tiny but thinking has substance,
        // the model put its answer in <think> tags — use thinking as content
        if (mb_strlen(trim($content), 'UTF-8') < self::MIN_SUBSTANTIVE_CONTENT_LENGTH
            && $response->hasThinking()
        ) {
            $content  = $thinking ?? '';
            $thinking = null;
        }

        return new self(
            success: true,
            content: $content,
            model: $response->model ?? '',
            finishReason: $response->finishReason ?? '',
            usage: UsageData::fromUsageStatistics($response->usage),
            wasTruncated: $response->wasTruncated(),
            wasFiltered: $response->wasFiltered(),
            error: null,
            retryAfter: null,
            thinking: $thinking,
        );
    }

    /**
     * Create an error response.
     */
    public static function error(string $message): self
    {
        return new self(
            success: false,
            content: null,
            model: null,
            finishReason: null,
            usage: null,
            wasTruncated: false,
            wasFiltered: false,
            error: $message,
            retryAfter: null,
        );
    }

    /**
     * Create a rate-limited response with retry information.
     *
     * Note: Currently unused as nr-llm does not expose RateLimitException.
     * This method is prepared for future integration when rate limiting
     * is supported by the nr-llm provider abstraction layer.
     *
     * @see https://github.com/netresearch/t3x-nr-llm/issues - Track rate limit support
     */
    public static function rateLimited(int $retryAfter): self
    {
        return new self(
            success: false,
            content: null,
            model: null,
            finishReason: null,
            usage: null,
            wasTruncated: false,
            wasFiltered: false,
            error: 'Rate limit exceeded. Please try again later.',
            retryAfter: $retryAfter,
        );
    }

    /**
     * Serialize to JSON for API response.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'success'      => $this->success,
            'wasTruncated' => $this->wasTruncated,
            'wasFiltered'  => $this->wasFiltered,
        ];

        if ($this->success) {
            $data['content']      = $this->content;
            $data['model']        = $this->model;
            $data['finishReason'] = $this->finishReason;
            $data['usage']        = $this->usage?->jsonSerialize();
            if ($this->thinking !== null) {
                $data['thinking'] = $this->thinking;
            }
        } else {
            $data['error'] = $this->error;
            if ($this->retryAfter !== null) {
                $data['retryAfter'] = $this->retryAfter;
            }
        }

        return $data;
    }
}
