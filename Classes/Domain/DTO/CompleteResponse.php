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
use Netresearch\NrLlm\Domain\Model\CompletionResponse;

/**
 * Response DTO for completion AJAX endpoint.
 *
 * Handles proper HTML escaping of LLM output to prevent XSS attacks.
 *
 * @internal
 */
final readonly class CompleteResponse implements JsonSerializable
{
    private function __construct(
        public bool $success,
        public ?string $content,
        public ?string $model,
        public ?UsageData $usage,
        public ?string $error,
        public ?int $retryAfter,
    ) {}

    /**
     * Create a successful response from nr-llm CompletionResponse.
     *
     * SECURITY: All string content is HTML-escaped to prevent XSS attacks.
     */
    public static function success(CompletionResponse $response): self
    {
        return new self(
            success: true,
            content: htmlspecialchars($response->content, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            model: htmlspecialchars($response->model ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            usage: UsageData::fromUsageStatistics($response->usage),
            error: null,
            retryAfter: null,
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
            usage: null,
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
            usage: null,
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
        $data = ['success' => $this->success];

        if ($this->success) {
            $data['content'] = $this->content;
            $data['model']   = $this->model;
            $data['usage']   = $this->usage?->jsonSerialize();
        } else {
            $data['error'] = $this->error;
            if ($this->retryAfter !== null) {
                $data['retryAfter'] = $this->retryAfter;
            }
        }

        return $data;
    }
}
