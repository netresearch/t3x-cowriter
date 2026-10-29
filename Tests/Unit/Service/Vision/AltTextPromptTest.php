<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Service\Vision;

use Netresearch\T3Cowriter\Domain\DTO\VisionRequest;
use Netresearch\T3Cowriter\Service\Vision\AltTextPrompt;
use Netresearch\T3Cowriter\Service\Vision\RecordLanguageResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(AltTextPrompt::class)]
final class AltTextPromptTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed}>
     */
    public static function settingsWithoutAnInstruction(): iterable
    {
        yield 'not set' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => ["  \n "];
        yield 'not a string' => [['nested' => 'value']];
    }

    #[Test]
    #[DataProvider('settingsWithoutAnInstruction')]
    public function withoutAnInstructionInTheSettingTheBuiltInOneApplies(mixed $setting): void
    {
        self::assertSame(
            AltTextPrompt::DEFAULT_INSTRUCTION,
            $this->prompt($setting)->forRequest(new VisionRequest('https://example.com/a.png')),
        );
    }

    #[Test]
    public function theBuiltInInstructionAppliesWhenTheSettingCannotBeRead(): void
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new RuntimeException('not configured'));

        $prompt = new AltTextPrompt($extensionConfiguration, $this->createStub(RecordLanguageResolverInterface::class));

        self::assertSame(AltTextPrompt::DEFAULT_INSTRUCTION, $prompt->forRequest(new VisionRequest('https://example.com/a.png')));
    }

    #[Test]
    public function theSettingIsReadFromThisExtension(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->expects(self::once())->method('get')
            ->with('t3_cowriter', 'altText/prompt')
            ->willReturn('  Describe it for a museum catalogue.  ');

        $prompt = new AltTextPrompt($extensionConfiguration, $this->createStub(RecordLanguageResolverInterface::class));

        self::assertSame('Describe it for a museum catalogue.', $prompt->forRequest(new VisionRequest('https://example.com/a.png')));
    }

    #[Test]
    public function aPromptTheRequestSendsReplacesTheSetting(): void
    {
        self::assertSame(
            'Describe the colours.',
            $this->prompt('Describe it for a museum catalogue.')->forRequest(new VisionRequest('https://example.com/a.png', 'Describe the colours.')),
        );
    }

    #[Test]
    public function theRecordsLanguageIsAppendedToTheInstruction(): void
    {
        $languageResolver = $this->createMock(RecordLanguageResolverInterface::class);
        $languageResolver->expects(self::once())->method('languageName')
            ->with('tt_content', 11)
            ->willReturn('German (Germany)');

        $prompt = new AltTextPrompt($this->extensionConfiguration('Describe it.'), $languageResolver);

        self::assertSame(
            "Describe it.\n\nAnswer in German (Germany).",
            $prompt->forRequest(new VisionRequest('https://example.com/a.png', '', ['table' => 'tt_content', 'uid' => 11])),
        );
    }

    #[Test]
    public function theRecordsLanguageIsAppendedToAPromptTheRequestSends(): void
    {
        $languageResolver = $this->createStub(RecordLanguageResolverInterface::class);
        $languageResolver->method('languageName')->willReturn('German (Germany)');

        $prompt = new AltTextPrompt($this->extensionConfiguration(null), $languageResolver);

        self::assertSame(
            "Describe the colours.\n\nAnswer in German (Germany).",
            $prompt->forRequest(new VisionRequest('https://example.com/a.png', 'Describe the colours.', ['table' => 'tt_content', 'uid' => 11])),
        );
    }

    #[Test]
    public function noLanguageHintWhenTheLanguageCannotBeResolved(): void
    {
        $languageResolver = $this->createStub(RecordLanguageResolverInterface::class);
        $languageResolver->method('languageName')->willReturn(null);

        $prompt = new AltTextPrompt($this->extensionConfiguration(null), $languageResolver);

        self::assertSame(
            AltTextPrompt::DEFAULT_INSTRUCTION,
            $prompt->forRequest(new VisionRequest('https://example.com/a.png', '', ['table' => 'tt_content', 'uid' => 11])),
        );
    }

    #[Test]
    public function aFailingLanguageLookupCostsTheHintNotTheRequest(): void
    {
        $languageResolver = $this->createStub(RecordLanguageResolverInterface::class);
        $languageResolver->method('languageName')->willThrowException(new RuntimeException('database gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')
            ->with(self::anything(), self::callback(static fn (array $context): bool => $context['exception'] === 'database gone'));

        $prompt = new AltTextPrompt($this->extensionConfiguration(null), $languageResolver, $logger);

        self::assertSame(
            AltTextPrompt::DEFAULT_INSTRUCTION,
            $prompt->forRequest(new VisionRequest('https://example.com/a.png', '', ['table' => 'tt_content', 'uid' => 11])),
        );
    }

    #[Test]
    public function withoutARecordTheLanguageIsNotLookedUp(): void
    {
        $languageResolver = $this->createMock(RecordLanguageResolverInterface::class);
        $languageResolver->expects(self::never())->method('languageName');

        $prompt = new AltTextPrompt($this->extensionConfiguration(null), $languageResolver);

        self::assertSame(AltTextPrompt::DEFAULT_INSTRUCTION, $prompt->forRequest(new VisionRequest('https://example.com/a.png')));
    }

    private function prompt(mixed $setting): AltTextPrompt
    {
        return new AltTextPrompt($this->extensionConfiguration($setting), $this->createStub(RecordLanguageResolverInterface::class));
    }

    private function extensionConfiguration(mixed $setting): ExtensionConfiguration
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($setting);

        return $extensionConfiguration;
    }
}
