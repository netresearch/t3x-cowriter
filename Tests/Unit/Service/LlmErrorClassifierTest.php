<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service;

use Netresearch\NrLlm\Exception\ConfigurationInactiveException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\T3Cowriter\Service\LlmErrorClassifier;
use Netresearch\T3Cowriter\Service\LlmErrorKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(LlmErrorClassifier::class)]
final class LlmErrorClassifierTest extends TestCase
{
    private LlmErrorClassifier $subject;

    protected function setUp(): void
    {
        $this->subject = new LlmErrorClassifier();
    }

    #[Test]
    public function configurationNotFoundIsAConfigurationError(): void
    {
        self::assertSame(
            LlmErrorKind::Configuration,
            $this->subject->classify(new ConfigurationNotFoundException('missing', 1)),
        );
    }

    #[Test]
    public function configurationInactiveIsAConfigurationError(): void
    {
        self::assertSame(
            LlmErrorKind::Configuration,
            $this->subject->classify(new ConfigurationInactiveException('inactive', 1)),
        );
    }

    #[Test]
    public function noDefaultProviderExceptionIsAConfigurationError(): void
    {
        // nr-llm KeyedProviderRegistry throws this base ProviderException code.
        self::assertSame(
            LlmErrorKind::Configuration,
            $this->subject->classify(new ProviderException('no provider', 4867297358)),
        );
    }

    #[Test]
    public function status401IsAnAuthenticationError(): void
    {
        self::assertSame(
            LlmErrorKind::Authentication,
            $this->subject->classify(new ProviderResponseException(message: 'Unauthorized', httpStatus: 401)),
        );
    }

    #[Test]
    public function status429IsARateLimitError(): void
    {
        self::assertSame(
            LlmErrorKind::RateLimit,
            $this->subject->classify(new ProviderResponseException(message: 'Too many requests', httpStatus: 429)),
        );
    }

    #[Test]
    public function otherProviderResponseStatusesAreUnknown(): void
    {
        self::assertSame(
            LlmErrorKind::Unknown,
            $this->subject->classify(new ProviderResponseException(message: 'Forbidden', httpStatus: 403)),
        );
    }

    #[Test]
    public function otherProviderExceptionCodesAreUnknown(): void
    {
        self::assertSame(
            LlmErrorKind::Unknown,
            $this->subject->classify(new ProviderException('some other provider error', 6273324883)),
        );
    }

    #[Test]
    public function unrelatedThrowableIsUnknown(): void
    {
        self::assertSame(
            LlmErrorKind::Unknown,
            $this->subject->classify(new RuntimeException('boom')),
        );
    }
}
