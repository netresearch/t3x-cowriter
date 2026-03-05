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
}
