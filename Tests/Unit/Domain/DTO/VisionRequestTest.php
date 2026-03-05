<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\T3Cowriter\Tests\Unit\Domain\DTO;

use Netresearch\T3Cowriter\Domain\DTO\VisionRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VisionRequest::class)]
final class VisionRequestTest extends TestCase
{
    #[Test]
    public function fromRequestBodyParsesImageUrl(): void
    {
        $body    = ['imageUrl' => 'https://example.com/image.jpg', 'prompt' => 'Generate alt text'];
        $request = VisionRequest::fromRequestBody($body);

        self::assertSame('https://example.com/image.jpg', $request->imageUrl);
        self::assertSame('Generate alt text', $request->prompt);
    }

    #[Test]
    public function fromRequestBodyUsesDefaultPrompt(): void
    {
        $body    = ['imageUrl' => 'https://example.com/image.jpg'];
        $request = VisionRequest::fromRequestBody($body);

        self::assertSame('Generate a concise, descriptive alt text for this image.', $request->prompt);
    }

    #[Test]
    public function fromRequestBodyTrimsWhitespace(): void
    {
        $body    = ['imageUrl' => '  https://example.com/image.jpg  ', 'prompt' => '  test  '];
        $request = VisionRequest::fromRequestBody($body);

        self::assertSame('https://example.com/image.jpg', $request->imageUrl);
        self::assertSame('test', $request->prompt);
    }

    #[Test]
    public function fromRequestBodyHandlesMissingImageUrl(): void
    {
        $request = VisionRequest::fromRequestBody([]);

        self::assertSame('', $request->imageUrl);
    }

    #[Test]
    public function fromRequestBodyHandlesNonScalarImageUrl(): void
    {
        $body    = ['imageUrl' => ['nested', 'array'], 'prompt' => 'Generate alt text'];
        $request = VisionRequest::fromRequestBody($body);

        // Non-scalar values fall back to default empty string
        self::assertSame('', $request->imageUrl);
        self::assertSame('Generate alt text', $request->prompt);
    }

    #[Test]
    public function fromRequestBodyHandlesNonScalarPrompt(): void
    {
        $body    = ['imageUrl' => 'https://example.com/image.jpg', 'prompt' => ['array', 'value']];
        $request = VisionRequest::fromRequestBody($body);

        // Non-scalar prompt falls back to default prompt
        self::assertSame('https://example.com/image.jpg', $request->imageUrl);
        self::assertSame('Generate a concise, descriptive alt text for this image.', $request->prompt);
    }

    #[Test]
    public function isValidReturnsTrueForNormalInput(): void
    {
        $request = new VisionRequest(imageUrl: 'https://example.com/img.jpg', prompt: 'Describe this image');
        self::assertTrue($request->isValid());
    }

    #[Test]
    public function isValidReturnsTrueAtExactMaxImageUrlLength(): void
    {
        $request = new VisionRequest(imageUrl: str_repeat('a', 32768));
        self::assertTrue($request->isValid());
    }

    #[Test]
    public function isValidReturnsFalseAtOneOverMaxImageUrlLength(): void
    {
        $request = new VisionRequest(imageUrl: str_repeat('a', 32769));
        self::assertFalse($request->isValid());
    }

    #[Test]
    public function isValidReturnsFalseForExcessiveImageUrlLength(): void
    {
        $request = new VisionRequest(imageUrl: str_repeat('a', 40000));
        self::assertFalse($request->isValid());
    }

    #[Test]
    public function isValidReturnsTrueAtExactMaxPromptLength(): void
    {
        $request = new VisionRequest(imageUrl: 'https://example.com/img.jpg', prompt: str_repeat('a', 32768));
        self::assertTrue($request->isValid());
    }

    #[Test]
    public function isValidReturnsFalseAtOneOverMaxPromptLength(): void
    {
        $request = new VisionRequest(imageUrl: 'https://example.com/img.jpg', prompt: str_repeat('a', 32769));
        self::assertFalse($request->isValid());
    }

    #[Test]
    public function isValidReturnsFalseForExcessivePromptLength(): void
    {
        $request = new VisionRequest(imageUrl: 'https://example.com/img.jpg', prompt: str_repeat('a', 40000));
        self::assertFalse($request->isValid());
    }
}
