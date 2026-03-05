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
}
