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
    public function fromRequestBodyParsesConfiguration(): void
    {
        $body    = ['imageUrl' => 'https://example.com/image.jpg', 'configuration' => 'openai-gpt4'];
        $request = VisionRequest::fromRequestBody($body);

        self::assertSame('openai-gpt4', $request->configuration);
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
        self::assertNull($request->configuration);
    }
}
