<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Service;

use Netresearch\NrLlm\Exception\ConfigurationInactiveException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Exception\GuardrailPolicyException;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Throwable;

/**
 * Classifies an nr-llm failure into an {@see LlmErrorKind} using nr-llm's typed
 * exceptions, replacing the previous exception-message string-matching in the
 * controllers.
 *
 * The classification is by exception type / HTTP status, not message text:
 *
 * - {@see ConfigurationNotFoundException} / {@see ConfigurationInactiveException}
 *   → Configuration.
 * - A base {@see ProviderException} carrying the "no provider specified and no
 *   default provider configured" code → Configuration.
 * - A {@see ProviderResponseException} (which every 4xx is) with HTTP status 401
 *   → Authentication, 429 → RateLimit. This also covers the dedicated 401/429
 *   subclasses nr-llm may add (they *are* ProviderResponseExceptions carrying
 *   the same httpStatus), so no change is needed when they land.
 * - A {@see GuardrailPolicyException}, also when another exception wraps it →
 *   GuardrailRefusal. Its message is written for the person who asked and says
 *   why nothing came back, so it is the one nr-llm message shown as it is.
 */
final class LlmErrorClassifier
{
    /**
     * nr-llm's KeyedProviderRegistry throws a base ProviderException with this
     * stable (timestamp) code when no provider and no default configuration
     * resolve. TYPO3 error codes are never reused, so keying off it is safe.
     */
    private const NO_DEFAULT_PROVIDER_CODE = 4867297358;

    /**
     * The reason a guardrail gave for stopping an answer, also when another
     * exception wraps it; null for any other failure.
     */
    public static function guardrailReason(Throwable $error): ?string
    {
        for ($current = $error; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof GuardrailPolicyException) {
                return $current->getMessage();
            }
        }

        return null;
    }

    public function classify(Throwable $error): LlmErrorKind
    {
        if (self::guardrailReason($error) !== null) {
            return LlmErrorKind::GuardrailRefusal;
        }

        if ($error instanceof ConfigurationNotFoundException || $error instanceof ConfigurationInactiveException) {
            return LlmErrorKind::Configuration;
        }

        if ($error instanceof ProviderException && $error->getCode() === self::NO_DEFAULT_PROVIDER_CODE) {
            return LlmErrorKind::Configuration;
        }

        if ($error instanceof ProviderResponseException) {
            return match ($error->httpStatus) {
                401     => LlmErrorKind::Authentication,
                429     => LlmErrorKind::RateLimit,
                default => LlmErrorKind::Unknown,
            };
        }

        return LlmErrorKind::Unknown;
    }
}
