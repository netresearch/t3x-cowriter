<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Domain\DTO;

use Netresearch\T3Cowriter\Domain\DTO\TranslationRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(TranslationRequest::class)]
final class TranslationRequestTest extends TestCase
{
    #[Test]
    public function fromRequestBodyParsesAllFields(): void
    {
        $body = [
            'text'           => 'Hello world',
            'targetLanguage' => 'de',
            'formality'      => 'formal',
            'domain'         => 'technical',
        ];
        $request = TranslationRequest::fromRequestBody($body);

        self::assertSame('Hello world', $request->text);
        self::assertSame('de', $request->targetLanguage);
        self::assertSame('formal', $request->formality);
        self::assertSame('technical', $request->domain);
    }

    #[Test]
    public function fromRequestBodyUsesDefaults(): void
    {
        $body    = ['text' => 'Hello', 'targetLanguage' => 'fr'];
        $request = TranslationRequest::fromRequestBody($body);

        self::assertSame('default', $request->formality);
        self::assertSame('general', $request->domain);
        self::assertNull($request->configuration);
    }

    #[Test]
    public function fromRequestBodyHandlesMissingFields(): void
    {
        $request = TranslationRequest::fromRequestBody([]);

        self::assertSame('', $request->text);
        self::assertSame('', $request->targetLanguage);
    }

    #[Test]
    public function fromRequestBodyTrimsWhitespace(): void
    {
        $body    = ['text' => '  Hello  ', 'targetLanguage' => ' de '];
        $request = TranslationRequest::fromRequestBody($body);

        self::assertSame('Hello', $request->text);
        self::assertSame('de', $request->targetLanguage);
    }

    #[Test]
    public function fromRequestBodyParsesConfiguration(): void
    {
        $body    = ['text' => 'Hello', 'targetLanguage' => 'de', 'configuration' => 'claude-fast'];
        $request = TranslationRequest::fromRequestBody($body);

        self::assertSame('claude-fast', $request->configuration);
    }

    #[Test]
    public function fromRequestBodyHandlesNonScalarText(): void
    {
        $body    = ['text' => ['nested', 'array'], 'targetLanguage' => 'de'];
        $request = TranslationRequest::fromRequestBody($body);

        // Non-scalar text falls back to default empty string
        self::assertSame('', $request->text);
        self::assertSame('de', $request->targetLanguage);
    }

    #[Test]
    public function fromRequestBodyHandlesNonScalarTargetLanguage(): void
    {
        $body    = ['text' => 'Hello', 'targetLanguage' => new stdClass()];
        $request = TranslationRequest::fromRequestBody($body);

        // Non-scalar targetLanguage falls back to default empty string
        self::assertSame('Hello', $request->text);
        self::assertSame('', $request->targetLanguage);
    }

    #[Test]
    public function isValidReturnsTrueForNormalInput(): void
    {
        $request = new TranslationRequest(text: 'Hello world', targetLanguage: 'de');
        self::assertTrue($request->isValid());
    }

    #[Test]
    public function isValidReturnsTrueAtExactMaxTextLength(): void
    {
        $request = new TranslationRequest(text: str_repeat('a', 32768), targetLanguage: 'de');
        self::assertTrue($request->isValid());
    }

    #[Test]
    public function isValidReturnsFalseAtOneOverMaxTextLength(): void
    {
        $request = new TranslationRequest(text: str_repeat('a', 32769), targetLanguage: 'de');
        self::assertFalse($request->isValid());
    }

    #[Test]
    public function isValidReturnsFalseForExcessiveTextLength(): void
    {
        $request = new TranslationRequest(text: str_repeat('a', 40000), targetLanguage: 'de');
        self::assertFalse($request->isValid());
    }

    #[Test]
    public function isValidReturnsTrueAtExactMaxTargetLanguageLength(): void
    {
        $request = new TranslationRequest(text: 'Hello', targetLanguage: str_repeat('a', 10));
        self::assertTrue($request->isValid());
    }

    #[Test]
    public function isValidReturnsFalseForExcessiveTargetLanguageLength(): void
    {
        $request = new TranslationRequest(text: 'Hello', targetLanguage: str_repeat('a', 11));
        self::assertFalse($request->isValid());
    }

    #[Test]
    public function isValidReturnsTrueAtExactMaxFormalityLength(): void
    {
        $request = new TranslationRequest(
            text: 'Hello',
            targetLanguage: 'de',
            formality: str_repeat('a', 50),
        );
        self::assertTrue($request->isValid());
    }

    #[Test]
    public function isValidReturnsFalseForExcessiveFormalityLength(): void
    {
        $request = new TranslationRequest(
            text: 'Hello',
            targetLanguage: 'de',
            formality: str_repeat('a', 51),
        );
        self::assertFalse($request->isValid());
    }

    #[Test]
    public function isValidReturnsTrueAtExactMaxDomainLength(): void
    {
        $request = new TranslationRequest(
            text: 'Hello',
            targetLanguage: 'de',
            domain: str_repeat('a', 100),
        );
        self::assertTrue($request->isValid());
    }

    #[Test]
    public function isValidReturnsFalseForExcessiveDomainLength(): void
    {
        $request = new TranslationRequest(
            text: 'Hello',
            targetLanguage: 'de',
            domain: str_repeat('a', 101),
        );
        self::assertFalse($request->isValid());
    }

    // =========================================================================
    // Multi-byte string length checks (kills MBString mutants)
    // =========================================================================

