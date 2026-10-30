<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service\Transparency;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;

/**
 * Which nr-llm configuration and which model answered a request, for the
 * "About this suggestion" section.
 *
 * The model and the provider come from nr-llm's response, which takes them
 * from the provider's own answer, so they name the model that actually
 * answered, not the one that was asked for. An empty string means nr-llm did
 * not report it.
 */
final readonly class ModelUse
{
    public function __construct(
        public string $configurationIdentifier,
        public string $configurationName,
        public int $configurationUid,
        public string $model,
        public string $provider,
    ) {}

    public static function fromResponse(?LlmConfiguration $configuration, ?CompletionResponse $response): self
    {
        return new self(
            configurationIdentifier: $configuration?->getIdentifier() ?? '',
            configurationName: $configuration?->getName() ?? '',
            configurationUid: $configuration?->getUid() ?? 0,
            model: trim($response->model ?? ''),
            provider: trim($response->provider ?? ''),
        );
    }

    /**
     * A call without an nr-llm configuration (image generation without a
     * pinned configuration): only the model and the provider.
     */
    public static function withoutConfiguration(string $model, string $provider): self
    {
        return new self('', '', 0, trim($model), trim($provider));
    }
}
