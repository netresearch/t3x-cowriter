<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Overview;

use Netresearch\T3Cowriter\Service\Overview\ReadinessAction;
use Netresearch\T3Cowriter\Service\Overview\ReadinessReason;
use Netresearch\T3Cowriter\Service\Overview\ReadinessRow;
use Netresearch\T3Cowriter\Service\Overview\ReadinessState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each reason links the module where an administrator fixes it: the records
 * the runtime path stumbled over, or the extension configuration for a
 * switch. A reason that needs no fix links nothing.
 */
#[CoversClass(ReadinessReason::class)]
#[CoversClass(ReadinessRow::class)]
final class ReadinessReasonTest extends TestCase
{
    /**
     * @return iterable<string, array{ReadinessReason, ?string}>
     */
    public static function reasons(): iterable
    {
        yield 'denied configuration' => [ReadinessReason::ConfigurationDenied, 'nrllm_configurations'];
        yield 'no default' => [ReadinessReason::NoConfiguration, 'nrllm_configurations'];
        yield 'unknown configuration' => [ReadinessReason::ConfigurationNotFound, 'nrllm_configurations'];
        yield 'unusable site configuration' => [ReadinessReason::SiteConfigurationUnusable, 'nrllm_configurations'];
        yield 'no unrestricted default' => [ReadinessReason::NoGenericDefault, 'nrllm_configurations'];
        yield 'inactive model' => [ReadinessReason::ModelInactive, 'nrllm_models'];
        yield 'model without vision' => [ReadinessReason::ModelLacksVision, 'nrllm_models'];
        yield 'model without provider' => [ReadinessReason::ProviderMissing, 'nrllm_models'];
        yield 'inactive provider' => [ReadinessReason::ProviderInactive, 'nrllm_providers'];
        yield 'provider without key' => [ReadinessReason::ProviderKeyMissing, 'nrllm_providers'];
        yield 'unknown adapter' => [ReadinessReason::AdapterUnknown, 'nrllm_providers'];
        yield 'switched off' => [ReadinessReason::SwitchedOff, 'tools_toolssettings'];
        yield 'no suggestion fields' => [ReadinessReason::NoSuggestionFields, 'tools_toolssettings'];
        yield 'no OpenAI key' => [ReadinessReason::ImageKeyMissing, 'tools_toolssettings'];
        yield 'ready' => [ReadinessReason::ConfigurationReady, null];
        yield 'model per request' => [ReadinessReason::ModelChosenPerRequest, null];
        yield 'translator' => [ReadinessReason::TranslatorAvailable, null];
        yield 'no upload folder' => [ReadinessReason::NoUploadFolder, null];
        yield 'image ready' => [ReadinessReason::ImageReady, null];
        yield 'no bootstrap_package' => [ReadinessReason::BootstrapPackageMissing, null];
    }

    #[Test]
    #[DataProvider('reasons')]
    public function eachReasonLinksTheModuleThatFixesIt(ReadinessReason $reason, ?string $route): void
    {
        self::assertSame($route, $reason->fixRoute());
    }

    #[Test]
    public function everyReasonIsListed(): void
    {
        self::assertCount(count(ReadinessReason::cases()), iterator_to_array(self::reasons()));
    }

    /**
     * A fix link belongs to a row that is not ready or off; a ready row and
     * a row that could not be checked link nothing.
     */
    #[Test]
    public function onlyARowThatIsNotReadyOrOffLinksAFix(): void
    {
        $reason = ReadinessReason::ProviderKeyMissing;

        self::assertSame('nrllm_providers', (new ReadinessRow(ReadinessAction::Writing, ReadinessState::NotReady, $reason))->fixRoute());
        self::assertSame('nrllm_providers', (new ReadinessRow(ReadinessAction::Writing, ReadinessState::Off, $reason))->fixRoute());
        self::assertNull((new ReadinessRow(ReadinessAction::Writing, ReadinessState::Ready, $reason))->fixRoute());
        self::assertNull((new ReadinessRow(ReadinessAction::Writing, ReadinessState::NotChecked, $reason))->fixRoute());
    }
}