    #[Test]
    public function isValidUsesMultiByteForTextLength(): void
    {
        // Kills MBString mutant: mb_strlen → strlen for text
        // 32768 emojis = 32768 chars (within limit) but 131072 bytes (over limit with strlen)
        $emoji   = "\xF0\x9F\x92\xA1"; // U+1F4A1 (lightbulb), 4 bytes
        $request = new TranslationRequest(text: str_repeat($emoji, 32768), targetLanguage: 'de');
        self::assertTrue($request->isValid());
    }

    #[Test]
    public function isValidUsesMultiByteForTargetLanguageLength(): void
    {
        // Kills MBString mutant: mb_strlen → strlen for targetLanguage
        // Multi-byte chars that are 2 bytes each: 10 chars = 10 mb_strlen, 20 bytes = 20 strlen
        $twoByteChar = "\xC3\xA4"; // ä, 2 bytes
        $request     = new TranslationRequest(text: 'Hello', targetLanguage: str_repeat($twoByteChar, 10));
        self::assertTrue($request->isValid());
    }

    #[Test]
    public function isValidUsesMultiByteForFormalityLength(): void
    {
        // Kills MBString mutant: mb_strlen → strlen for formality
        $twoByteChar = "\xC3\xA4";
        $request     = new TranslationRequest(text: 'Hello', targetLanguage: 'de', formality: str_repeat($twoByteChar, 50));
        self::assertTrue($request->isValid());
    }

    #[Test]
    public function isValidUsesMultiByteForDomainLength(): void
    {
        // Kills MBString mutant: mb_strlen → strlen for domain
        $twoByteChar = "\xC3\xA4";
        $request     = new TranslationRequest(text: 'Hello', targetLanguage: 'de', domain: str_repeat($twoByteChar, 100));
        self::assertTrue($request->isValid());
    }

    #[Test]
    public function isValidUsesMultiByteForConfigurationLength(): void
    {
        // Kills MBString mutant: mb_strlen → strlen for configuration
        $twoByteChar = "\xC3\xA4";
        $request     = new TranslationRequest(text: 'Hello', targetLanguage: 'de', configuration: str_repeat($twoByteChar, 255));
        self::assertTrue($request->isValid());
    }

    // =========================================================================
    // Configuration boundary tests (kills LessThanOrEqualTo, LessThanOrEqualToNegotiation)
    // =========================================================================

    #[Test]
    public function isValidReturnsTrueAtExactMaxConfigurationLength(): void
    {
        // Kills LessThanOrEqualTo mutant: <= 255 → < 255
        $request = new TranslationRequest(text: 'Hello', targetLanguage: 'de', configuration: str_repeat('a', 255));
        self::assertTrue($request->isValid());
    }

    #[Test]
    public function isValidReturnsFalseAtOneOverMaxConfigurationLength(): void
    {
        // Kills LessThanOrEqualToNegotiation mutant: <= 255 → > 255
        $request = new TranslationRequest(text: 'Hello', targetLanguage: 'de', configuration: str_repeat('a', 256));
        self::assertFalse($request->isValid());
    }

    // =========================================================================
    // Trim tests for formality/domain (kills UnwrapTrim mutants)
    // =========================================================================

    #[Test]
    public function fromRequestBodyTrimsFormalityWhitespace(): void
    {
        // Kills UnwrapTrim mutant on formality
        $body    = ['text' => 'Hello', 'targetLanguage' => 'de', 'formality' => '  formal  '];
        $request = TranslationRequest::fromRequestBody($body);

        self::assertSame('formal', $request->formality);
    }

    #[Test]
    public function fromRequestBodyTrimsDomainWhitespace(): void
    {
        // Kills UnwrapTrim mutant on domain
        $body    = ['text' => 'Hello', 'targetLanguage' => 'de', 'domain' => '  technical  '];
        $request = TranslationRequest::fromRequestBody($body);

        self::assertSame('technical', $request->domain);
    }

    // =========================================================================
    // CastString / extractNullableString (kills CastString, ReturnRemoval, UnwrapTrim)
    // =========================================================================

    #[Test]
    public function extractStringCastsNumericToString(): void
    {
        // Kills CastString mutant: (string) $value → $value
        $body    = ['text' => 42, 'targetLanguage' => 99];
        $request = TranslationRequest::fromRequestBody($body);

        self::assertIsString($request->text);
        self::assertSame('42', $request->text);
        self::assertIsString($request->targetLanguage);
        self::assertSame('99', $request->targetLanguage);
    }

    #[Test]
    public function extractNullableStringReturnsNullForEmptyValue(): void
    {
        // Kills ReturnRemoval mutant on extractNullableString null return
        $body    = ['text' => 'Hello', 'targetLanguage' => 'de', 'configuration' => ''];
        $request = TranslationRequest::fromRequestBody($body);

        self::assertNull($request->configuration);
    }

    #[Test]
    public function extractNullableStringTrimsValue(): void
    {
        // Kills UnwrapTrim mutant on extractNullableString
        $body    = ['text' => 'Hello', 'targetLanguage' => 'de', 'configuration' => '  my-config  '];
        $request = TranslationRequest::fromRequestBody($body);

        self::assertSame('my-config', $request->configuration);
    }

    #[Test]
    public function extractNullableStringReturnsNullForNonScalar(): void
    {
        $body    = ['text' => 'Hello', 'targetLanguage' => 'de', 'configuration' => ['nested']];
        $request = TranslationRequest::fromRequestBody($body);

        self::assertNull($request->configuration);
    }
}
